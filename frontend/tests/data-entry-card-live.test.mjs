import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3199';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
let f, browser, card;
function control(mode) {
  const r = spawnSync('php', ['tests/Support/data-entry-card-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  assert.doesNotMatch(r.stdout + r.stderr, /In .+ line|Exception|Undefined array/);
  return r.stdout;
}
before(async () => { control('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/data-entry-card-live.json', import.meta.url))); browser = await chromium.launch({ channel: 'chrome' }); });
after(async () => { await browser?.close(); if (f) control('cleanup'); });
async function api(method, path, body = {}, token = f.clerk_token) {
  const query = method === 'GET' ? `${path.includes('?') ? '&' : '?'}facility_id=${f.facility}` : '';
  const r = await fetch(`${base}/hospital-api/${path}${query}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, ...(method === 'GET' ? {} : { body: JSON.stringify({ facility_id: f.facility, request_id: randomUUID(), ...body }) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers');
  assert.match(r.headers.get('cache-control'), /no-store/);
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function open(path, width = 1440) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.clerk_token);
  const page = await context.newPage(); page.setDefaultTimeout(20000); page.on('dialog', d => d.accept());
  await page.goto(`${base}${path}${path.includes('?') ? '&' : '?'}facility_id=${f.facility}`);
  return { context, page };
}
async function choose(page, label, name) {
  await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
  const group = page.getByRole('group', { name: label, exact: true });
  await group.getByRole('searchbox').fill(name);
  await group.locator('button[aria-pressed]').filter({ hasText: name }).first().click();
}
async function save(page, suffix, name = 'حفظ ومتابعة', status = 200) {
  const response = page.waitForResponse(r => ['PUT', 'POST'].includes(r.request().method()) && r.url().split('?')[0].endsWith(suffix));
  await page.getByRole('button', { name, exact: true }).click();
  const r = await response; assert.equal(r.status(), status, await r.text());
  return (await r.json()).data;
}
const ctx = () => ({ clinic_id: f.clinics[0], doctor_id: f.workflow_doctors[0] });
test('data_entry alone creates a card and saves existing diagnoses, services, procedures, all prescriptions, outcome and photo through the UI', async () => {
  const { context, page } = await open('/patient-cards');
  try {
    await page.getByRole('link', { name: 'تسجيل بطاقة مريض', exact: true }).click();
    await page.getByLabel('الاسم الأول', { exact: true }).fill('مريض');
    await page.getByLabel('اسم العائلة', { exact: true }).fill(`إدخال ${f.tag}`);
    await page.getByLabel('تاريخ فتح البطاقة', { exact: true }).fill('2001-03-01');
    await page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).fill('2001-03-02');
    const registered = await save(page, '/patient-cards/registrations', 'حفظ البطاقة والزيارة الأولى', 201);
    const replay = await api('POST', 'patient-cards/registrations', { request_id: randomUUID(), person_mode: 'existing', patient_id: registered.patient_id, opening_date: '2001-03-01', visit_date: '2001-03-02' });
    assert.equal(replay.status, 409); assert.equal(replay.body.error.existing_dossier_id, registered.id);
    await page.goto(`${base}/patient-cards/new?facility_id=${f.facility}&card=${registered.id}&section=1`);
    await page.locator('[name="is_oncology"]').selectOption('yes');
    await page.locator('[name="clinical_history"]').fill('قصة مسجلة من مدخل البيانات');
    card = await save(page, '/medical');
    await page.getByRole('button', { name: 'إضافة تشخيص للزيارة', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: /إضافة تشخيص.*الدليل/ }).count(), 0);
    await choose(page, 'التشخيص من الدليل 1', f.diagnosis_name);
    await choose(page, 'العيادة للتشخيص 1', f.clinic_name);
    await choose(page, 'الطبيب المسؤول عن التشخيص 1', f.doctor_name);
    card = await save(page, `/visits/${card.visit.id}`);
    for (const [singular, name] of [['الإجراء', f.procedure_name], ['الخدمة', f.service_name]]) {
      await page.getByRole('button', { name: new RegExp(`^إضافة ${singular} للزيارة$`) }).click();
      await choose(page, `${singular} من الدليل 1`, name);
      await choose(page, `العيادة · ${singular} 1`, f.clinic_name);
      await choose(page, `الطبيب المسؤول · ${singular} 1`, f.doctor_name);
    }
    card = await save(page, '/clinical');
    let linked = 0;
    for (const kind of ['unlinked', 'dose_linked', 'dose_linked', 'dose_linked', 'outside']) {
      if(kind==='dose_linked')await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
      if(kind==='outside')await page.getByRole('tab',{name:'أدوية خارج المشفى',exact:true}).click();
      await page.getByRole('button', { name: 'إضافة فاتورة', exact: true }).click();
      await choose(page, 'العيادة · الوصفة', f.clinic_name);
      await choose(page, 'الطبيب المسؤول · الوصفة', f.doctor_name);
      if (kind === 'dose_linked') {
        await page.locator('[name="prescription.funding_source_id"] option', {hasText:f.fundings[linked].name_ar}).waitFor({state:'attached'});
        await page.locator('[name="prescription.funding_source_id"]').selectOption(String(f.fundings[linked++].id));
      }
      if (kind === 'outside') await page.locator('[name="prescription.unavailable_reason"]').fill('غير متوفر في المشفى');
      await page.getByRole('button', { name: 'إضافة دواء للوصفة', exact: true }).click();
      assert.equal(await page.getByRole('button', { name: 'إضافة دواء إلى الدليل', exact: true }).count(), 0);
      await choose(page, 'الدواء من الدليل 1', kind==='dose_linked'?f.invoice_medications[linked-1].name_ar:f.medication_name);
      if(kind==='dose_linked')await page.locator('[name="prescription.prescribed_on"]').fill(`2001-03-0${linked}`);
      await page.locator('[name="prescription.items.0.note"]').fill(`Medication ${kind} ${linked}`);
      await page.locator('[name="prescription.note"]').fill(`Invoice ${kind} ${linked}`);
      card = await save(page, '/medications');
    }
    const invoicesBefore=structuredClone(card.clinical.prescriptions.filter(p=>p.kind==='dose_linked'));
    assert.equal(invoicesBefore.length,3);
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    await page.getByRole('button',{name:'إضافة فاتورة',exact:true}).click();
    await page.locator('[name="prescription.note"]').fill('Unfinished fourth invoice');
    await page.getByRole('button',{name:new RegExp(`^فاتورة 2 · #${invoicesBefore[1].id}$`)}).click();
    await page.locator('[name="prescription.note"]').fill('Only second invoice edited');
    card=await save(page,'/medications');
    const invoicesAfter=card.clinical.prescriptions.filter(p=>p.kind==='dose_linked');
    assert.deepEqual(invoicesAfter[0],invoicesBefore[0]);assert.deepEqual(invoicesAfter[2],invoicesBefore[2]);
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    await page.getByRole('button',{name:'فاتورة 4 · مسودة',exact:true}).click();
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'Unfinished fourth invoice');
    await page.getByRole('button',{name:'إزالة الفاتورة',exact:true}).click();
    await page.getByRole('tab',{name:'النتيجة',exact:true}).click();
    await page.getByRole('button', { name: 'تسجيل نتيجة الزيارة', exact: true }).click();
    await page.locator('[name="outcome.code"]').selectOption('DOS-NORX');
    await choose(page, 'العيادة · نتيجة الزيارة', f.clinic_name);
    await choose(page, 'الطبيب المسؤول · نتيجة الزيارة', f.doctor_name);
    card = await save(page, '/medications');
    await page.locator('input[type="file"]').setInputFiles({ name: 'synthetic.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV4sAAAAASUVORK5CYII=', 'base64') });
    await page.getByRole('button', { name: 'رفع الملف', exact: true }).click();
    await page.getByText('تم حفظ الملف', { exact: true }).waitFor();
    const pending = page.waitForEvent('download');
    await page.getByRole('button', { name: 'تنزيل', exact: true }).click();
    assert.equal((await pending).suggestedFilename(), 'synthetic.png');
    card = (await api('GET', `dossiers/${card.id}/visits/${card.visit.id}/progress`)).body.data;
    assert.equal(card.visit.diagnoses[0].diagnosis_id, f.diagnosis);
    assert.equal(card.clinical.services[0].catalog_id, f.service);
    assert.equal(card.clinical.procedures[0].catalog_id, f.procedure);
    assert.equal(card.clinical.prescriptions.length, 5);
    assert.deepEqual(card.clinical.prescriptions.filter(p=>p.kind==='dose_linked').map(p=>p.funding_source_id),f.fundings.map(s=>s.id));
    assert.deepEqual(card.clinical.prescriptions.filter(p=>p.kind==='dose_linked').map(p=>p.items[0].medication_id),f.invoice_medications.map(s=>s.id));
    await page.goto(`${base}/patient-cards/new?facility_id=${f.facility}&card=${card.id}&visit=${card.visit.id}&section=4`);
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    for (const [i,invoice] of card.clinical.prescriptions.filter(p=>p.kind==='dose_linked').entries()) {
      await page.getByRole('button',{name:`فاتورة ${i+1} · #${invoice.id}`,exact:true}).click();
      assert.equal(await page.locator('[name="prescription.funding_source_id"]').inputValue(),String(invoice.funding_source_id));
      assert.equal(await page.locator('[name="prescription.note"]').inputValue(),invoice.note);
      assert.equal(await page.locator('[name="prescription.items.0.note"]').inputValue(),invoice.items[0].note);
    }
    assert.equal(card.clinical.attachment_count, 1);
  } finally { await context.close(); }
});

