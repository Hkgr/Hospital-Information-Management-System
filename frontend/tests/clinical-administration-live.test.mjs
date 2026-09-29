import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3196';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
let f, browser;
function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/clinical-administration-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => { fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/clinical-administration-live.json', import.meta.url))); browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' }); });
after(async () => { await browser?.close(); fixture('cleanup'); });
async function api(method, path, data = {}) {
  const r = await fetch(`${base}/hospital-api/dossiers${path}${method === 'GET' ? '?' + new URLSearchParams({ facility_id: f.facility, ...data }) : ''}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${f.token}` }, ...(method === 'GET' ? {} : { body: JSON.stringify({ facility_id: f.facility, request_id: crypto.randomUUID(), ...data }) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers');
  const body = await r.json(); assert.ok(r.ok, JSON.stringify(body)); return body.data;
}
async function choose(page, label, search) {
  await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
  const group = page.getByRole('group', { name: label, exact: true });
  await group.getByRole('searchbox').fill(search);
  await group.getByRole('button').filter({ hasText: search }).first().click();
}
test('real browser chooses eligible clinic doctor, saves manual doctor, reopens and completes with preserved identity', async () => {
  for (const width of [390, 1440]) {
    let d = await api('POST', '', { person_mode: 'new', opening_date: '2001-01-01', visit_date: '2001-03-02', first_name: 'اختبار الإدارة', family_name: `${f.tag}-${width}`, birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown' });
    d = await api('PUT', `/${d.id}/medical`, { lock_version: d.lock_version, is_oncology: false });
    const path = `/${d.id}/visits/${d.visit.id}`;
    d = await api('PUT', path, { lock_version: d.visit.lock_version, visit_date: '2001-03-02', is_referred: false, unchanged: true, diagnoses: [] });
    const context = await browser.newContext({ viewport: { width, height: 950 } });
    await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
    const page = await context.newPage(); page.setDefaultTimeout(25000);
    try {
      await page.goto(`${base}/patient-cards/${d.id}/edit?section=3&facility_id=${f.facility}`);
      await page.getByRole('button', { name: 'إضافة الإجراء للزيارة', exact: true }).click();
      await choose(page, 'الإجراء من الدليل 1', f.procedure_name);
      await page.getByText('هذا الإجراء يُسجّل في عيادة مصنفة جراحية.', { exact: true }).waitFor();
      await choose(page, 'العيادة · الإجراء 1', f.clinic_name);
      await choose(page, 'الطبيب المسؤول · الإجراء 1', f.doctor_name);
      const save = page.waitForResponse(r => r.url().endsWith('/clinical') && r.request().method() === 'PUT');
      await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
      const saved = await save; assert.equal(saved.status(), 200); d = (await saved.json()).data;
      assert.equal(d.clinical.procedures[0].doctor_id, f.workflow_doctors[0]);
      await page.goto(`${base}/patient-cards/${d.id}/edit?section=3&facility_id=${f.facility}`);
      await page.getByRole('checkbox', { name: 'غير ذلك', exact: true }).check();
      await page.locator('[name="procedures.0.manual_doctor_name"]').fill('اسم طبيب يدوي اصطناعي');
      const manual = page.waitForResponse(r => r.url().endsWith('/clinical') && r.request().method() === 'PUT');
      await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
      assert.equal((await manual).status(), 200); d = (await (await manual).json()).data;
      assert.equal(d.clinical.procedures[0].doctor_id, null);
      d = await api('PUT', path, { lock_version: d.visit.lock_version, visit_date: '2001-02-01', is_referred: false, unchanged: true, diagnoses: [] });
      d = await api('PUT', path + '/clinical', { lock_version: d.visit.lock_version, services: [], procedures: d.clinical.procedures });
      d = await api('PUT', path + '/medications', { lock_version: d.visit.lock_version, prescription: null, outcome: { code: 'DOS-NORX', outcome_on: '2001-02-01', clinic_id: f.clinics[0], doctor_id: f.workflow_doctors[0] } });
      await page.goto(`${base}/patient-cards/${d.id}/edit?section=3&facility_id=${f.facility}`);
      assert.equal(await page.locator('[name="procedures.0.manual_doctor_name"]').inputValue(), 'اسم طبيب يدوي اصطناعي');
      await page.goto(`${base}/patient-cards/${d.id}/edit?section=5&facility_id=${f.facility}`);
      await page.getByRole('button', { name: 'الانتقال إلى المراجعة', exact: true }).click();
      await page.locator('[name="confirmed"]').check();
      await choose(page, 'العيادة · المسؤول عن إكمال الزيارة', f.clinic_name);
      await choose(page, 'الطبيب المسؤول · المسؤول عن إكمال الزيارة', f.doctor_name);
      await page.getByRole('button', { name: /^(إكمال هذه الزيارة|تفعيل بطاقة المريض وإكمال الزيارة)$/ }).click();
      const complete = page.waitForResponse(r => r.url().endsWith('/complete') && r.request().method() === 'POST');
      await page.getByRole('button', { name: 'أؤكد الإكمال', exact: true }).click();
      const completed = await complete; assert.equal(completed.status(), 200); d = (await completed.json()).data;
      assert.equal(d.visit.status, 'complete');
      await page.goto(`${base}/patient-cards/${d.id}?facility_id=${f.facility}`);
      await page.getByText('اسم طبيب يدوي اصطناعي', { exact: true }).first().waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    } finally { await context.close(); }
  }
});
