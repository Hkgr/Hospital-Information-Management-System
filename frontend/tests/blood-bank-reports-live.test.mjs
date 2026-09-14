// Real transport only: Next standalone -> Laravel -> guarded MySQL/MariaDB.
import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { readFileSync } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3105';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
const output = fileURLToPath(new URL('../.superdesign/blood-bank-reports/', import.meta.url));
const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
let f, browser, donor, recipient, donation;
function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/blood-bank-live.php', mode], { cwd: backend, env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 30000 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
async function request(path, method = 'GET', body, token = f.token) {
  const r = await fetch(`${base}/hospital-api/blood-bank${path}${path.includes('?') ? '&' : '?'}facility_id=${f.facility}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, body: body ? JSON.stringify({ facility_id: f.facility, ...body }) : undefined });
  assert.equal(r.headers.get('x-test-laravel'), 'blood-bank');
  assert.match(r.headers.get('cache-control'), /no-store/);
  return r;
}
async function json(path, method = 'GET', body) {
  const r = await request(path, method, body); const data = await r.json(); assert.ok(r.ok, JSON.stringify(data)); return data;
}
before(async () => {
  fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/blood-bank-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); await mkdir(output, { recursive: true });
  for (let i = 0; i < 32; i++) {
    const p = (await json('', 'POST', { ...f.profile, request_id: randomUUID(), kind: i % 2 ? 'recipient' : 'donor', first_name: `اصطناعي${i}`, family_name: 'اسم عربي طويل لمراجعة التقرير والالتفاف دون قص', phone: '0012345678', governorate_text: 'محافظة اصطناعية خارجية', city_text: 'مدينة اصطناعية', address_line: 'عنوان اصطناعي طويل '.repeat(10), screenings: [{ analyte: 'HCV', status: 'complete' }] })).data;
    if (i === 0) donor = p; if (i === 1) recipient = p;
  }
  donation = (await json(`/donor/${donor.id}/donations`, 'POST', { request_id: randomUUID(), donated_on: f.today, blood_group: 'AB', rh: 'positive', units: '1.2500' })).data;
});
after(async () => { await browser?.close(); if (f) { try { fixture('verify'); } finally { fixture('cleanup'); } } });

async function pageAt(path, width = 1440, token = f.token) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), token);
  const page = await context.newPage(); page.setDefaultTimeout(20000); await page.goto(base + path); return { page, context };
}

test('all report routes through real Next return complete PDF and typed XLSX; scoped permission rejects export', async () => {
  const paths = { list: '/export/', donor: `/donor/${donor.id}/report/`, recipient: `/recipient/${recipient.id}/report/`, donation: `/donor/${donor.id}/donations/${donation.id}/report/` };
  for (const [name, path] of Object.entries(paths)) for (const format of ['pdf', 'xlsx']) {
    const r = await request(`${path}${format}?per_page=10&page=2`); assert.equal(r.status, 200);
    assert.match(r.headers.get('content-disposition'), new RegExp(`BB-.*\\.${format}`));
    const bytes = Buffer.from(await r.arrayBuffer()); assert.equal(bytes.subarray(0, format === 'pdf' ? 5 : 2).toString(), format === 'pdf' ? '%PDF-' : 'PK');
    await writeFile(`${output}/${name}.${format}`, bytes);
    const denied = await request(`${path}${format}`, 'GET', undefined, f.viewer_token); assert.equal(denied.status, 403); assert.equal((await denied.json()).error.code, 'BLOOD_BANK_ACCESS_DENIED');
  }
  assert.equal((await json('?per_page=10&page=2')).meta.total, 32);
});

test('exports track delayed actual list requests, pending search, failed filters and retry; previous rows stay visible', async () => {
  const { page, context } = await pageAt('/blood-bank');
  try {
    const excel = page.getByRole('button', { name: 'Excel', exact: true }); await excel.waitFor();
    await page.waitForFunction(() => ![...document.querySelectorAll('button')].find(b => b.textContent === 'Excel')?.disabled);
    // Delay delivery of REAL Laravel fetch responses. No page.route or fabricated API data.
    await page.evaluate(() => {
      const original = window.fetch.bind(window);
      window.fetch = async (...args) => {
        const response = await original(...args); const url = new URL(String(args[0]), location.origin);
        if (url.pathname === '/hospital-api/blood-bank' && url.searchParams.get('search') === 'اصطناعي1') await new Promise(r => setTimeout(r, 1400));
        return response;
      };
    });
    const search = page.getByRole('searchbox', { name: 'البحث في بنك الدم' });
    await search.fill('اصطناعي1'); assert.equal(await excel.isDisabled(), true);
    await page.getByRole('status').filter({ hasText: 'جارٍ تحديث النتائج' }).waitFor(); assert.equal(await excel.isDisabled(), true);
    assert.ok(await page.getByRole('region', { name: 'جدول بنك الدم' }).getByText('اصطناعي0', { exact: false }).count());
    await search.fill('اصطناعي2');
    await page.waitForFunction(() => ![...document.querySelectorAll('button')].find(b => b.textContent === 'Excel')?.disabled);
    await page.waitForTimeout(1600); assert.equal(await search.inputValue(), 'اصطناعي2');
    const download = page.waitForEvent('download'); await excel.click(); assert.match((await download).suggestedFilename(), /\.xlsx$/);
    // A real invalid sort fails validation while the existing same-context rows remain.
    await page.evaluate(() => window.history.pushState(null, '', '/blood-bank?search=اصطناعي2&sort=invalid'));
    await page.getByRole('alert').filter({ hasText: 'التصدير يتطلب نجاح إعادة التحميل' }).waitFor(); assert.equal(await excel.isDisabled(), true);
    await page.getByRole('button', { name: 'إعادة المحاولة' }).click(); assert.equal(await excel.isDisabled(), true);
    await page.getByRole('combobox', { name: 'الترتيب', exact: true }).selectOption('name');
    await page.waitForFunction(() => ![...document.querySelectorAll('button')].find(b => b.textContent === 'Excel')?.disabled);
    await page.getByRole('combobox', { name: 'نوع الملف' }).selectOption('donor'); assert.equal(await excel.isDisabled(), true);
    await page.waitForFunction(() => ![...document.querySelectorAll('button')].find(b => b.textContent === 'Excel')?.disabled);
  } finally { await context.close(); }
});

test('individual downloads remain independent of list and responsive export buttons are visible', async () => {
  const paths = { list: '/blood-bank', donor: `/blood-bank/donor/${donor.id}`, recipient: `/blood-bank/recipient/${recipient.id}`, donation: `/blood-bank/donor/${donor.id}/donations/${donation.id}` };
  for (const width of [390, 768, 1440]) for (const [name, path] of Object.entries(paths)) {
    const { page, context } = await pageAt(path, width);
    try {
      const button = page.getByRole('button', { name: name === 'list' ? 'PDF' : 'تقرير PDF', exact: true }); await button.waitFor();
      await page.waitForFunction(text => ![...document.querySelectorAll('button')].find(b => b.textContent === text)?.disabled, name === 'list' ? 'PDF' : 'تقرير PDF');
      if (name === 'donor') await page.getByRole('region', { name: 'جدول وقائع التبرع' }).waitFor();
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
      if (width === 1440 && name !== 'list') {
        const download = page.waitForEvent('download', { timeout: 65000 }); await button.click();
        try { assert.match((await download).suggestedFilename(), /\.pdf$/); }
        catch (e) { throw new Error(`${name}: ${await page.getByRole('alert').allTextContents()}`, { cause: e }); }
      }
    } finally { await context.close(); }
  }
});