test('invoice conflict review uses invoice IDs and keeps new same-kind drafts separate from concurrent invoices', async () => {
  const {context,page}=await open(`/patient-cards/new?card=${card.id}&visit=${card.visit.id}&section=4`);
  try {
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    const rows=card.clinical.prescriptions.filter(p=>p.kind==='dose_linked');
    await page.getByRole('button',{name:`فاتورة 1 · #${rows[0].id}`,exact:true}).click();
    await page.locator('[name="prescription.note"]').fill('My reviewed invoice edit');
    let result=await api('PUT',`dossiers/${card.id}/visits/${card.visit.id}/medications`,{lock_version:card.visit.lock_version,prescription:{...rows[1],note:'Concurrent second invoice'},outcome:null});
    assert.equal(result.status,200);card=result.body.data;
    await save(page,'/medications','حفظ ومتابعة',409);
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'My reviewed invoice edit');
    await page.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    await page.getByRole('checkbox',{name:`تطبيق مسودتي: أدوية مرتبطة بالجرعة:${rows[0].id} · الملاحظة`,exact:true}).check();
    await page.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();
    card=await save(page,'/medications');
    assert.equal(card.clinical.prescriptions.find(p=>p.id===rows[0].id).note,'My reviewed invoice edit');
    assert.equal(card.clinical.prescriptions.find(p=>p.id===rows[1].id).note,'Concurrent second invoice');
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    await page.getByRole('button',{name:'إضافة فاتورة',exact:true}).click();
    await choose(page,'العيادة · الوصفة',f.clinic_name);await choose(page,'الطبيب المسؤول · الوصفة',f.doctor_name);
    await page.locator('[name="prescription.funding_source_id"]').selectOption(String(f.funding));
    await page.locator('[name="prescription.note"]').fill('My separate new invoice');
    await page.getByRole('button',{name:'إضافة دواء للوصفة',exact:true}).click();await choose(page,'الدواء من الدليل 1',f.medication_name);
    const concurrent={kind:'dose_linked',prescribing_clinic_id:f.clinics[0],prescribing_staff_id:f.workflow_doctors[0],prescribed_on:'2001-03-02',funding_source_id:f.funding,note:'Concurrent separate new invoice',items:[{medication_id:f.medication,display_order:0}]};
    result=await api('PUT',`dossiers/${card.id}/visits/${card.visit.id}/medications`,{lock_version:card.visit.lock_version,prescription:concurrent,outcome:null});assert.equal(result.status,200);
    await save(page,'/medications','حفظ ومتابعة',409);await page.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    await page.getByRole('checkbox',{name:/تطبيق مسودتي: إضافة أدوية مرتبطة بالجرعة:.*الجديدة من مسودتي/}).check();
    await page.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'My separate new invoice');
    card=await save(page,'/medications');
    const invoices=card.clinical.prescriptions.filter(p=>p.kind==='dose_linked');
    assert.equal(invoices.length,5);
    assert.ok(invoices.some(p=>p.note==='Concurrent separate new invoice'));
    assert.ok(invoices.some(p=>p.note==='My separate new invoice'));
    assert.equal(new Set(invoices.map(p=>p.id)).size,5);
  } finally {await context.close();}
});

