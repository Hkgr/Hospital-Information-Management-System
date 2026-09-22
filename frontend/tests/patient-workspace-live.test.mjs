import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

// Real standalone Next -> Laravel -> isolated MariaDB; deliberately no API interception.
const base = 'http://127.0.0.1:3194';
const gallery = new URL('../.superdesign/tmp/patient-workspace/', import.meta.url);
let f, browser;
function fixture(mode) {
  const p = spawnSync(process.env.PHP_BINARY ?? 'php', ['tests/Support/oncology-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(p.status, 0, p.stdout + p.stderr);
  assert.doesNotMatch(p.stdout + p.stderr, /In .+ line|Exception/);
}
before(async () => { fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/oncology-live.json', import.meta.url))); browser = await chromium.launch(process.env.PLAYWRIGHT_CHANNEL ? { channel: process.env.PLAYWRIGHT_CHANNEL } : {}); mkdirSync(gallery, { recursive: true }); });
after(async () => { await browser?.close(); fixture('cleanup'); });
async function api(method, path, data = {}) {
  const r = await fetch(`${base}/hospital-api/dossiers${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${f.token}` }, ...method === 'GET' ? {} : { body: JSON.stringify({ facility_id: f.facility, request_id: randomUUID(), ...data }) } });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers');
  assert.match(r.headers.get('cache-control'), /no-store/);
  assert.ok(r.ok, await r.clone().text());
  return r.json();
}
async function pageAt(width, url) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(12000); page.on('dialog', d => d.accept());
  await page.goto(`${base}${url}`); return { context, page };
}
async function choose(page, label, text) {
  await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
  const group = page.getByRole('group', { name: label, exact: true });
  if (text) await group.getByRole('searchbox').fill(text);
  const option = text ? group.getByRole('button', { name: new RegExp(text) }).first() : group.locator('button[aria-pressed]').first();
  await option.click();
}
async function save(page, suffix) {
  const response = page.waitForResponse(r => r.url().endsWith(`/hospital-api/dossiers${suffix}`) && ['POST', 'PUT'].includes(r.request().method()));
  await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
  const r = await response; assert.ok(r.ok(), await r.text()); return (await r.json()).data;
}
async function shot(page, name) {
  await page.evaluate(async () => { await document.fonts.ready; window.scrollTo(0, 0); await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); });
  assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), name + ': horizontal overflow');
  await page.screenshot({ path: fileURLToPath(new URL(`${name}.png`, gallery)), fullPage: true });
}
async function stage(page, title, index) {
  const mobile = page.getByRole('combobox', { name: 'القسم الحالي', exact: true });
  if (await mobile.isVisible()) await mobile.selectOption(String(index));
  else await page.getByRole('navigation', { name: 'مراحل مساحة العمل' }).getByRole('button', { name: new RegExp(title) }).click();
}

