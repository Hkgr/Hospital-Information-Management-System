import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync, writeFileSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194';
const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
let fixture, browser;
function setup(mode) {
  const r = spawnSync('php', ['tests/Support/statistics-live.php', mode], { cwd: backend, env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  return r.stdout;
}
before(async () => {
  setup('prepare');
  fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/statistics-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  mkdirSync('test-results/statistics', { recursive: true });
});
after(async () => { await browser?.close(); if (fixture) setup('cleanup'); });
async function api(token, path, body) {
  const r = await fetch(`${base}/hospital-api/${path}`, { method: body ? 'POST' : 'GET', headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers', 'Real Next -> Laravel required');
  return r;
}
async function login(role) {
  const r = await api(null, 'login', { username: fixture.users[role].username, password: 'password', device_name: 'statistics-live' });
  assert.equal(r.status, 200);
  return (await r.json()).data.token;
}
async function browserFor(token, width = 1440) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, acceptDownloads: true });
  await context.addInitScript(t => { if (!sessionStorage.getItem('hospital.fixture-loaded')) { sessionStorage.setItem('hospital.bearer', t); sessionStorage.setItem('hospital.fixture-loaded', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  page.apiTrace = [];
  page.on('request', r => { if (r.url().includes('/hospital-api/')) page.apiTrace.push([new URL(r.url()).pathname, 'start']); });
  page.on('response', r => { if (r.url().includes('/hospital-api/')) page.apiTrace.push([new URL(r.url()).pathname, r.status()]); });
  page.on('requestfailed', r => { if (r.url().includes('/hospital-api/')) page.apiTrace.push([new URL(r.url()).pathname, r.failure()?.errorText]); });
  return { context, page };
}
const query = () => `facility_id=${fixture.facility}&from_month=2020-01&to_month=2020-01`;

test('four actual roles, protected aggregate JSON and exports through Next; responsive RTL portal', async () => {
  for (const role of ['statistics', 'hospital_admin', 'super_admin', 'data_entry']) {
    const token = await login(role);
    const response = await api(token, `statistics?${query()}`);
    assert.equal(response.status, role === 'data_entry' ? 403 : 200);
    if (role !== 'data_entry') {
      const data = (await response.json()).data;
      assert.equal(data.months[0].sections[0].patients, 11);
      assert.equal(data.months[0].sections[0].events, 12);
      assert.doesNotMatch(JSON.stringify(data), /SECRET|0900999000|patient_id|dossier_id/);
      assert.equal((await api(token, `statistics?${query().replace(`facility_id=${fixture.facility}`, `facility_id=${fixture.other}`)}`)).status, role === 'super_admin' ? 200 : 403);
    }
    const { context, page } = await browserFor(token);
    try {
      if (role === 'statistics') {
        await page.goto(`${base}/login`);
        await page.getByLabel('اسم المستخدم', { exact: true }).fill(fixture.users[role].username);
        await page.getByLabel('كلمة المرور', { exact: true }).fill('password');
        await page.getByRole('button', { name: 'دخول', exact: true }).click();
        await page.waitForURL(`${base}/statistics?facility_id=${fixture.facility}`);
        assert.equal(await page.evaluate(() => Boolean(sessionStorage.getItem('hospital.bearer'))), true, 'Login must persist this tab token before navigation');
      }
      await page.goto(`${base}/${role === 'data_entry' ? `reception?facility_id=${fixture.facility}` : `statistics?${query()}`}`);
      if (role === 'data_entry') {
        await page.getByRole('searchbox').waitFor();
        assert.equal((await api(token, `dossiers?facility_id=${fixture.facility}`)).status, 403);
      } else {
        await page.getByRole('button', { name: 'تصدير Excel', exact: true }).waitFor();
        await page.getByText('مرضى فريدون: 11 — الوقائع: 12', { exact: true }).first().waitFor();
        if (role === 'super_admin') {
          await page.getByLabel('المنشأة', { exact: true }).selectOption(String(fixture.other));
          await page.getByText('محجوب لحماية الخصوصية', { exact: true }).first().waitFor();
          assert.equal(await page.getByText('مرضى فريدون: 11 — الوقائع: 12', { exact: true }).count(), 0);
          await page.getByLabel('المنشأة', { exact: true }).selectOption(String(fixture.facility));
          await page.getByText('مرضى فريدون: 11 — الوقائع: 12', { exact: true }).first().waitFor();
        }
        if (role === 'statistics') {
          assert.equal(await page.getByRole('link', { name: 'بطاقة المريض', exact: true }).count(), 0);
          for (const width of [390, 768, 1440]) {
            await page.setViewportSize({ width, height: 1000 });
            // Resize changes the responsive sidebar and starts its existing
            // transition. Inspect the settled layout, not a frame mid-resize.
            await page.waitForFunction(expected => Math.round(document.querySelector('#main-content').getBoundingClientRect().width) === innerWidth - expected, width >= 1280 ? 268 : width >= 768 ? 84 : 0);
            await page.evaluate(() => document.fonts.ready);
            // Viewport captures preserve Chromium's fixed RTL sidebar geometry;
            // capture the bottom separately instead of expanding the viewport.
            await page.screenshot({ path: `test-results/statistics/portal-${width}.png` });
            await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
            await page.screenshot({ path: `test-results/statistics/portal-bottom-${width}.png` });
            await page.evaluate(() => window.scrollTo(0, 0));
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
          }
          for (const format of ['pdf', 'xlsx']) {
            const r = await api(token, `statistics/export/${format}?${query()}`, {});
            assert.equal(r.status, 200); writeFileSync(`test-results/statistics/report.${format}`, Buffer.from(await r.arrayBuffer()));
            const long = await api(token, `statistics/export/${format}?${query().replace('to_month=2020-01', 'to_month=2020-12')}`, {});
            assert.equal(long.status, 200); writeFileSync(`test-results/statistics/long-report.${format}`, Buffer.from(await long.arrayBuffer()));
          }
          const download = page.waitForEvent('download');
          await page.getByRole('button', { name: 'تصدير Excel', exact: true }).click();
          assert.equal((await download).suggestedFilename(), 'statistics.xlsx');
          for (const path of ['dossiers', 'users', 'reception/patients?search=SECRET', 'reports?period=day']) assert.equal((await api(token, `${path}${path.includes('?') ? '&' : '?'}facility_id=${fixture.facility}`)).status, 403);
        }
      }
    } catch (error) {
      await page.screenshot({ path: `test-results/statistics/failure-${role}.png`, fullPage: true });
      throw new Error(`${role}: ${new URL(page.url()).pathname}: ${JSON.stringify(page.apiTrace)}: ${(await page.locator('body').innerText()).slice(0, 1200)}`, { cause: error });
    } finally { await context.close(); }
  }
});

test('same-token tabs continue explicitly, preserve drafts during checks, then clear on authoritative idle expiry', async () => {
  const token = await login('statistics');
  const { context, page } = await browserFor(token);
  const other = await context.newPage();
  try {
    for (const p of [page, other]) { await p.goto(`${base}/statistics?${query()}`); await p.getByRole('button', { name: 'تصدير Excel' }).waitFor(); }
    await other.getByLabel('من شهر مكتمل').fill('2019-12');
    setup('warn'); await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await page.getByRole('button', { name: 'متابعة الجلسة', exact: true }).click();
    await page.getByRole('alert', { name: 'تنبيه انتهاء الجلسة' }).waitFor({ state: 'hidden' });
    await other.evaluate(() => window.dispatchEvent(new Event('focus')));
    assert.equal(await other.getByLabel('من شهر مكتمل').inputValue(), '2019-12');
    setup('expire');
    const expired = await api(token, 'session/activity', {}); assert.equal(expired.status, 401); assert.equal((await expired.json()).error.code, 'SESSION_IDLE_EXPIRED');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    for (const p of [page, other]) { await p.waitForURL('**/login?reason=idle'); await p.getByText('انتهت الجلسة بسبب الخمول', { exact: true }).waitFor(); assert.equal(await p.evaluate(() => sessionStorage.getItem('hospital.bearer')), null); assert.equal(await p.getByLabel('من شهر مكتمل').count(), 0); }
  } finally { await context.close(); }
});

test('reception remains nonmedical, UUID replay creates only one first visit under web session policy', async () => {
  const token = await login('data_entry');
  const body = { facility_id: fixture.facility, request_id: randomUUID(), person_mode: 'new', first_name: 'اختبار', family_name: 'إحصاء', birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown', opening_date: '2020-01-01', visit_date: '2020-01-01' };
  const first = await api(token, 'reception/registrations', body); assert.equal(first.status, 201); const record = (await first.json()).data;
  const repeat = await api(token, 'reception/registrations', body); assert.equal(repeat.status, 201); assert.equal((await repeat.json()).data.registration_visit_id, record.registration_visit_id);
  assert.equal((await api(token, `dossiers/${record.id}?facility_id=${fixture.facility}`)).status, 403);
  assert.equal((await api(token, `dossiers/${record.id}/report/xlsx?facility_id=${fixture.facility}`, {})).status, 403);
});

test('two independent PHP workers cannot revive expired token or duplicate expiry audit', async () => {
  const token = await login('statistics'); setup('expire');
  const beforeCount = Number(setup('audits'));
  const gate = randomUUID(), path = new URL(`../../backend/storage/framework/testing/idle-${gate}`, import.meta.url);
  const workers = [0, 1].map(() => {
    const child = spawn('php', ['tests/Support/idle-concurrent.php'], { cwd: backend, env: { ...process.env, APP_ENV: 'testing' } });
    let output = '', errors = '', signal;
    const ready = new Promise(resolve => { signal = resolve; });
    child.stdout.on('data', data => { output += data; if (output.includes('READY\n')) signal(); });
    child.stderr.on('data', data => { errors += data; });
    const done = new Promise((resolve, reject) => { child.on('error', reject); child.on('exit', code => code === 0 ? resolve(JSON.parse(output.trim().split('\n').at(-1)).status) : reject(new Error(errors || output))); });
    child.stdin.end(JSON.stringify({ token, gate }));
    return { child, ready, done };
  });
  let timeout;
  try {
    await Promise.race([Promise.all(workers.map(w => w.ready)), new Promise((_, reject) => { timeout = setTimeout(() => reject(new Error('Worker readiness timeout')), 15000); })]);
    writeFileSync(path, 'go');
    assert.deepEqual(await Promise.all(workers.map(w => w.done)), [401, 401]);
    assert.equal(Number(setup('audits')), beforeCount + 1);
  } finally { clearTimeout(timeout); workers.forEach(w => w.child.kill()); try { unlinkSync(path); } catch {} }
});
