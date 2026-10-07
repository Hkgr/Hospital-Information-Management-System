import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3198';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
let browser, f, card;
function control(mode) {
  const result = spawnSync('php', ['tests/Support/clinical-edit-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}
before(async () => { control('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/clinical-edit-live.json', import.meta.url))); browser = await chromium.launch({ channel: 'chrome' }); mkdirSync('test-results/clinical-edit', { recursive: true }); });
after(async () => { await browser?.close(); if (f) control('cleanup'); });
async function api(path, method = 'GET', body, token = f.token) {
  const r = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify({ facility_id: f.facility, request_id: crypto.randomUUID(), ...body }) } : {}) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers', path);
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function open(token, path, width) {
  const context = await browser.newContext({ viewport: { width, height: 960 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), token);
  const page = await context.newPage(); page.setDefaultTimeout(15000); page.on('dialog', dialog => dialog.accept());
  await page.goto(`${base}${path}`); return { page, context };
}
async function choose(page, label, text) {
  await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
  const group = page.getByRole('group', { name: label, exact: true });
  await group.getByRole('searchbox').fill(text);
  await group.getByRole('button', { name: new RegExp(text) }).first().click();
}
async function save(page, suffix) {
  const pending = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().split('?')[0].endsWith(suffix));
  await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
  const response = await pending; assert.equal(response.status(), 200, await response.text());
  return (await response.json()).data;
}

test('historical doctor appears after assignment-date edit; diagnosis draft survives dependent refresh', async () => {
  const created = await api('dossiers', 'POST', { person_mode: 'new', first_name: 'مريض', family_name: `تواريخ ${f.tag}`, opening_date: '2022-06-01', visit_date: '2022-06-01', gender: 'unknown', birth_date_accuracy: 'unknown', displacement_status: 'unknown' });
  assert.equal(created.status, 201, JSON.stringify(created.body)); card = created.body.data;
  const session = await open(f.token, `/patient-cards/new?facility_id=${f.facility}&card=${card.id}&visit=${card.visit.id}&section=2`, 390);
  try {
    const page = session.page;
    await page.getByRole('button', { name: 'إضافة تشخيص للزيارة', exact: true }).click();
    await choose(page, 'التشخيص من الدليل 1', f.diagnosis_name);
    await choose(page, 'العيادة للتشخيص 1', f.clinic_name);
    await page.getByRole('button', { name: 'اختيار: الطبيب المسؤول عن التشخيص 1', exact: true }).click();
    await page.getByText('لا يوجد طبيب مؤهل مرتبط بهذه العيادة في التاريخ المحدد. راجع ارتباطات العيادة أو صحح التاريخ.', { exact: true }).waitFor();
    await page.keyboard.press('Escape');
    const dates = await session.context.newPage(); dates.setDefaultTimeout(15000);
    await dates.goto(`${base}/doctors/${f.doctor}?facility_id=${f.facility}`);
    await dates.getByRole('button', { name: 'تاريخ الارتباطات في المنشأة', exact: true }).click();
    const row = dates.getByRole('row').filter({ hasText: f.clinic_name });
    await row.getByRole('button', { name: 'تعديل التواريخ', exact: true }).click();
    await dates.getByLabel('بداية الارتباط *', { exact: true }).fill('2022-01-01');
    await dates.getByLabel('سبب تصحيح التواريخ *', { exact: true }).fill('تغطية التسجيل التاريخي المعتمد');
    await dates.screenshot({ path: 'test-results/clinical-edit/assignment-390.png', fullPage: true });
    const response = dates.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith(`/assignments/${f.assignment}`));
    await dates.getByRole('button', { name: 'حفظ تواريخ الارتباط', exact: true }).click();
    assert.equal((await response).status(), 200);
    await dates.close(); await page.bringToFront();
    await choose(page, 'الطبيب المسؤول عن التشخيص 1', f.doctor_name);
    card = await save(page, `/visits/${card.visit.id}`);
    assert.equal(card.visit.diagnoses[0].diagnosing_staff_id, f.doctor);
    assert.equal(card.visit.diagnoses[0].diagnosis_id, f.diagnosis);
  } finally { await session.context.close(); }
});

for (const width of [390, 768, 1440]) test(`other authorized user edits saved diagnosis at ${width}px; retained doctor, IDs and revocation remain correct`, async () => {
  const current = await api(`dossiers/${card.id}/visits/${card.visit.id}/progress?facility_id=${f.facility}`);
  assert.equal(current.status, 200);
  const before = current.body.data.visit.diagnoses[0];
  const session = await open(f.reader_token, `/patient-cards/${card.id}?facility_id=${f.facility}&visit=${card.visit.id}`, width);
  try {
    const page = session.page;
    await page.getByRole('link', { name: 'تعديل التشخيصات', exact: true }).click();
    await page.getByText('هذه قيمة الطبيب المحفوظة. يمكن الاحتفاظ بها عند تصحيح السطر؛ اختيار طبيب آخر يتطلب أهلية فعلية في تاريخ الزيارة.', { exact: true }).waitFor();
    await page.getByLabel(/^تاريخ التشخيص/).fill(`2022-05-${width === 390 ? '01' : width === 768 ? '02' : '03'}`);
    await page.screenshot({ path: `test-results/clinical-edit/saved-diagnosis-${width}.png`, fullPage: true });
    const saved = await save(page, `/visits/${card.visit.id}`);
    assert.equal(saved.visit.diagnoses[0].id, before.id);
    assert.equal(saved.visit.diagnoses[0].diagnosing_staff_id, before.diagnosing_staff_id);
    assert.ok(saved.visit.diagnoses[0].lock_version > before.lock_version);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  } finally { await session.context.close(); }
});

