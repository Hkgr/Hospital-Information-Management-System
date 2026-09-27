import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194';
let browser, fixture;
function setup(mode) {
  const result = spawnSync('php', ['tests/Support/reception-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}
before(async () => {
  setup('prepare');
  fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/reception-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
});
after(async () => { await browser?.close(); if (fixture) setup('cleanup'); });

test('real reception registers a first visit, preserves invalid draft and refuses medical reads', async () => {
  const context = await browser.newContext();
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), fixture.token);
  const page = await context.newPage();
  page.setDefaultTimeout(20000);
  try {
    await page.goto(`${base}/reception?facility_id=${fixture.facility}`);
    await page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
    await page.getByLabel('الاسم الأول', { exact: true }).fill('اختبار استقبال');
    const failed = page.waitForResponse(r => r.url().includes('/reception/registrations') && r.status() === 422);
    await page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    await failed;
    assert.equal(await page.getByLabel('الاسم الأول', { exact: true }).inputValue(), 'اختبار استقبال');
    await page.getByLabel('اسم العائلة', { exact: true }).fill('وظيفي');
    const saved = page.waitForResponse(r => r.url().includes('/reception/registrations') && r.status() === 201);
    await page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    const response = await saved;
    assert.equal(response.headers()['x-test-laravel'], 'dossiers');
    const { data: card } = await response.json();
    assert.ok(card.registration_visit_id);
    assert.match(card.code, /^PC-\d+$/);
    await page.getByRole('region', { name: 'ملخص الاستقبال' }).waitFor();
    await page.getByRole('searchbox').fill(card.code);
    await page.getByRole('button', { name: 'فتح ملخص الاستقبال', exact: true }).click();
    await page.getByRole('region', { name: 'ملخص الاستقبال' }).waitFor();
    for (const [method, path] of [['GET', `dossiers/${card.id}`], ['GET', `dossiers/${card.id}/visits/${card.registration_visit_id}`], ['POST', `dossiers/${card.id}/report/xlsx`], ['DELETE', `dossiers/${card.id}`]]) {
      const denied = await fetch(`${base}/hospital-api/${path}?facility_id=${fixture.facility}`, { method, headers: { Authorization: `Bearer ${fixture.token}`, Accept: 'application/json' } });
      assert.equal(denied.status, 403);
    }
    const guest = await fetch(`${base}/hospital-api/reception/options?facility_id=${fixture.facility}`, { headers: { Accept: 'application/json' } });
    assert.equal(guest.status, 401);
  } finally { await context.close(); }
});