test('funding empty/error states are explicit and retry preserves the invoice draft (UI fault injection only)', async () => {
  const {context,page}=await open(`/patient-cards/new?card=${card.id}&visit=${card.visit.id}&section=4`);
  try {
    let mode='empty';
    await page.route('**/hospital-api/dossiers/prescription-funding?*',route=>mode==='empty'?route.fulfill({json:{data:{funding_sources:[]}}}):mode==='error'?route.fulfill({status:503,json:{error:{code:'LOCAL_TEST_UNAVAILABLE',message:'تعذر تحميل بيانات الاختبار'}}}):route.continue());
    await page.getByRole('tab',{name:'أدوية مرتبطة بالجرعة',exact:true}).click();
    await page.getByText(/لا توجد مصادر تمويل فعالة/).waitFor();
    await page.locator('[name="prescription.note"]').fill('Draft survives funding failures');
    mode='error';await page.getByRole('button',{name:'إعادة تحميل التمويل',exact:true}).click();
    await page.getByRole('alert').filter({hasText:'تعذّر تحميل مصادر التمويل'}).waitFor();
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'Draft survives funding failures');
    mode='real';await page.getByRole('button',{name:'إعادة تحميل التمويل',exact:true}).click();
    await page.locator('[name="prescription.funding_source_id"] option').filter({hasText:f.fundings[0].name_ar}).waitFor({state:'attached'});
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'Draft survives funding failures');
  } finally {await context.close();}
});

