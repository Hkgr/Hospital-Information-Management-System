import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { mkdir } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
const base = 'http://127.0.0.1:3194'; let browser, f, completed = false;
const gallery = new URL('../.superdesign/tmp/dossiers-phase2-regression/', import.meta.url);
function fixture(mode) { const r = spawnSync('php', ['tests/Support/dossier-workflow-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' }); assert.equal(r.status, 0, r.stdout + r.stderr); process.stdout.write(r.stdout); }
before(async () => { fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-workflow-live.json', import.meta.url))); browser = await chromium.launch(); await mkdir(gallery, { recursive: true }); });
after(async () => { await browser?.close(); try { if (completed) fixture('verify'); } finally { fixture('cleanup'); } });
async function api(method, path, fields = {}, token = f.token) {
  const data = { facility_id: f.facility, ...fields };
  const r = await fetch(`${base}/hospital-api/dossiers${path}${method === 'GET' ? `?${new URLSearchParams(data)}` : ''}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: token ? `Bearer ${token}` : '' }, ...(method === 'GET' ? {} : { body: JSON.stringify(data) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers', 'Must pass through Next to actual Laravel');
  assert.match(r.headers.get('cache-control'), /no-store/);
  return { status: r.status, body: await r.json() };
}
async function pageAt(width, path = '/dossiers/new') { const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' }); await context.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token); const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('dialog', d => d.accept()); await page.goto(`${base}${path}?facility_id=${f.facility}`); return { context, page }; }
async function capture(page, name) { await page.waitForFunction(() => !Array.from(document.querySelectorAll('[role="status"]')).some(el => el.textContent.includes('جارٍ تحميل الخيارات'))); await page.evaluate(async () => { await document.fonts.ready; window.scrollTo(0, 0); await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))); }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false, name); if (process.env.DOSSIER_GALLERY_FILTER && !name.startsWith(process.env.DOSSIER_GALLERY_FILTER)) return; await page.screenshot({ path: fileURLToPath(new URL(`${name}.jpg`, gallery)), fullPage: true, type: 'jpeg', quality: 78 }); }
async function button(page, name) { await page.getByRole('button', { name, exact: true }).click(); }
async function saveNext(page, path) { const response = page.waitForResponse(r => r.url().includes(`/hospital-api/dossiers${path}`) && ['POST', 'PUT'].includes(r.request().method())); await button(page, 'حفظ ومتابعة'); const r = await response; assert.ok(r.ok(), await r.text()); return (await r.json()).data; }
async function choose(page, label, search, text) { const field = page.getByRole('group', { name: label, exact: true }); await field.getByRole('searchbox').fill(search); await field.getByRole('button', { name: new RegExp(text) }).first().click(); }

test('opening has no writes; actual rewrite boundary, permission isolation and diagnostic options', async () => {
  for (const width of [390, 768, 1440]) {
    const { page, context } = await pageAt(width);
    try { await page.getByRole('heading', { name: 'البيانات الشخصية', exact: true }).waitFor(); await capture(page, `empty-${width}`); const future = page.getByRole('list', { name: 'مراحل بطاقة المريض' }).getByRole('button'); assert.equal(await future.count(), 6); for (const b of (await future.all()).slice(3)) { assert.equal(await b.isDisabled(), true); await b.evaluate(el => el.click()); } }
    finally { await context.close(); }
  }
  fixture('verify-empty');
  for (const [token, facility, status] of [['', f.facility, 401], [f.denied_token, f.facility, 403], [f.token, f.other, 403]]) assert.equal((await api('GET', '/options', { facility_id: facility }, token)).status, status);
  for (const path of ['/abc/progress', '/1/activate', '/1/complete', '/1/report', '/1/uploads']) { const r = await fetch(`${base}/hospital-api/dossiers${path}`); assert.equal(r.status, 404); assert.equal(r.headers.get('x-test-laravel'), null); }
});

test('real three-section wizard, saved/resumed draft, diagnoses, validation and accessible gallery at every width', async () => {
  for (const width of [390, 768, 1440]) {
    const { page, context } = await pageAt(width);
    try {
      await page.getByRole('radio', { name: 'تسجيل بطاقة مريض جديدة', exact: true }).check();
      await page.locator('[name="first_name"]').fill('ليلى'); await page.locator('[name="family_name"]').fill(`اختبار المعالج ${width}`);
      await page.getByRole('radio', { name: 'إضافة زيارة لمريض موجود', exact: true }).check();
      await choose(page, 'المريض الموجود', f.search_patient_name, f.search_patient_code);
      await page.getByRole('link', { name: 'فتح البطاقة / إضافة زيارة', exact: true }).waitFor(); await capture(page, `existing-patient-${width}`);
      await page.getByRole('radio', { name: 'تسجيل بطاقة مريض جديدة', exact: true }).check(); assert.equal(await page.locator('[name="first_name"]').inputValue(), 'ليلى');
      await button(page, 'حفظ ومتابعة'); await page.locator('[name="code"][aria-invalid="true"]').waitFor(); assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), 'code'); await capture(page, `personal-errors-${width}`);
      await page.locator('[name="code"]').fill(`WIZ-${f.tag}-${width}`); await page.locator('[name="opening_date"]').fill('1999-02-03'); await page.locator('[name="visit_date"]').fill('2000-03-04'); await page.locator('[name="visit_type_id"]').selectOption(String(f.visit_type)); await page.locator('[name="father_name"]').fill('اسم أب اختباري'); await page.locator('[name="birth_date"]').fill('1980-01-02'); await page.locator('[name="birth_date_accuracy"]').selectOption('exact'); await page.locator('[name="gender"]').selectOption('female'); await page.locator('[name="address_line"]').fill('عنوان اصطناعي للمراجعة فقط'); await capture(page, `new-patient-${width}`);
      await choose(page, 'المحافظة السورية', f.tag, `محافظة اختبار ${f.tag}`); await choose(page, 'المدينة التابعة للمحافظة', f.tag, `مدينة اختبار ${f.tag}`); await capture(page, `new-patient-${width}`);
      let d = await saveNext(page, ''); assert.equal(Number(d.patient.city_id), f.city); await page.getByRole('heading', { name: 'المعلومات الطبية والورمية', exact: true }).waitFor(); assert.match(page.url(), new RegExp(`/dossiers/${d.id}/edit`));
      await page.locator('[name="is_oncology"]').selectOption('no'); await capture(page, `medical-general-${width}`);
      await page.locator('[name="clinical_history"]').fill('قصة مرضية اصطناعية محفوظة للمراجعة'); await page.locator('[name="disability_text"]').fill('وصف اختباري فقط'); await page.locator('[name="is_oncology"]').selectOption('yes');
      await page.getByRole('checkbox', { name: 'مرضية', exact: true }).check(); await page.getByRole('checkbox', { name: 'عائلية', exact: true }).check(); await page.getByRole('checkbox', { name: 'كيميائي', exact: true }).check();
      await page.locator('[name="medication_source"]').selectOption('other_organization'); await button(page, 'حفظ ومتابعة'); await page.locator('[name="other_organization"][aria-invalid="true"]').waitFor(); await capture(page, `oncology-errors-${width}`);
      await page.locator('[name="other_organization"]').fill('جهة اختبارية'); await page.locator('[name="previous_examinations"]').fill('فحوص اصطناعية'); await capture(page, `oncology-${width}`);
      d = await saveNext(page, `/${d.id}/medical`); await page.getByRole('heading', { name: 'الزيارة والتشخيصات', exact: true }).waitFor();
      await page.locator('[name="visit_date"]').fill('2000-03-04'); await page.locator('[name="visit_type_id"]').selectOption(String(f.visit_type)); await page.locator('[name="is_referred"]').selectOption('yes'); await page.locator('[name="referring_hospital"]').fill('مشفى إحالة اختباري'); await page.locator('[name="referral_date"]').fill('2000-03-01'); await page.locator('[name="referral_reason"]').fill('سبب إحالة اصطناعي'); await capture(page, `referral-${width}`);
      for (let n = 1; n <= 2; n++) {
        await button(page, 'إضافة تشخيص للزيارة');
        await choose(page, `التشخيص من الدليل ${n}`, 'خباثات الاذن', 'خباثات الاذن');
        await choose(page, `العيادة للتشخيص ${n}`, `عيادة التشخيص ${n}`, `عيادة التشخيص ${n}`);
        await choose(page, `الطبيب المسؤول عن التشخيص ${n}`, `الطبيب المسؤول ${n}`, `الطبيب المسؤول ${n}`);
      }
      await page.getByRole('button', { name: 'إضافة تشخيص إلى الدليل', exact: true }).first().click(); await page.getByRole('dialog').waitFor(); await page.getByRole('textbox', { name: 'كود التشخيص الجديد', exact: true }).fill(`WIZ-DX-${f.tag}-${width}`); await page.getByRole('textbox', { name: 'اسم التشخيص الجديد', exact: true }).fill(`تشخيص معالج اصطناعي ${f.tag} ${width}`); await capture(page, `inline-diagnosis-${width}`); await button(page, 'حفظ التشخيص'); await page.getByRole('dialog').waitFor({ state: 'hidden' }); assert.equal(await page.locator('[name="referral_reason"]').inputValue(), 'سبب إحالة اصطناعي');
      await capture(page, `multiple-diagnoses-${width}`);
      const saved = page.waitForResponse(r => r.url().includes(`/dossiers/${d.id}/visits/${d.visit.id}`) && r.request().method() === 'PUT'); await button(page, 'حفظ ومتابعة'); const response = await saved; assert.equal(response.status(), 200, await response.text()); d = (await response.json()).data;
      assert.equal(d.visit.diagnoses.length, 2); assert.equal(d.visit.diagnoses[0].diagnosed_on, null); assert.notEqual(d.visit.diagnoses[0].clinic_id, d.visit.diagnoses[1].clinic_id);
      const before = await api('GET', `/${d.id}`); assert.equal(before.body.data.visit_count, 1); assert.equal(before.body.data.latest_visit.status, 'draft');
      await capture(page, `saved-${width}`); await page.goto(page.url() + '&section=not-a-step'); await page.getByRole('heading', { name: 'المرفقات والمراجعة', exact: true }).waitFor();
      const nav = page.getByRole('list', { name: 'مراحل بطاقة المريض' }); await nav.getByRole('button', { name: /الزيارة والتشخيصات/ }).click(); assert.equal(await page.locator('[name="referral_reason"]').inputValue(), 'سبب إحالة اصطناعي'); await capture(page, `resumed-${width}`);
      await nav.getByRole('button', { name: /المعلومات الطبية والورمية/ }).click(); await page.locator('[name="clinical_history"]').fill('مسودتي المحلية لا تضيع');
      const current = (await api('GET', `/${d.id}/progress`)).body.data;
      const other = await api('PUT', `/${d.id}/medical`, { request_id: crypto.randomUUID(), lock_version: current.lock_version, ...current.medical, is_oncology: true, disability_text: 'تعديل المستخدم الآخر' }); assert.equal(other.status, 200);
      await button(page, 'حفظ ومتابعة'); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor(); assert.equal(await page.locator('[name="clinical_history"]').inputValue(), 'مسودتي المحلية لا تضيع'); await capture(page, `conflict-${width}`);
      await button(page, 'جلب أحدث نسخة'); await page.getByRole('heading', { name: 'مراجعة أحدث نسخة ومسودتك', exact: true }).waitFor(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: القصة المرضية المختصرة', exact: true }).check(); await button(page, 'اعتماد الاختيارات للمراجعة'); assert.equal(await page.locator('[name="disability_text"]').inputValue(), 'تعديل المستخدم الآخر');
      await saveNext(page, `/${d.id}/medical`);
      const final = (await api('GET', `/${d.id}/progress`)).body.data; assert.equal(final.medical.clinical_history, 'مسودتي المحلية لا تضيع'); assert.equal(final.medical.disability_text, 'تعديل المستخدم الآخر'); assert.equal(final.visit.diagnoses.length, 2);
    } finally { await context.close(); }
  }
  completed = true;
});

test('delayed old doctor response cannot replace a newer clinic or a changed facility', async () => {
  const { page, context } = await pageAt(1440);
  try {
    // Delay delivery of an actual response, without mocking API bodies or page.route.
    await context.addInitScript(() => { const original = window.fetch; window.fetch = async (...args) => { const response = await original(...args); if (String(args[0]).includes('/options/doctors?')) await new Promise(r => setTimeout(r, 800)); return response; }; });
    const saved = await api('GET', '', { search: `WIZ-${f.tag}-1440` }); const id = saved.body.data[0].id;
    await page.goto(`${base}/dossiers/${id}/edit?facility_id=${f.facility}&section=2`); await page.getByRole('heading', { name: 'الزيارة والتشخيصات', exact: true }).waitFor();
    await choose(page, 'العيادة للتشخيص 1', 'عيادة التشخيص 2', 'عيادة التشخيص 2');
    const doctors = page.getByRole('group', { name: 'الطبيب المسؤول عن التشخيص 1', exact: true }); await doctors.getByRole('button', { name: /الطبيب المسؤول 2/ }).waitFor(); assert.equal(await doctors.getByRole('button', { name: /الطبيب المسؤول 1/ }).count(), 0);
    await page.goto(`${base}/dossiers/${id}/edit?facility_id=${f.other}`); await page.getByRole('heading', { name: 'تعذّر فتح بطاقة المريض', exact: true }).waitFor(); assert.equal(await page.locator('[name="referral_reason"]').count(), 0);
  } finally { await context.close(); }
});

test('failed refresh, second conflict and saving a previous section never advance another dirty draft silently', async () => {
  const saved = await api('GET', '', { search: `WIZ-${f.tag}-1440` }); const id = saved.body.data[0].id;
  const { page, context } = await pageAt(1440, `/dossiers/${id}/edit`);
  try {
    const nav = page.getByRole('list', { name: 'مراحل بطاقة المريض' });
    await nav.getByRole('button', { name: /البيانات الشخصية/ }).click();
    await page.getByRole('heading', { name: 'البيانات الشخصية', exact: true }).waitFor();
    await nav.getByRole('button', { name: /المعلومات الطبية والورمية/ }).click();
    await page.locator('[name="clinical_history"]').fill('مسودة تنتظر أثناء تعديل القسم السابق');
    await nav.getByRole('button', { name: /البيانات الشخصية/ }).click(); await page.locator('[name="father_name"]').fill('تصحيح اسم الأب');
    let current = (await api('GET', `/${id}/progress`)).body.data;
    assert.equal((await api('PUT', `/${id}/medical`, { ...current.medical, is_oncology: true, disability_text: 'تعديل طبي متزامن جديد', lock_version: current.lock_version, request_id: crypto.randomUUID() })).status, 200);
    await button(page, 'حفظ ومتابعة'); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor();
    await context.setOffline(true); await button(page, 'جلب أحدث نسخة'); await page.getByText('تعذّر جلب أحدث نسخة؛ المسودة باقية. حاول مجددًا.', { exact: true }).waitFor(); assert.equal(await page.locator('[name="father_name"]').inputValue(), 'تصحيح اسم الأب');
    await context.setOffline(false); await button(page, 'جلب أحدث نسخة'); await page.getByRole('heading', { name: 'مراجعة أحدث نسخة ومسودتك', exact: true }).waitFor(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: اسم الأب', exact: true }).check();
    current = (await api('GET', `/${id}/progress`)).body.data;
    assert.equal((await api('PUT', `/${id}/medical`, { ...current.medical, is_oncology: true, previous_examinations: 'تعديل ثانٍ أثناء المراجعة', lock_version: current.lock_version, request_id: crypto.randomUUID() })).status, 200);
    await button(page, 'اعتماد الاختيارات للمراجعة'); await button(page, 'حفظ ومتابعة'); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor();
    await button(page, 'جلب أحدث نسخة'); await page.getByRole('heading', { name: 'مراجعة أحدث نسخة ومسودتك', exact: true }).waitFor(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: اسم الأب', exact: true }).check(); await button(page, 'اعتماد الاختيارات للمراجعة'); await saveNext(page, `/${id}/personal`);
    assert.equal(await page.locator('[name="clinical_history"]').inputValue(), 'مسودة تنتظر أثناء تعديل القسم السابق');
    // Personal save cannot silently rebase the untouched medical draft's version.
    await button(page, 'حفظ ومتابعة'); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor();
    await button(page, 'جلب أحدث نسخة'); await page.getByRole('heading', { name: 'مراجعة أحدث نسخة ومسودتك', exact: true }).waitFor(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: القصة المرضية المختصرة', exact: true }).check(); await button(page, 'اعتماد الاختيارات للمراجعة'); await saveNext(page, `/${id}/medical`);
    const result = (await api('GET', `/${id}/progress`)).body.data; assert.equal(result.medical.previous_examinations, 'تعديل ثانٍ أثناء المراجعة'); assert.equal(result.medical.disability_text, 'تعديل طبي متزامن جديد'); assert.equal(result.medical.clinical_history, 'مسودة تنتظر أثناء تعديل القسم السابق'); assert.equal(result.patient.father_name, 'تصحيح اسم الأب'); assert.equal(result.visit.diagnoses.length, 2);
  } finally { await context.setOffline(false); await context.close(); }
});
