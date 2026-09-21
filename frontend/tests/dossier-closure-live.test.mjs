import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194'; let f, browser, dossier;
const gallery = new URL('../.superdesign/tmp/patient-card-regressions/dossiers-phase-four/', import.meta.url);
function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/dossier-closure-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: process.env, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr); process.stdout.write(r.stdout);
}
async function api(method, path, data = {}, token = f.token) {
  const fields = { facility_id: f.facility, ...data };
  const r = await fetch(`${base}/hospital-api/dossiers${path}${method === 'GET' ? '?' + new URLSearchParams(fields) : ''}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` }, ...(method === 'GET' ? {} : { body: JSON.stringify(fields) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers'); assert.match(r.headers.get('cache-control'), /no-store/);
  return { status: r.status, body: await r.json() };
}
async function write(method, path, data) { const r = await api(method, path, { request_id: crypto.randomUUID(), ...data }); assert.ok([200, 201].includes(r.status), JSON.stringify(r.body)); return r.body.data; }
before(async () => {
  fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-closure-live.json', import.meta.url)));
  browser = await chromium.launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{}); await mkdir(gallery, { recursive: true });
  dossier = await write('POST', '', { person_mode: 'new', code: `PH4-${f.tag}`, opening_date: '2001-01-01', visit_date: '2001-03-02', first_name: 'ليلى', family_name: 'مراجعة اصطناعية', birth_date_accuracy: 'unknown', gender: 'female', displacement_status: 'unknown' });
  dossier = await write('PUT', `/${dossier.id}/medical`, { lock_version: dossier.lock_version, is_oncology: false, clinical_history: 'قصة مرضية اصطناعية قبل التصحيح' });
  for (let i = 0; i < 12; i++) dossier = await write('PUT', `/${dossier.id}/medical`, { lock_version: dossier.lock_version, is_oncology: false, clinical_history: `قصة مرضية اصطناعية بعد التصحيح ${i + 1}` });
  dossier = await write('PUT', `/${dossier.id}/visits/${dossier.visit.id}`, { lock_version: dossier.visit.lock_version, visit_date: '2001-03-02', is_referred: false, diagnoses: [{ diagnosis_id: f.diagnosis, diagnosed_on: null, clinic_id: f.clinics[0], diagnosing_staff_id: f.workflow_doctors[0] }] });
  const path = `/${dossier.id}/visits/${dossier.visit.id}`;
  dossier = await write('PUT', path + '/clinical', { lock_version: dossier.visit.lock_version, services: [{ catalog_id: f.service, clinic_id: f.clinics[0], doctor_id: f.workflow_doctors[0], note: '=ملاحظة اصطناعية آمنة' }], procedures: [] });
  const row = dossier.clinical.services[0];
  dossier = await write('PUT', path + '/clinical', { lock_version: dossier.visit.lock_version, services: [{ ...row, remove: true, void_reason: 'خدمة أضيفت بالخطأ — اختبار تاريخي' }], procedures: [] });
  dossier = await write('PUT', path + '/medications', { lock_version: dossier.visit.lock_version, prescription: { prescribing_clinic_id: f.clinics[0], prescribing_staff_id: f.workflow_doctors[0], prescribed_on: '2001-03-02', items: [{ medication_id: f.medication, display_order: 0, note: 'وصفة اصطناعية وليست صرفًا' }] }, outcome: { code: 'DOS-RX', outcome_on: '2001-03-02', clinic_id: f.clinics[0], doctor_id: f.workflow_doctors[0] } });
});
after(async () => { await browser?.close(); if (f) fixture('cleanup'); });
async function open(width, token = f.token, delay = false) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(({ token, delay }) => {
    sessionStorage.setItem('hospital.bearer', token);
    if (delay) { const real = window.fetch; window.fetch = async (...args) => { const response = await real(...args); if (String(args[0]).includes('/audit?') && new URL(String(args[0]), location.origin).searchParams.get('entity') === 'dossier_medical') await new Promise(r => setTimeout(r, 1600)); return response; }; }
  }, { token, delay });
  const page = await context.newPage(); page.setDefaultTimeout(20000);
  await page.goto(`${base}/patient-cards/${dossier.id}?facility_id=${f.facility}`);
  return { context, page };
}

test('real audit boundary protects both permissions and explicit scope with no generic Next rewrite', async () => {
  const path = `/${dossier.id}/audit`;
  const result = await api('GET', path); assert.equal(result.status, 200); assert.ok(result.body.meta.total > 20);
  for (const [data, token, code] of [[{}, '', 401], [{}, f.view_token, 403], [{ facility_id: f.other }, f.token, 403], [{ visit_id: f.latest_visit }, f.token, 404]]) {
    const r = await api('GET', path, data, token); assert.equal(r.status, code); assert.equal(r.body.meta, undefined); assert.equal(r.body.data, undefined);
  }
  for (const path of ['/abc/audit', `/${dossier.id}/arbitrary`]) { const r = await fetch(`${base}/hospital-api/dossiers${path}`); assert.equal(r.status, 404); assert.equal(r.headers.get('x-test-laravel'), null); }
  const { context, page } = await open(390, f.view_token);
  try { await page.getByRole('button', { name: 'سجل التغييرات', exact: true }).waitFor(); assert.equal(await page.getByRole('button', { name: 'سجل التغييرات', exact: true }).isDisabled(), true); assert.equal(await page.locator('#dossier-change-history').count(), 0); } finally { await context.close(); }
});