test('directory creation stays absent on mobile/tablet/desktop and the remaining card sections expose their input actions', async () => {
  for (const width of [390, 768, 1440]) {
    const { context, page } = await open('/clinics', width);
    try {
      await page.getByRole('heading', { name: 'العيادات', exact: true }).waitFor();
      await page.waitForLoadState('networkidle');
      assert.equal(await page.getByRole('button', { name: /إضافة عيادة/ }).count(), 0);
      await page.goto(`${base}/doctors?facility_id=${f.facility}`);
      await page.getByRole('region', { name: 'جدول الأطباء' }).waitFor();
      await page.waitForLoadState('networkidle');
      assert.equal(await page.getByRole('button', { name: /إضافة طبيب/ }).count(), 0);
      await page.goto(`${base}/services-procedures?facility_id=${f.facility}`);
      await page.getByRole('heading', { name: 'الخدمات والإجراءات', exact: true, level: 2 }).waitFor();
      await page.waitForLoadState('networkidle');
      assert.equal(await page.getByRole('button', { name: /إضافة خدمة|إضافة إجراء|إضافة دواء|إضافة فئة/ }).count(), 0);
      await page.goto(`${base}/medications?facility_id=${f.facility}`);
      await page.getByRole('heading', { name: 'الأدوية', exact: true, level: 2 }).waitFor();
      await page.waitForLoadState('networkidle');
      assert.equal(await page.getByRole('button', { name: /إضافة دواء|إضافة فئة/ }).count(), 0);
      for (const [section, label] of [[6, 'إضافة تقرير تشريح مرضي'], [7, 'إضافة خطة علاجية'], [8, 'تسجيل إعطاء جرعة']]) {
        await page.goto(`${base}/patient-cards/new?facility_id=${f.facility}&card=${card.id}&visit=${card.visit.id}&section=${section}`);
        if (section !== 8) await page.getByRole('button', { name: label, exact: true }).waitFor();
        else await page.getByRole('region', { name: 'العلاج الفعلي للزيارة', exact: true }).waitFor();
      }
    } finally { await context.close(); }
  }
});

