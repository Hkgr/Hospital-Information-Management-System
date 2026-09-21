import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { readFileSync, mkdirSync, existsSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194';
const fixturePath = new URL('../../backend/storage/framework/testing/dossier-import-live.json', import.meta.url);
const artifacts = new URL('../.superdesign/tmp/imports/', import.meta.url);
let f, browser;
before(async () => {
  if (!existsSync(fixturePath)) {
    const result = spawnSync('php', ['tests/Support/dossier-import-live.php', 'prepare'], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8' });
    assert.equal(result.status, 0, result.stdout + result.stderr);
  }
  f = JSON.parse(readFileSync(fixturePath));
  browser = await chromium.launch(); mkdirSync(artifacts, { recursive: true });
});
after(async () => { await browser?.close(); });

for (const width of [390, 768, 1440]) test(`real upload, preview, explicit commit and interrupted resume at ${width}px`, async () => {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, acceptDownloads: true });
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
  const page = await context.newPage();
  const replies = [];
  page.on('response', response => {
    if (response.url().includes('/hospital-api/dossiers/imports') && ['POST', 'GET'].includes(response.request().method())) replies.push(response);
  });
  try {
    await page.goto(`${base}/patient-cards/imports?facility_id=${f.facility}`);
    await page.getByRole('heading', { name: 'استيراد بطاقات المرضى', exact: true }).waitFor();
    const template = page.waitForEvent('download');
    await page.getByRole('button', { name: 'تنزيل قالب Excel الحالي', exact: true }).click();
    assert.equal((await template).suggestedFilename(), 'patient-import-template.xlsx');
    await (await template).saveAs(fileURLToPath(new URL(`template-${width}.xlsx`, artifacts)));
    await page.getByRole('button', { name: 'رفع ومعاينة الملف', exact: true }).click();
    await page.getByRole('alert').filter({ hasText: 'اختر ملف' }).waitFor();
    assert.equal(await page.locator('input[type=file]').evaluate(el => el === document.activeElement), true);
    await page.screenshot({ path: fileURLToPath(new URL(`errors-${width}.png`, artifacts)), fullPage: true, animations: 'disabled' });
    await page.locator('input[type=file]').setInputFiles(f.samples[width]);
    const uploaded = page.waitForResponse(r => r.url().endsWith('/hospital-api/dossiers/imports') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'رفع ومعاينة الملف', exact: true }).click();
    const response = await uploaded; assert.equal(response.status(), 201); assert.equal(response.headers()['x-test-laravel'], 'dossiers');
    const id = (await response.json()).data.id;
    await page.waitForURL(new RegExp(`batch=${id}`));
    for (let n = 0; n < 10; n++) {
      const start = page.getByRole('button', { name: /^(فحص الدفعة|استكمال الفحص)$/ });
      await page.getByRole('heading', { name: `٣. معاينة الدفعة ${id}`, exact: true }).waitFor();
      const state = await page.request.get(`${base}/hospital-api/dossiers/imports/${id}?facility_id=${f.facility}`, { headers: { Authorization: `Bearer ${f.token}`, Accept: 'application/json' } });
      const b = (await state.json()).data;
      if (b.status === 'validated') break;
      assert.ok(['uploaded', 'validating'].includes(b.status), JSON.stringify(b));
      await start.waitFor();
      const validated = page.waitForResponse(r => r.url().includes(`/imports/${id}/validate`) && r.request().method() === 'POST');
      await start.click(); assert.equal((await validated).status(), 200);
      // Navigate away after one bounded step and resume from the durable batch URL.
      if (n === 0) { await page.goto(`${base}/patient-cards?facility_id=${f.facility}`); await page.goto(`${base}/patient-cards/imports?facility_id=${f.facility}&batch=${id}`); }
    }
    await page.getByRole('button', { name: 'اعتماد المجموعات الصالحة', exact: true }).waitFor();
    assert.equal(await page.getByRole('button', { name: 'اعتماد المجموعات الصالحة', exact: true }).isDisabled(), true);
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: fileURLToPath(new URL(`preview-${width}.png`, artifacts)), fullPage: true, animations: 'disabled' });
    await page.getByRole('checkbox').check();
    for (let n = 0; n < 10; n++) {
      const state = await page.request.get(`${base}/hospital-api/dossiers/imports/${id}?facility_id=${f.facility}`, { headers: { Authorization: `Bearer ${f.token}`, Accept: 'application/json' } });
      const b = (await state.json()).data;
      if (b.status.startsWith('completed')) { assert.equal(b.status, 'completed_with_errors'); assert.ok(b.counts.committed > 0); assert.ok(b.counts.needs_review > 0); break; }
      const button = page.getByRole('button', { name: /^(اعتماد المجموعات الصالحة|استكمال اعتماد الدفعة)$/ });
      await button.waitFor();
      const committed = page.waitForResponse(r => r.url().includes(`/imports/${id}/commit`) && r.request().method() === 'POST');
      await button.click(); assert.equal((await committed).status(), 200);
    }
    await page.getByRole('link', { name: 'فتح بطاقة المريض' }).first().waitFor();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: fileURLToPath(new URL(`complete-${width}.png`, artifacts)), fullPage: true, animations: 'disabled' });
    const download = page.waitForEvent('download');
    await page.getByRole('button', { name: 'تنزيل تقرير الأخطاء', exact: true }).click();
    await (await download).saveAs(fileURLToPath(new URL(`errors-${width}.xlsx`, artifacts)));
    await page.getByRole('link', { name: 'فتح بطاقة المريض' }).first().click();
    await page.waitForURL(/patient-cards\/\d+/);
    const history = page.locator('#dossier-change-history');
    await history.getByRole('rowheader', { name: 'دفعة الاستيراد', exact: true }).waitFor();
    for (const label of ['مراجع صفوف المصدر لهذه البطاقة', 'غرض الاستيراد', 'تاريخ الانتقال للنظام', 'رقم الملف الورقي', 'معرّف السياق التاريخي (غير الكود الحالي)']) {
      await history.getByRole('rowheader', { name: label, exact: true }).first().waitFor();
    }
    assert.ok((await history.innerText()).includes(`000-${f.tag}-live${width}`));
    assert.ok((await history.innerText()).includes(`LEG-${f.tag}-live${width}`));
    const filtered = page.waitForResponse(r => r.url().includes('/audit?') && r.url().includes('action=imported'));
    await history.getByRole('combobox', { name: 'نوع التغيير', exact: true }).selectOption('imported');
    const auditReply = await filtered;
    assert.equal(auditReply.status(), 200); assert.equal(auditReply.headers()['x-test-laravel'], 'dossiers');
    const audit = await auditReply.json();
    assert.equal(audit.meta.total, 1); assert.equal(audit.data[0].action_label, 'اعتماد استيراد');
    assert.deepEqual(audit.data[0].changes.map(c => c.field), ['import_batch_id', 'source_rows', 'purpose', 'cutover_date']);
    await history.getByRole('rowheader', { name: 'دفعة الاستيراد', exact: true }).waitFor();
    await page.goBack(); await page.getByRole('heading', { name: `٣. معاينة الدفعة ${id}`, exact: true }).waitFor();
    await page.locator('input[type=file]').setInputFiles(f.corrected[width]);
    const correctedReply = page.waitForResponse(r => r.url().endsWith('/hospital-api/dossiers/imports') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'رفع ومعاينة الملف', exact: true }).click();
    const correctedId = (await (await correctedReply).json()).data.id;
    assert.notEqual(correctedId, id);
    await page.waitForURL(new RegExp(`batch=${correctedId}`));
    for (const operation of ['validate', 'commit']) {
      for (let step = 0; step < 10; step++) {
        const result = await page.request.get(`${base}/hospital-api/dossiers/imports/${correctedId}?facility_id=${f.facility}`, { headers: { Authorization: `Bearer ${f.token}` } });
        const state = (await result.json()).data;
        if (operation === 'validate' && state.status === 'validated') break;
        if (operation === 'commit' && state.status === 'completed') {
          assert.ok(state.counts.skipped > 0); assert.ok(state.counts.committed > 0); break;
        }
        if (operation === 'commit') await page.getByRole('checkbox').check();
        const reply = page.waitForResponse(r => r.url().includes(`/imports/${correctedId}/${operation}`) && r.request().method() === 'POST');
        await page.getByRole('button', { name: operation === 'validate' ? /^(فحص الدفعة|استكمال الفحص)$/ : /^(اعتماد المجموعات الصالحة|استكمال اعتماد الدفعة)$/ }).click();
        assert.equal((await reply).status(), 200);
        assert.ok(step < 9, 'Corrected batch must finish in bounded steps');
      }
    }
    for (const reply of replies) { assert.equal(reply.headers()['x-test-laravel'], 'dossiers'); assert.match(reply.headers()['cache-control'], /no-store/); }
  } finally { await context.close(); }
});

test('unauthorized users and nonnumeric import routes stay denied through real Next', async () => {
  const context = await browser.newContext();
  const endpoint = `${base}/hospital-api/dossiers/imports?facility_id=${f.facility}`;
  try {
    assert.equal((await context.request.get(endpoint)).status(), 401);
    assert.equal((await context.request.get(endpoint, { headers: { Authorization: `Bearer ${f.denied_token}` } })).status(), 403);
    assert.equal((await context.request.get(`${base}/hospital-api/dossiers/imports/no-id?facility_id=${f.facility}`, { headers: { Authorization: `Bearer ${f.token}` } })).headers()['x-test-laravel'], undefined);
    await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.denied_token);
    const page = await context.newPage(); await page.goto(`${base}/patient-cards/imports?facility_id=${f.facility}`);
    await page.getByRole('heading', { name: 'الاستيراد غير متاح' }).waitFor();
    assert.equal(await page.locator('input[type=file]').count(), 0);
  } finally { await context.close(); }
});
