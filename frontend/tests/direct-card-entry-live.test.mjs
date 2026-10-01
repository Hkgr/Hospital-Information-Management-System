import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3198';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
let browser, fixture;
function control(mode) {
  const result = spawnSync('php', ['tests/Support/unified-registration-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}
before(async () => { control('prepare'); fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/unified-registration-live.json', import.meta.url))); browser = await chromium.launch({ channel: 'chrome' }); mkdirSync('test-results/direct-card-entry', { recursive: true }); });
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body) {
  const response = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  assert.equal(response.headers.get('x-test-laravel'), 'dossiers');
  return { status: response.status, body: response.status === 204 ? null : await response.json() };
}
async function open(kind, path, width = 1440) {
  const login = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(login.status, 200);
  const token = login.body.data.token;
  const context = await browser.newContext({ viewport: { width, height: 960 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => { if (!sessionStorage.getItem('direct-card-fixture')) { sessionStorage.setItem('hospital.bearer', token); sessionStorage.setItem('direct-card-fixture', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000); await page.goto(`${base}${path}`);
  return { context, page, token };
}

for (const width of [390, 768, 1440]) test(`new patient card immediately shows its fields without a preceding search at ${width}px`, async () => {
  const f = await open('clerk', `/patient-cards/new?facility_id=${fixture.facilities[0]}`, width);
  try {
    await f.page.waitForLoadState('networkidle');
    assert.equal(await f.page.getByLabel('الاسم الأول', { exact: true }).count(), 1, 'The card form must be open before any search');
    assert.equal(await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).inputValue(), '');
    assert.equal(await f.page.getByRole('link', { name: 'الاستقبال', exact: true }).count(), 0);
    assert.equal(await f.page.locator('a[href*="/reception-admin"]').count(), 0);
    assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await f.page.screenshot({ path: `test-results/direct-card-entry/new-${width}.png`, fullPage: true });
    const invalid = f.page.waitForResponse(r => r.url().endsWith('/patient-cards/registrations') && r.status() === 422);
    await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    await invalid;
    await f.page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'first_name');
    await f.page.getByLabel('الاسم الأول', { exact: true }).fill('بطاقة مباشرة');
    await f.page.getByLabel('اسم العائلة', { exact: true }).fill(`${fixture.tag}-${width}`);
    await f.page.getByLabel('تاريخ فتح البطاقة', { exact: true }).fill('2020-03-04');
    await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).fill('2020-03-05');
    const saved = f.page.waitForResponse(r => r.url().endsWith('/patient-cards/registrations') && r.status() === 201);
    await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    const response = await saved, card = (await response.json()).data;
    const replay = await api(f.token, 'patient-cards/registrations', 'POST', response.request().postDataJSON());
    assert.equal(replay.status, 201);
    assert.equal(replay.body.data.id, card.id);
    assert.equal(replay.body.data.registration_visit_id, card.registration_visit_id);
    assert.equal(card.workflow, null);
    await f.page.goto(`${base}/patient-cards/${card.id}?facility_id=${fixture.facilities[0]}`);
    await f.page.getByRole('region', { name: 'البيانات الأساسية للبطاقة' }).waitFor();
    await f.page.screenshot({ path: `test-results/direct-card-entry/saved-${width}.png`, fullPage: true });
    for (const [method, path] of [['GET', `dossiers/${card.id}`], ['POST', `dossiers/${card.id}/report/xlsx`], ['DELETE', `dossiers/${card.id}`]]) {
      assert.equal((await api(f.token, `${path}?facility_id=${fixture.facilities[0]}`, method)).status, 403);
    }
    control('verify');
  } finally { await f.context.close(); }
});

test('manager saves directly from the same card form and opens the same medical draft', async () => {
  const f = await open('manager', `/patient-cards/new?facility_id=${fixture.facilities[0]}`);
  try {
    await f.page.getByLabel('الاسم الأول', { exact: true }).fill('مدير تدريب');
    await f.page.getByLabel('اسم العائلة', { exact: true }).fill(fixture.tag);
    await f.page.getByLabel('تاريخ فتح البطاقة', { exact: true }).fill('2020-03-04');
    await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).fill('2020-03-04');
    const saved = f.page.waitForResponse(r => r.url().endsWith('/patient-cards/registrations') && r.status() === 201);
    await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    const card = (await (await saved).json()).data;
    await f.page.getByRole('link', { name: 'استكمال المسودة المحفوظة', exact: true }).click();
    await f.page.waitForURL(new RegExp(`card=${card.id}`));
    const progress = await api(f.token, `dossiers/${card.id}/progress?facility_id=${fixture.facilities[0]}`);
    assert.equal(progress.status, 200);
    assert.equal(progress.body.data.visit.id, card.registration_visit_id);
    assert.equal(progress.body.data.patient.id, card.patient_id);
  } finally { await f.context.close(); }
});