test('new card and real first visit, validation and compact clinic/doctor fields at 390/768/1440', async () => {
  for (const width of [390, 768, 1440]) {
    const { context, page } = await pageAt(width, `/patient-cards/new?facility_id=${f.facility}`);
    try {
      await page.getByRole('heading', { name: 'البيانات الشخصية', exact: true }).waitFor();
      assert.equal(await page.locator('[name="visit_type_id"]').count(), 0);
      await shot(page, `new-${width}`);
      await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
      await page.locator('[name="code"][aria-invalid="true"]').waitFor();
      assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), 'code');
      await shot(page, `errors-${width}`);
      await page.locator('[name="code"]').fill(`SPACE-${f.tag}-${width}`);
      await page.locator('[name="opening_date"]').fill('2001-03-02');
      await page.locator('[name="visit_date"]').fill('2001-03-02');
      await page.locator('[name="first_name"]').fill('أحمد'); await page.locator('[name="family_name"]').fill('مريض اصطناعي');
      let d = await save(page, '');
      assert.ok(d.visit.id); assert.equal(d.visit.status, 'draft'); assert.equal('visit_type_id' in d.visit, false);
      await page.getByRole('heading', { name: 'المعلومات الطبية والورمية', exact: true }).waitFor();
      assert.ok(page.url().includes('/patient-cards/new?'));
      await page.locator('[name="is_oncology"]').selectOption('yes');
      d = await save(page, `/${d.id}/medical`);
      await page.getByRole('button', { name: 'إضافة تشخيص للزيارة', exact: true }).click();
      await choose(page, 'التشخيص من الدليل 1', 'خباثات الاذن');
      await choose(page, 'العيادة للتشخيص 1', 'عيادة التشخيص 1');
      await choose(page, 'الطبيب المسؤول عن التشخيص 1', 'الطبيب المسؤول 1');
      await choose(page, 'العيادة للتشخيص 1', 'عيادة التشخيص 2');
      assert.ok(!(await page.getByRole('button', { name: 'اختيار: الطبيب المسؤول عن التشخيص 1' }).innerText()).includes('الطبيب المسؤول 1'));
      await choose(page, 'الطبيب المسؤول عن التشخيص 1', 'الطبيب المسؤول 2');
      await page.locator('[name="visit_date"]').fill('1980-01-01');
      await page.getByRole('button', { name: 'اختيار: الطبيب المسؤول عن التشخيص 1' }).click();
      await page.getByText('لا يوجد طبيب مؤهل مرتبط بهذه العيادة في التاريخ المحدد. راجع ارتباطات العيادة أو صحح التاريخ.').waitFor();
      await page.getByRole('button', { name: 'إغلاق خيارات الطبيب المسؤول عن التشخيص 1' }).click();
      await page.locator('[name="visit_date"]').fill('2001-03-02');
      await choose(page, 'الطبيب المسؤول عن التشخيص 1', 'الطبيب المسؤول 2');
      await shot(page, `visit-${width}`);
      d = await save(page, `/${d.id}/visits/${d.visit.id}`);
      assert.equal(d.visit.diagnoses[0].clinic_id, f.clinics[1]);
      assert.equal(d.visit.diagnoses[0].diagnosing_staff_id, f.workflow_doctors[1]);
      await stage(page, 'التقييم والتشريح المرضي', 6);
      await page.getByRole('button', { name: 'تسجيل أو تعديل التقييم التشخيصي', exact: true }).waitFor();
      await shot(page, `pathology-${width}`);
      await stage(page, 'الخطة ومواعيد العلاج', 7);
      await page.getByRole('button', { name: 'إضافة خطة علاجية', exact: true }).waitFor();
      await shot(page, `treatment-${width}`);
      await stage(page, 'أدوية الزيارة والنتيجة', 4);
      await page.getByRole('tab', {name:'أدوية مصروفة من المشفى (غير مرتبطة بالجرعة)', exact:true}).click();
      await page.getByRole('heading', { name: 'أدوية مصروفة من المشفى (غير مرتبطة بالجرعة)', exact: true }).waitFor();
      await page.getByRole('tab', {name:'أدوية مرتبطة بالجرعة', exact:true}).click();
      await page.getByRole('heading', { name: 'أدوية مرتبطة بالجرعة', exact: true }).waitFor();
      await page.getByRole('tab', {name:'أدوية خارج المشفى', exact:true}).click();
      await page.getByRole('heading', { name: 'أدوية خارج المشفى', exact: true }).waitFor();
      await page.getByRole('tab', {name:'النتيجة', exact:true}).click();
      await page.getByRole('heading', { name: 'نتيجة الزيارة', exact: true }).waitFor();
      await shot(page, `administration-${width}`);
      const record = (await api('GET', `/${d.id}?facility_id=${f.facility}`)).data;
      assert.equal(record.visit_count, 1);
    } finally { await context.close(); }
  }
});

test('Visits navigation opens an existing card without duplicate identity or automatic visit writes', async () => {
  const { context, page } = await pageAt(1440, `/visits?facility_id=${f.facility}`);
  try {
    await page.getByRole('heading', { name: 'الزيارات', exact: true }).last().waitFor();
    await page.getByRole('link', { name: 'تسجيل زيارة', exact: true }).click();
    await page.getByRole('heading', { name: 'تسجيل زيارة لمريض موجود', exact: true }).waitFor();
    await page.getByRole('searchbox', { name: 'البحث عن بطاقة المريض' }).fill(f.search_patient_code);
    await page.locator(`a[href*="card=${f.dossiers[0]}&visit=new"]`).click();
    await page.getByRole('heading', { name: 'الزيارة والتشخيصات', exact: true }).waitFor();
    await page.locator('[name="visit_date"]').fill('2001-03-05');
    const card = new URL(page.url()).searchParams.get('card');
    const before = (await api('GET', `/${card}?facility_id=${f.facility}`)).data;
    const d = await save(page, `/${card}/visits/subsequent`);
    assert.equal(d.card_id, before.card_id);
    assert.equal((await api('GET', `/${card}?facility_id=${f.facility}`)).data.visit_count, before.visit_count + 1);
    await shot(page, 'existing-visit-1440');
  } finally { await context.close(); }
});
