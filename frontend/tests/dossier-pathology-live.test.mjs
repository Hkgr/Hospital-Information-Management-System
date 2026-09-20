import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

// Real standalone Next -> Laravel -> guarded populated MariaDB; no interception.
const base = 'http://127.0.0.1:3194';
const artifacts = new URL('../.superdesign/tmp/pathology/', import.meta.url);
let f, browser, last;
function fixture(mode) {
  const result = spawnSync('php', ['tests/Support/dossier-pathology-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr); process.stdout.write(result.stdout); assert.ok(!/Exception|In .+ line|Missing saved/.test(result.stdout + result.stderr), result.stdout + result.stderr);
}
before(async () => { fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-pathology-live.json', import.meta.url))); browser = await chromium.launch(); mkdirSync(artifacts, { recursive: true }); });
after(async () => { await browser?.close(); fixture('cleanup'); });
async function api(method, path, data = {}, token = f.token) {
  const q = method === 'GET' ? `${path.includes('?') ? '&' : '?'}facility_id=${data.facility_id ?? f.facility}` : '';
  const response = await fetch(`${base}/hospital-api/dossiers${path}${q}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: token ? `Bearer ${token}` : '' }, ...method === 'GET' ? {} : { body: JSON.stringify({ facility_id: f.facility, request_id: randomUUID(), ...data }) } });
  assert.equal(response.headers.get('x-test-laravel'), 'dossiers'); assert.match(response.headers.get('cache-control'), /no-store/);
  return { status: response.status, body: await response.json() };
}
async function create() {
  const response = await api('POST', '', { person_mode: 'new', code: `PATH-${randomUUID().slice(0,18)}`, first_name: 'مريض', family_name: 'تشريح اصطناعي', opening_date: '1999-01-01', visit_date: '2000-02-03', visit_type_id: f.visit_type, birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown' });
  assert.equal(response.status, 201, JSON.stringify(response.body)); return response.body.data;
}
test('external completed pathology and validation preserve draft at 390, 768 and 1440; real reports', async () => {
  for (const width of [390, 768, 1440]) {
    last = await create(); const ctx = await browser.newContext({ viewport: { width, height: 1000 } });
    await ctx.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
    const page = await ctx.newPage();
    try {
      await page.goto(`${base}/patient-cards/${last.id}?facility_id=${f.facility}`);
      await page.getByRole('button', { name: 'إضافة تقرير تشريح مرضي', exact: true }).click();
      const dialog = page.getByRole('dialog');
      await dialog.locator('[name="source"]').selectOption('external');
      await dialog.locator('[name="status"]').selectOption('completed');
      await dialog.locator('[name="report_number"]').fill('0000123');
      const invalid = page.waitForResponse(r => r.url().includes('/pathology') && r.request().method() === 'POST' && r.status() === 422);
      await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await invalid;
      await page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'external_organization');
      assert.equal(await dialog.locator('[name="report_number"]').inputValue(), '0000123');
      await page.screenshot({ path: fileURLToPath(new URL(`form-errors-${width}.png`, artifacts)) });
      await dialog.locator('[name="external_organization"]').fill('مختبر خارجي اصطناعي');
      await dialog.locator('[name="result_on"]').fill('1998-06-02');
      await dialog.locator('[name="conclusion"]').fill('=خلاصة اصطناعية محفوظة لاختبار الطباعة');
      await dialog.locator('form').evaluate(el => el.parentElement.scrollTop = 0);
      await page.screenshot({ path: fileURLToPath(new URL(`form-${width}.png`, artifacts)) });
      await dialog.getByRole('button', {name:'حفظ',exact:true}).scrollIntoViewIfNeeded();
      await page.screenshot({ path: fileURLToPath(new URL(`form-bottom-${width}.png`, artifacts)) });
      const saved = page.waitForResponse(r => r.url().includes('/pathology') && r.request().method() === 'POST' && r.status() === 201);
      await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await saved;
      await page.getByRole('dialog').waitFor({ state: 'hidden' });
      await page.getByRole('region', { name: 'تقارير تشريح الزيارة', exact: true }).getByText('0000123', { exact: true }).waitFor();
      await page.getByText('نتيجة التشريح المرضي متوفرة', { exact: true }).first().waitFor();
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await page.evaluate(() => window.scrollTo(0, 0));
      await page.screenshot({ path: fileURLToPath(new URL(`detail-${width}.png`, artifacts)), fullPage: true });
    } finally { await ctx.close(); }
  }
  for (const [kind, endpoint] of [['patient-card', `/${last.id}/report`], ['visit', `/${last.id}/visits/${last.visit.id}/report`], ['list', '/export']]) for (const format of ['pdf', 'xlsx']) {
    const response = await fetch(`${base}/hospital-api/dossiers${endpoint}/${format}`, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${f.token}` }, body: JSON.stringify({ facility_id: f.facility, ...kind === 'list' ? { search: last.code, pathology_status: 'pathology_confirmed', columns: ['code','name','pathology_status','visit_count'] } : {} }) });
    assert.equal(response.status, 200); writeFileSync(new URL(`${kind}.${format}`, artifacts), Buffer.from(await response.arrayBuffer()));
  }
});
test('explicit latest-version review preserves other author changes and the local draft', async () => {
  const rows = await api('GET', `/${last.id}/pathology`), row = rows.body.data[0];
  const ctx = await browser.newContext(); await ctx.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token);
  const page = await ctx.newPage();
  try {
    await page.goto(`${base}/patient-cards/${last.id}?facility_id=${f.facility}&visit=${last.visit.id}`);
    await page.getByRole('button', { name: 'تعديل تقرير التشريح المرضي', exact: true }).click();
    const dialog = page.getByRole('dialog'); await dialog.locator('[name="note"]').fill('مسودتي المحلية');
    assert.equal((await api('PUT', `/${last.id}/visits/${last.visit.id}/pathology/${row.id}`, { ...row, request_id: randomUUID(), conclusion: 'تصحيح الطبيب الآخر' })).status, 200);
    const conflict = page.waitForResponse(r => r.status() === 409); await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await conflict;
    assert.equal(await dialog.locator('[name="note"]').inputValue(), 'مسودتي المحلية');
    await dialog.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).click();
    await dialog.getByText('الأحدث: تصحيح الطبيب الآخر', { exact: false }).waitFor();
    await dialog.getByRole('checkbox', { name: /ملاحظات/ }).check();
    await dialog.getByRole('button', { name: 'اعتماد الاختيارات للمراجعة قبل الحفظ' }).click();
    assert.equal(await dialog.locator('[name="conclusion"]').inputValue(), 'تصحيح الطبيب الآخر');
    assert.equal(await dialog.locator('[name="note"]').inputValue(), 'مسودتي المحلية');
    const saved = page.waitForResponse(r => r.request().method() === 'PUT' && r.status() === 200); await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await saved;
    const updated = await api('GET', `/${last.id}/visits/${last.visit.id}/pathology/${row.id}`); assert.equal(updated.body.data.note, 'مسودتي المحلية'); assert.equal(updated.body.data.conclusion, 'تصحيح الطبيب الآخر');
  } finally { await ctx.close(); }
});
test('real authorization, cross-facility refusal, UUID replay and authoritative list filter', async () => {
  const data = { source: 'internal', status: 'requested', requested_on: '2000-02-03', request_id: randomUUID() }, path = `/${last.id}/visits/${last.visit.id}/pathology`;
  const first = await api('POST', path, data); assert.equal(first.status, 201); assert.equal((await api('POST', path, data)).body.data.id, first.body.data.id);
  assert.equal((await api('POST', path, { ...data, note: 'different' })).status, 409);
  assert.equal((await api('POST', path, { ...data, request_id: randomUUID() }, f.denied_token)).status, 403);
  assert.equal((await api('GET', `/${last.id}/pathology`, { facility_id: f.other })).status, 403);
  assert.equal((await api('GET', `/${last.id}/pathology`, {}, '')).status, 401);
  const list = await api('GET', `?search=${encodeURIComponent(last.code)}&pathology_status=pathology_required`); assert.equal(list.body.totals.dossiers, 1);
  fixture('verify');
  fixture('concurrency');
});

