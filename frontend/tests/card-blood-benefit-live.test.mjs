import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3199';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname), 'Local isolated testing only');
const gallery = new URL('../.superdesign/card-blood-benefit/', import.meta.url);
let f, browser;
function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/card-blood-benefit-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 60000 });
  assert.equal(r.status, 0, r.stdout + r.stderr); process.stdout.write(r.stdout);
}
before(async () => {
  if (!process.env.BLOOD_CARD_REUSE_FIXTURE) fixture('prepare');
  f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/card-blood-benefit-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  await mkdir(gallery, { recursive: true });
});
after(async () => { await browser?.close(); fixture('cleanup'); });
async function api(method, path, data = {}, token = f.token) {
  const fields = { facility_id: f.facility, ...data };
  const r = await fetch(`${base}/hospital-api/${path}${method === 'GET' ? '?' + new URLSearchParams(fields) : ''}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, ...(method === 'GET' ? {} : { body: JSON.stringify(fields) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers'); assert.match(r.headers.get('cache-control'), /no-store/);
  return { status: r.status, body: await r.json() };
}
async function initial(width) {
  const r = await api('POST', 'dossiers', { request_id: crypto.randomUUID(), person_mode: 'new', opening_date: '2001-01-01', visit_date: '2001-03-02', first_name: 'مريض اصطناعي', family_name: `اختبار ربط الدم ${width}`, birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown' }, f.setup_token);
  assert.equal(r.status, 201, JSON.stringify(r.body));
  return r.body.data;
}
async function choose(page, label, search) {
  await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
  const group = page.getByRole('group', { name: label, exact: true });
  await group.getByRole('searchbox').fill(search);
  await group.getByRole('button', { name: new RegExp(search) }).first().click();
}
async function capture(page, width) {
  await page.evaluate(() => document.fonts.ready);
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
  const modal = page.getByRole('dialog');
  assert.equal(await modal.evaluate(e => getComputedStyle(e).direction), 'rtl');
  assert.match(await modal.evaluate(e => getComputedStyle(e).fontFamily), /Cairo/i);
  assert.equal(await modal.evaluate(e => e.scrollWidth > e.clientWidth + 1), false, 'Dialog has no horizontal clipping');
  await modal.getByLabel('ملخص الشخص المحدد').scrollIntoViewIfNeeded();
  const summaryOverflow = await modal.getByLabel('ملخص الشخص المحدد').evaluate(e => {
    const r = e.getBoundingClientRect();
    return [...e.children].map(c => c.getBoundingClientRect()).some(c => c.left < r.left - 1 || c.right > r.right + 1);
  });
  assert.equal(summaryOverflow, false, 'Patient summary fits within the dialog');
  await page.screenshot({ path: fileURLToPath(new URL(`benefit-${width}.png`, gallery)) });
  await modal.locator('[name="quantity"]').scrollIntoViewIfNeeded();
  await page.screenshot({ path: fileURLToPath(new URL(`benefit-fields-${width}.png`, gallery)) });
}

for (const width of [390, 768, 1440]) test(`real card → blood benefit flow at ${width}px, cancellation, replay and editing`, async () => {
  const d = await initial(width), path = `dossiers/${d.id}/visits/${d.visit.id}`;
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(30000);
  page.on('dialog', dialog => dialog.accept());
  const errors = []; page.on('pageerror', e => errors.push(e.message));
  const clinicalWrites = [];
  page.on('request', r => { if (r.url().endsWith('/clinical') && r.method() === 'PUT') clinicalWrites.push(r); });
  try {
    await page.goto(`${base}/patient-cards/new?card=${d.id}&section=3&facility_id=${f.facility}`);
    await page.getByRole('button', { name: 'إضافة الخدمة للزيارة', exact: true }).waitFor();
    assert.equal(await page.locator('a[href^="/blood-bank"]').count(), 0);
    await page.getByRole('button', { name: 'إضافة الخدمة للزيارة', exact: true }).click();
    await choose(page, 'الخدمة من الدليل 1', f.service_name);
    await choose(page, 'العيادة · الخدمة 1', 'عيادة التشخيص 1');
    await choose(page, 'الطبيب المسؤول · الخدمة 1', 'الطبيب المسؤول 1');
    await page.locator('[name="services.0.note"]').fill('مسودة خدمة أخرى محفوظة');
    await page.getByRole('button', { name: 'إضافة الخدمة للزيارة', exact: true }).click();
    await choose(page, 'الخدمة من الدليل 2', 'SER-4-001');
    let modal = page.getByRole('dialog', { name: 'تسجيل استفادة', exact: true });
    await modal.waitFor();
    assert.match(await modal.getByLabel('ملخص الشخص المحدد').innerText(), /مريض اصطناعي/);
    assert.equal(await modal.locator('[name="patient_id"]').count(), 0);
    await modal.locator('[name="quantity"]').fill('0.4500');
    await modal.getByRole('button', { name: 'إلغاء', exact: true }).click();
    assert.equal(await page.locator('[name="services.0.note"]').inputValue(), 'مسودة خدمة أخرى محفوظة');
    assert.equal((await api('GET', `${path}/progress`)).body.data.clinical.services.length, 0);
    await choose(page, 'الخدمة من الدليل 2', 'SER-4-001');
    modal = page.getByRole('dialog'); await modal.waitFor();
    assert.equal(await modal.locator('[name="quantity"]').inputValue(), '0.4500');
    await modal.locator('[name="benefit_kind"]').selectOption('transfusion');
    await modal.locator('[name="hemoglobin_g_dl"]').fill('9.1');
    await modal.locator('[name="crossmatch_result"]').selectOption('compatible');
    await modal.locator('[name="benefit_link_mode"]').selectOption('independent');
    await modal.locator('[name="blood_group"]').selectOption('A');
    await modal.locator('[name="rh"]').selectOption('positive');
    await modal.locator('[name="blood_component_id"]').selectOption(String(f.component));
    await modal.getByRole('group', { name: 'العيادة', exact: true }).getByRole('button', { name: /عيادة التشخيص 1/ }).click();
    await modal.getByRole('group', { name: 'الطبيب المسؤول', exact: true }).getByRole('button', { name: /الطبيب المسؤول 1/ }).click();
    await modal.locator('[name="quantity"]').fill('');
    await modal.getByRole('button', { name: 'اعتماد الاستفادة في المسودة', exact: true }).click();
    assert.equal(await modal.locator('[name="quantity"]').evaluate(e => e.matches(':invalid')), true);
    await modal.locator('[name="quantity"]').fill('0.4500');
    await capture(page, width);
    await modal.getByRole('button', { name: 'اعتماد الاستفادة في المسودة', exact: true }).click();
    await modal.waitFor({ state: 'hidden' });
    assert.equal((await api('GET', `${path}/progress`)).body.data.clinical.services.length, 0, 'Staging writes nothing');
    const response = page.waitForResponse(r => r.url().endsWith('/clinical') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).dblclick({ delay: 20 });
    const saved = await response; assert.equal(saved.status(), 200, await saved.text());
    assert.equal(clinicalWrites.length, 1, 'Double click sends one clinical write');
    const snapshot = (await saved.json()).data;
    assert.equal(snapshot.clinical.services.length, 2);
    const blood = snapshot.clinical.services[1], event = blood.blood_benefit;
    assert.ok(event?.id); assert.equal(blood.blood_bank_event_id, event.id);
    assert.equal(snapshot.clinical.services[0].note, 'مسودة خدمة أخرى محفوظة');
    const replay = await api('PUT', `${path}/clinical`, saved.request().postDataJSON());
    assert.equal(replay.status, 200); assert.equal(replay.body.data.clinical.services[1].blood_benefit.id, event.id);
    await page.goto(`${base}/patient-cards/new?card=${d.id}&section=3&facility_id=${f.facility}`);
    await page.getByRole('button', { name: 'تعديل الاستفادة المرتبطة', exact: true }).click();
    const edit = page.getByRole('dialog', { name: 'تعديل استفادة', exact: true }); await edit.waitFor();
    assert.equal(await edit.locator('[name="quantity"]').inputValue(), '0.4500');
    await edit.locator('[name="quantity"]').fill('0.6500');
    await edit.getByRole('button', { name: 'اعتماد الاستفادة في المسودة', exact: true }).click();
    const updatedResponse = page.waitForResponse(r => r.url().endsWith('/clinical') && r.request().method() === 'PUT');
    await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
    const updated = await updatedResponse; assert.equal(updated.status(), 200, await updated.text());
    const read = await api('GET', `${path}/progress`);
    assert.equal(read.body.data.clinical.services[1].blood_benefit.id, event.id);
    assert.equal(read.body.data.clinical.services[1].blood_benefit.quantity, '0.6500');
    assert.equal(read.body.data.clinical.services[0].note, 'مسودة خدمة أخرى محفوظة');
    assert.equal((await api('GET', 'blood-bank/options')).status, 403, 'No general blood-bank access granted');
    assert.equal((await api('GET', `${path}/blood-benefit-context`, {}, f.denied_token)).status, 403);
    assert.equal((await api('GET', `${path}/blood-benefit-context`, { facility_id: f.other })).status, 403);
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});