test('assessment, pathology, scheduling, actual treatment/dispensing and completion use the same real card with no missing entitlement', async () => {
  const basePath = `dossiers/${card.id}`, visitPath = `${basePath}/visits/${card.visit.id}`;
  let r = await api('POST', `${visitPath}/pathology`, { source: 'external', external_organization: 'مختبر اصطناعي', status: 'completed', result_on: '2001-03-01', conclusion: 'تقرير موجود' , ...ctx() });
  assert.equal(r.status, 201, JSON.stringify(r.body));
  assert.equal((await api('PUT', `${visitPath}/diagnostic-assessment`, { lock_version: 0, disposition: 'pathology_confirmed', evidence_pathology_id: r.body.data.id, assessed_on: '2001-03-02' })).status, 200);
  r = await api('POST', `${basePath}/treatment-plans`, { modality: 'chemotherapy', intent: 'curative', protocol_text: 'بروتوكول مدخل بيانات', protocol_clinic_id: f.clinics[0], protocol_doctor_id: f.workflow_doctors[0], treating_clinic_id: f.clinics[0], treating_doctor_id: f.workflow_doctors[0] });
  assert.equal(r.status, 201, JSON.stringify(r.body)); const plan = r.body.data;
  assert.equal((await api('POST', `${basePath}/treatment-plans/${plan.id}/sessions`, { lock_version: plan.lock_version, sessions: [{ planned_on: '2001-03-02' }] })).status, 201);
  const session = (await api('GET', `${basePath}/treatment-sessions`)).body.data[0];
  assert.equal((await api('POST', `${basePath}/treatment-sessions/${session.id}/session-doses`, { given_on: '2001-03-02', dose_name: 'جرعة محفوظة', complaint: 'مسجلة', recommendations: 'مسجلة', nurse_id: f.workflow_doctors[0], medication_source: 'ministry_of_health' })).status, 201);
  assert.equal((await api('POST', `${basePath}/treatment-sessions`, { planned_on: '2001-03-02', ...ctx() })).status, 201);
  const currentPlan = (await api('GET', `${basePath}/treatment-plans/${plan.id}`)).body.data;
  const item = { medication_id: f.medication, medication_source: 'ministry_of_health', quantity: '1.5', quantity_unit: 'vial' };
  r = await api('POST', `${visitPath}/doses`, { session_id: session.id, session_lock_version: session.lock_version, plan_lock_version: currentPlan.lock_version, visit_lock_version: card.visit.lock_version, administered_on: '2001-03-02', supervising_staff_id: f.workflow_doctors[0], administered_by: f.workflow_doctors[0], items: [{ ...item, dose_value: '2.5', dose_unit: 'mg', route: 'IV' }] });
  assert.equal(r.status, 201, JSON.stringify(r.body));
  assert.equal((await api('POST', `${visitPath}/dispensing`, { ...item, dispensed_on: '2001-03-02', prescribing_clinic_id: f.clinics[0], prescribing_staff_id: f.workflow_doctors[0], dispensing_purpose: 'take_home' })).status, 201);
  r = await api('POST', `${visitPath}/review`, { lock_version: card.visit.lock_version, confirmed: true });
  assert.equal(r.status, 200, JSON.stringify(r.body)); card = r.body.data;
  r = await api('POST', `${visitPath}/complete`, { lock_version: card.visit.lock_version, dossier_lock_version: card.lock_version, confirmed: true, clinic_id: f.clinics[0], attending_staff_id: f.workflow_doctors[0] });
  assert.equal(r.status, 200, JSON.stringify(r.body)); assert.equal(r.body.data.visit.status, 'complete'); assert.equal(r.body.data.status, 'active');
  assert.equal((await api('DELETE', basePath)).status, 403);
  assert.equal((await api('POST', `${basePath}/report/xlsx`)).status, 403);
  r = await api('POST', `${basePath}/visits/subsequent`, { visit_date: '2001-03-03', is_referred: false, diagnoses: [{ diagnosis_id: f.diagnosis, clinic_id: f.clinics[0], diagnosing_staff_id: f.workflow_doctors[0] }] });
  assert.equal(r.status, 201, JSON.stringify(r.body)); assert.equal(r.body.data.visit.status, 'draft');
});

test('direct directory writes are 403 without inserts; the explicitly authorized creator still succeeds through Next', async () => {
  const requests = [
    ['clinics', { name_ar: `عيادة-${f.tag}`, is_active: true }],
    ['doctors', { name: `طبيب-${f.tag}`, is_active: true, staff_type_id: f.staff_type, specialty_ids: [] }],
    ['service-catalog', { kind: 'service', name_ar: `خدمة-${f.tag}`, category_id: f.category, is_active: true }],
    ['service-catalog', { kind: 'procedure', name_ar: `إجراء-${f.tag}`, is_active: true }],
    ['service-catalog', { kind: 'medication', name_ar: `دواء-${f.tag}`, is_active: true }],
    ['service-catalog/categories', { name_ar: `فئة-${f.tag}`, is_active: true }],
    ['dossiers/diagnoses', { name_ar: `تشخيص-${f.tag}` }],
    ['dossiers/medications', { name_ar: `دواء سريع-${f.tag}` }],
  ];
  const before = JSON.parse(control('counts'));
  for (const [path, body] of requests) assert.equal((await api('POST', path, body)).status, 403, path);
  assert.deepEqual(JSON.parse(control('counts')), before);
  for (const [path, body] of requests) { const r = await api('POST', path, body, f.creator_token); assert.equal(r.status, 201, `${path}: ${JSON.stringify(r.body)}`); }
});