test('diagnostic decision UI, optional column and filter use the saved pathology; read-only UI has no write actions', async () => {
  const ctx = await browser.newContext(); await ctx.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token); const page = await ctx.newPage();
  try {
    await page.goto(`${base}/patient-cards/${last.id}?facility_id=${f.facility}`);
    await page.getByRole('button', {name:'تسجيل أو تعديل التقييم التشخيصي',exact:true}).click();
    const dialog=page.getByRole('dialog'); await dialog.locator('[name="disposition"]').selectOption('pathology_not_required');
    await dialog.locator('[name="not_required_reason"]').fill('قرار سريري صريح');
    await dialog.locator('[name="assessed_on"]').fill('2001-01-01');
    const saved=page.waitForResponse(r=>r.request().method()==='PUT'&&r.url().includes('diagnostic-assessment')&&r.status()===200);
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click(); await saved; await dialog.waitFor({state:'hidden'});
    assert.equal((await api('GET',`/${last.id}/visits/${last.visit.id}/diagnostic-assessment`)).body.data.disposition,'pathology_not_required');
    await page.goto(`${base}/patient-cards?facility_id=${f.facility}&search=${encodeURIComponent(last.code)}&pathology_status=pathology_not_required`);
    await page.getByRole('region',{name:'جدول بطاقات المرضى'}).getByText(last.code,{exact:true}).waitFor();
    await page.getByText('الأعمدة',{exact:true}).click(); await page.getByRole('checkbox',{name:'حالة التشريح المرضي',exact:true}).check();
    await page.getByRole('region',{name:'جدول بطاقات المرضى'}).getByText('لا يتطلب تشريحًا مرضيًا',{exact:true}).waitFor();
    await page.getByText('الأعمدة',{exact:true}).click();
    for (const width of [390, 768, 1440]) {
      await page.setViewportSize({width,height:1000});
      await page.reload();
      await page.getByRole('region',{name:'جدول بطاقات المرضى'}).getByText(last.code,{exact:true}).waitFor();
      await page.getByText('الأعمدة',{exact:true}).click();
      await page.getByRole('checkbox',{name:'حالة التشريح المرضي',exact:true}).check();
      await page.getByText('الأعمدة',{exact:true}).click();
      await page.evaluate(() => document.fonts.ready);
      await page.evaluate(() => window.scrollTo(0, 0));
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await page.screenshot({path:fileURLToPath(new URL(`list-${width}.png`,artifacts)),fullPage:true});
    }
  } finally {await ctx.close();}
  const readonly=await browser.newContext(); await readonly.addInitScript(t=>sessionStorage.setItem('hospital.bearer',t),f.denied_token); const p=await readonly.newPage();
  try {await p.goto(`${base}/patient-cards/${last.id}?facility_id=${f.facility}`);await p.getByRole('heading',{name:'تقارير التشريح المرضي',exact:true}).first().waitFor();assert.equal(await p.getByRole('button',{name:'إضافة تقرير تشريح مرضي',exact:true}).count(),0);}
  finally {await readonly.close();}
});
