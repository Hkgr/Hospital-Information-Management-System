import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { chromium } from 'playwright';
const base = 'http://127.0.0.1:3194';
let browser;
before(async () => { browser = await chromium.launch({ channel: 'chrome' }); });
after(async () => { await browser?.close(); });
async function fixture({ initialFailure = false } = {}) {
  const context = await browser.newContext(); const page = await context.newPage();
  await page.clock.install();
  let reads = 0, renewals = 0;
  let fail = initialFailure;
  await context.addInitScript(() => { if (!sessionStorage.getItem('fixture')) { sessionStorage.setItem('fixture', 'yes'); sessionStorage.setItem('hospital.bearer', 'test-idle'); } });
  await page.route('**/hospital-api/**', route => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/hospital-api/session' || path.endsWith('/session/activity')) {
      if (fail) { fail = false; return route.abort(); }
      if (path.endsWith('/activity')) renewals++; else reads++;
      return route.fulfill({ json: { data: { idle_timeout: 120, remaining_seconds: 120 } } });
    }
    if (path.endsWith('/user')) return route.fulfill({ json: { data: { user: { id: 7, username: 'fixture', name: 'مستخدم تجريبي' }, access: [{ facility: { id: 1, name_ar: 'اختبار', timezone: 'Asia/Damascus' }, roles: [], permissions: ['statistics.view'] }] } } });
    if (path.endsWith('/statistics')) return route.fulfill({ json: { data: { facility: { name_ar: 'اختبار' }, privacy: { policy: 'تجميع اختباري' }, occupancy: { reason: 'غير متاح' }, months: [] } } });
    return route.fulfill({ status: 404, json: {} });
  });
  await page.goto(`${base}/statistics?facility_id=1&from_month=2020-01&to_month=2020-01`);
  if (initialFailure) await page.getByRole('button', { name: 'إعادة التحقق' }).waitFor();
  else await page.getByLabel('من شهر مكتمل').waitFor();
  assert.equal(await page.getByRole('button', { name: 'تصدير Excel' }).count(), 0);
  return { context, page, reads: () => reads, renewals: () => renewals };
}
test('initial verification transport failure hides content and allows retry without inventing idle expiry', async () => {
  const f = await fixture({ initialFailure: true });
  try {
    assert.equal(await f.page.getByLabel('من شهر مكتمل').count(), 0);
    assert.equal(await f.page.evaluate(() => sessionStorage.getItem('hospital.bearer')), 'test-idle');
    await f.page.getByRole('button', { name: 'إعادة التحقق' }).click();
    await f.page.getByLabel('من شهر مكتمل').waitFor();
    assert.equal(f.renewals(), 0);
  } finally { await f.context.close(); }
});
test('timer and focus do not renew; genuine typing renews without saving', async () => {
  const f = await fixture();
  try {
    await f.page.clock.runFor(60000);
    assert.equal(f.renewals(), 0);
    await f.page.getByLabel('من شهر مكتمل').fill('2019-12');
    await f.page.keyboard.press('ArrowRight');
    const renewed = f.page.waitForResponse(r => r.url().endsWith('/session/activity'));
    await f.page.clock.runFor(21000);
    await renewed;
    assert.ok(f.renewals() >= 1);
    const count = f.renewals();
    await f.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await f.page.clock.runFor(21000);
    assert.equal(f.renewals(), count);
    assert.equal(await f.page.getByLabel('من شهر مكتمل').inputValue(), '2019-12');
  } finally { await f.context.close(); }
});
test('sleep/clock jump revalidates without extending and keeps the mounted draft', async () => {
  const f = await fixture();
  try {
    await f.page.getByLabel('من شهر مكتمل').fill('2019-12');
    await f.page.clock.runFor(21000);
    const before = f.reads();
    const rechecked = f.page.waitForResponse(r => r.url().endsWith('/session'));
    await f.page.clock.setSystemTime(new Date('2040-01-01T00:00:00Z'));
    await f.page.clock.runFor(1000);
    await rechecked;
    await f.page.getByLabel('من شهر مكتمل').waitFor();
    assert.ok(f.reads() > before);
    assert.equal(await f.page.getByLabel('من شهر مكتمل').inputValue(), '2019-12');
  } finally { await f.context.close(); }
});
test('offline deadline removes token and page state; no automatic replay or persistent draft', async () => {
  const f = await fixture();
  try {
    await f.page.getByLabel('من شهر مكتمل').fill('2019-12');
    // Abort transport after initial authorized render, including explicit activity.
    await f.page.route('**/hospital-api/session{,/activity}', route => route.abort());
    await f.page.clock.runFor(121000);
    await f.page.waitForURL('**/login?reason=idle');
    assert.equal(await f.page.evaluate(() => sessionStorage.getItem('hospital.bearer')), null);
    assert.equal(await f.page.getByLabel('من شهر مكتمل').count(), 0);
    assert.equal(await f.page.evaluate(() => JSON.stringify(localStorage).includes('2019-12')), false);
    assert.equal(f.renewals(), 0);
  } finally { await f.context.close(); }
});