test('session-dose edit uses the existing PUT contract and explicit conflict review', async () => {
  const appointment = await api(`dossiers/${card.id}/treatment-sessions`, 'POST', { planned_on: '2022-06-01', clinic_id: f.clinic, doctor_id: f.doctor });
  assert.equal(appointment.status, 201, JSON.stringify(appointment.body));
  const sessionId = appointment.body.data.id;
  const input = { given_on: '2022-06-01', dose_name: 'جرعة اصطناعية', complaint: 'شكاية قديمة', recommendations: 'توصيات قديمة', nurse_id: f.doctor, medication_source: 'ministry_of_health' };
  const dose = await api(`dossiers/${card.id}/treatment-sessions/${sessionId}/session-doses`, 'POST', input);
  assert.equal(dose.status, 201, JSON.stringify(dose.body));
  const doseId = dose.body.data.id;
  const session = await open(f.reader_token, `/patient-cards/new?facility_id=${f.facility}&card=${card.id}&visit=${card.visit.id}&section=7`, 768);
  try {
    const page = session.page;
    await page.getByRole('button', { name: 'تعديل الجرعة العلاجية', exact: true }).click();
    assert.equal(await page.getByLabel('اسم الجرعة', { exact: true }).inputValue(), input.dose_name);
    await page.getByRole('textbox', { name: /^شكاية المريض/ }).fill('تصحيح المسودة');
    const live = await api(`dossiers/${card.id}/treatment-sessions/${sessionId}?facility_id=${f.facility}`);
    const old = live.body.data.doses.find(d => d.id === doseId);
    const concurrent = await api(`dossiers/${card.id}/treatment-sessions/${sessionId}/session-doses/${doseId}`, 'PUT', { ...input, complaint: 'تصحيح متزامن', lock_version: old.lock_version });
    assert.equal(concurrent.status, 200);
    const conflict = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith(`/session-doses/${doseId}`));
    await page.getByRole('dialog').getByRole('button', { name: 'حفظ', exact: true }).click();
    assert.equal((await conflict).status(), 409);
    assert.equal(await page.getByRole('textbox', { name: /^شكاية المريض/ }).inputValue(), 'تصحيح المسودة');
    await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).click();
    await page.getByRole('checkbox', { name: /^شكاية المريض/ }).check();
    await page.getByRole('button', { name: 'اعتماد الاختيارات للمراجعة قبل الحفظ', exact: true }).click();
    const saved = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith(`/session-doses/${doseId}`));
    await page.getByRole('dialog').getByRole('button', { name: 'حفظ', exact: true }).click();
    assert.equal((await saved).status(), 200);
    await page.screenshot({ path: 'test-results/clinical-edit/session-dose-768.png', fullPage: true });
    const result = await api(`dossiers/${card.id}/treatment-sessions/${sessionId}?facility_id=${f.facility}`);
    assert.equal(result.body.data.doses.length, 1);
    assert.equal(result.body.data.doses[0].id, doseId);
    assert.equal(result.body.data.doses[0].complaint, 'تصحيح المسودة');
  } finally { await session.context.close(); }
});

test('revoked diagnosis update hides edit action and API denies the same token without changing clinical rows', async () => {
  control('revoke');
  const session = await open(f.reader_token, `/patient-cards/${card.id}?facility_id=${f.facility}&visit=${card.visit.id}`, 1440);
  try {
    await session.page.getByRole('heading', { name: 'سجل الزيارات', exact: true }).waitFor();
    assert.equal(await session.page.getByRole('link', { name: 'تعديل التشخيصات', exact: true }).count(), 0);
    const current = await api(`dossiers/${card.id}/visits/${card.visit.id}/progress?facility_id=${f.facility}`);
    const diagnoses = current.body.data.visit.diagnoses.map(d => ({ id: d.id, lock_version: d.lock_version, diagnosis_id: d.diagnosis_id, clinic_id: d.clinic_id, diagnosing_staff_id: d.diagnosing_staff_id, diagnosed_on: d.diagnosed_on }));
    const rejected = await api(`dossiers/${card.id}/visits/${card.visit.id}`, 'PUT', { lock_version: current.body.data.visit.lock_version, visit_date: '2022-06-01', is_referred: false, diagnoses }, f.reader_token);
    assert.equal(rejected.status, 403);
    const after = await api(`dossiers/${card.id}/visits/${card.visit.id}/progress?facility_id=${f.facility}`);
    assert.deepEqual(after.body.data.visit.diagnoses, current.body.data.visit.diagnoses);
  } finally { await session.context.close(); control('restore'); }
});