test('Arabic history, old/new values, actual visit filter and pagination at 390 768 1440', async () => {
  for (const width of [390, 768, 1440]) {
    const { context, page } = await open(width);
    try {
      const section = page.locator('#dossier-change-history'); await section.getByRole('article').first().waitFor();
      await page.getByRole('link', { name: 'سجل التغييرات', exact: true }).click();
      await section.getByLabel('القسم', { exact: true }).selectOption('dossier_medical'); await section.getByText('قصة مرضية اصطناعية بعد التصحيح 12', { exact: true }).first().waitFor();
      assert.equal(await section.getByRole('article').count(), 10);
      await section.getByRole('button', { name: 'التالي', exact: true }).click(); await section.getByText('صفحة 2 من 2', { exact: true }).waitFor();
      assert.equal(await section.getByRole('article').count(), 3);
      await section.getByLabel('القسم', { exact: true }).selectOption('visit_services'); await section.getByText('خدمة أضيفت بالخطأ — اختبار تاريخي', { exact: false }).first().waitFor();
      await section.getByRole('button', { name: 'تغييرات هذه الزيارة', exact: true }).first().click(); await section.getByRole('button', { name: 'عرض تغييرات جميع الزيارات', exact: true }).waitFor();
      assert.equal(new URL(page.url()).searchParams.get('audit_visit_id'), String(dossier.visit.id));
      await section.getByRole('button', { name: 'عرض تغييرات جميع الزيارات', exact: true }).click();
      await section.getByLabel('القسم', { exact: true }).selectOption(''); await section.getByRole('article').nth(9).waitFor();
      await page.evaluate(async () => { await document.fonts.ready; });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      assert.equal(await page.evaluate(() => { const c = document.querySelector('#main-content > div'); return c.scrollWidth > c.clientWidth + 1; }), false);
      await page.screenshot({ path: fileURLToPath(new URL(`history-${width}.jpg`, gallery)), type: 'jpeg', quality: 82, fullPage: true });
      await section.evaluate(el => window.scrollTo({ top: el.getBoundingClientRect().top + window.scrollY - 96, behavior: 'instant' })); await page.screenshot({ path: fileURLToPath(new URL(`history-${width}-viewport.jpg`, gallery)), type: 'jpeg', quality: 85 });
    } finally { await context.close(); }
  }
});

test('delayed real responses cannot replace a newer filter or resurrect an old dossier context; failure retries preserve URL', async () => {
  const { context, page } = await open(768, f.token, true);
  try {
    const section = page.locator('#dossier-change-history'); await section.getByRole('article').first().waitFor();
    await section.getByLabel('القسم', { exact: true }).selectOption('dossier_medical');
    await page.waitForTimeout(150);
    await section.getByLabel('القسم', { exact: true }).selectOption('visit_services');
    await section.getByRole('article', { name: 'الخدمة — إلغاء', exact: true }).waitFor();
    await page.waitForTimeout(1800);
    assert.equal(await section.getByRole('article', { name: /المعلومات الطبية/ }).count(), 0);
    await page.evaluate(() => { const q = new URLSearchParams(location.search); q.set('audit_from', '2020-02-03'); q.set('audit_to', '2020-02-02'); history.pushState(null, '', location.pathname + '?' + q); });
    await section.getByRole('alert').waitFor(); assert.equal(await section.getByRole('article').count(), 0);
    await section.getByLabel('تاريخ التغيير من', { exact: true }).fill(''); await section.getByLabel('تاريخ التغيير إلى', { exact: true }).fill('');
    await section.getByRole('article').first().waitFor();
    await section.getByLabel('القسم', { exact: true }).selectOption('dossier_medical');
    await page.goto(`${base}/patient-cards/${f.dossiers[0]}?facility_id=${f.facility}`);
    await page.locator('#dossier-change-history').getByText('لا توجد تغييرات مسجلة تطابق هذه الفلاتر.', { exact: true }).waitFor();
    await page.waitForTimeout(1800); assert.equal(await page.locator('#dossier-change-history article').count(), 0);
  } finally { await context.close(); }
});

test('existing full-history PDF and XLSX download through Next with actual date range and void reasons', async () => {
  const { context, page } = await open(1440);
  try {
    const report = page.getByRole('region', { name: 'تقرير بطاقة المريض', exact: true });
    await report.getByLabel('تقرير الزيارات من', { exact: true }).fill('2001-03-02');
    await report.getByLabel('تقرير الزيارات إلى', { exact: true }).fill('2001-03-02');
    for (const [format, label] of [['xlsx', 'تصدير Excel'], ['pdf', 'تصدير PDF']]) {
      const response = page.waitForResponse(r => r.url().includes(`/${dossier.id}/report/${format}`)); const downloaded = page.waitForEvent('download');
      await report.getByRole('button', { name: label, exact: true }).click();
      const r = await response; assert.equal(r.status(), 200); assert.equal(r.headers()['x-test-laravel'], 'dossiers');
      const body = r.request().postDataJSON(); assert.equal(body.from, '2001-03-02'); assert.equal(body.to, '2001-03-02');
      const d = await downloaded; await d.saveAs(fileURLToPath(new URL(`full-history.${format}`, gallery)));
    }
    await writeFile(new URL('sample-scope.txt', gallery), 'Synthetic dossier and visit only. Actual visit date: 2001-03-02. Includes a voided service and independent prescription. No attachment binaries or private keys.\n');
  } finally { await context.close(); }
});
