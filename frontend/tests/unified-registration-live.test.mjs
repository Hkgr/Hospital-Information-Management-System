import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.UNIFIED_TEST_URL || 'http://127.0.0.1:3198';
assert.ok(['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname), 'integration requires an isolated local server');
let browser, fixture, card;
const tokens = new Map();
function control(mode) {
  const r = spawnSync('php', ['tests/Support/unified-registration-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => {
  control('prepare');
  fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/unified-registration-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
  mkdirSync('test-results/unified', { recursive: true });
});
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body) {
  const r = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers', 'request reached the guarded real Laravel server through Next');
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function open(kind, path, width = 1440) {
  const response = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(response.status, 200);
  const token = response.body.data.token;
  tokens.set(kind, token);
  const context = await browser.newContext({ viewport: { width, height: 960 }, reducedMotion: 'reduce' });
  await context.addInitScript(t => { if (!sessionStorage.getItem('fixture-loaded')) { sessionStorage.setItem('hospital.bearer', t); sessionStorage.setItem('fixture-loaded', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  await page.goto(`${base}${path}`);
  return { page, context, token };
}

test('real basic registration, explicit visit date, retained draft, no medical access and same-record medical handoff', async () => {
  const f = await open('clerk', `/reception?facility_id=${fixture.facilities[0]}`);
  try {
    await f.page.waitForURL(/\/patient-cards\?/);
    assert.equal(await f.page.getByRole('link', { name: 'الاستقبال', exact: true }).count(), 0);
    await f.page.getByRole('searchbox').fill(`مريض تدريب ${fixture.tag}`);
    await f.page.getByText('لا توجد نتائج مطابقة. راجع الاسم والمعرّف قبل إنشاء مريض جديد.').waitFor();
    await f.page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
    assert.equal(await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).inputValue(), '');
    await f.page.getByLabel('الاسم الأول', { exact: true }).fill('مريض تدريب');
    await f.page.getByLabel('اسم العائلة', { exact: true }).fill(fixture.tag);
    const failed = f.page.waitForResponse(r => r.url().includes('/reception/registrations') && r.status() === 422);
    await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    await failed;
    assert.equal(await f.page.getByLabel('الاسم الأول', { exact: true }).inputValue(), 'مريض تدريب');
    await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).fill('2020-03-04');
    const saved = f.page.waitForResponse(r => r.url().includes('/reception/registrations') && r.status() === 201);
    await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    card = (await (await saved).json()).data;
    assert.equal(card.workflow, null);
    assert.ok(card.registration_visit_id);
    await f.page.getByRole('region', { name: 'البيانات الأساسية للبطاقة' }).waitFor();
    assert.equal(await f.page.getByRole('link', { name: 'فتح مساحة العمل الطبية' }).count(), 0);
    for (const [method, path] of [['GET', `dossiers/${card.id}`], ['GET', `dossiers/${card.id}/visits/${card.registration_visit_id}`], ['POST', `dossiers/${card.id}/report/xlsx`], ['DELETE', `dossiers/${card.id}`]]) assert.equal((await api(f.token, `${path}?facility_id=${fixture.facilities[0]}`, method)).status, 403);
    assert.equal((await api(f.token, `reception/cards/${card.id}?facility_id=${fixture.facilities[1]}`)).status, 403);
    const med = await open('medical', `/patient-cards/${card.id}?facility_id=${fixture.facilities[0]}&view=registration`);
    try {
      await med.page.getByRole('link', { name: 'استكمال المسودة المحفوظة' }).click();
      await med.page.waitForURL(new RegExp(`card=${card.id}`));
      const progress = await api(med.token, `dossiers/${card.id}/progress?facility_id=${fixture.facilities[0]}`);
      assert.equal(progress.status, 200);
      assert.equal(progress.body.data.code, card.code);
      assert.equal(progress.body.data.visit.id, card.registration_visit_id);
      const updated = await api(med.token, `dossiers/${card.id}/medical`, 'PUT', { facility_id: fixture.facilities[0], request_id: randomUUID(), lock_version: progress.body.data.lock_version, is_oncology: false });
      assert.equal(updated.status, 200);
      assert.equal(updated.body.data.visit.id, card.registration_visit_id);
      assert.equal(updated.body.data.patient.id, card.patient_id);
    } finally { await med.context.close(); }
    control('verify');
  } finally { await f.context.close(); }
});

test('real duplicate-name hint requires review; selecting the saved identity opens its existing card', async () => {
  const f = await open('clerk', `/patient-cards/new?facility_id=${fixture.facilities[0]}&search=${encodeURIComponent(`مريض تدريب ${fixture.tag}`)}`);
  try {
    await f.page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
    await f.page.getByLabel('الاسم الأول', { exact: true }).fill('مريض تدريب');
    await f.page.getByLabel('اسم العائلة', { exact: true }).fill(fixture.tag);
    await f.page.getByRole('region', { name: 'مراجعة تشابه الأسماء' }).waitFor();
    assert.equal(await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى' }).isDisabled(), true);
    await f.page.getByRole('region', { name: 'مراجعة تشابه الأسماء' }).getByRole('button', { name: 'فتح البطاقة' }).click();
    await f.page.waitForURL(new RegExp(`/patient-cards/${card.id}`));
    await f.page.getByRole('region', { name: 'البيانات الأساسية للبطاقة' }).waitFor();
  } finally { await f.context.close(); }
});

test('real granular service-only workspace omits procedure and medication controls; forbidden payload remains denied', async () => {
  const f = await open('service', `/patient-cards/new?facility_id=${fixture.facilities[0]}&card=${card.id}&section=3`);
  try {
    await f.page.getByRole('button', { name: 'إضافة الخدمة للزيارة', exact: true }).waitFor();
    assert.equal(await f.page.getByRole('button', { name: 'إضافة الإجراء للزيارة', exact: true }).count(), 0);
    const before = await api(f.token, `dossiers/${card.id}/progress?facility_id=${fixture.facilities[0]}`);
    const payload = { facility_id: fixture.facilities[0], request_id: randomUUID(), lock_version: before.body.data.visit.lock_version, services: [], procedures: [], unchanged: true };
    const response = await api(f.token, `dossiers/${card.id}/visits/${card.registration_visit_id}/clinical`, 'PUT', payload);
    assert.equal(response.status, 200);
    assert.equal((await api(f.token, `dossiers/${card.id}/report/xlsx?facility_id=${fixture.facilities[0]}`, 'POST')).status, 403);
  } finally { await f.context.close(); }
});

test('real task templates, explicit prerequisites, scope and no privilege escalation through Next role routes', async () => {
  const f = await open('manager', `/users?facility_id=${fixture.facilities[0]}`);
  try {
    await f.page.getByRole('tab', { name: 'الأدوار والصلاحيات' }).click();
    await f.page.getByRole('button', { name: 'إضافة دور', exact: true }).click();
    const dialog = f.page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'معاينة التسجيل والبيانات الأساسية' }).click();
    assert.equal(await dialog.getByRole('checkbox', { checked: true }).count(), 0);
    await dialog.getByRole('button', { name: 'إلغاء المعاينة' }).click();
    await dialog.getByLabel('البحث في الصلاحيات').fill('خدمات الزيارة');
    await dialog.getByRole('checkbox').check();
    await dialog.getByRole('heading', { name: 'متطلبات تحتاج اختيارًا صريحًا' }).waitFor();
    assert.equal(await dialog.getByRole('button', { name: 'إنشاء الدور', exact: true }).isDisabled(), true);
    await dialog.getByRole('button', { name: 'إضافة عرض التاريخ الطبي للبطاقة', exact: true }).click();
    await dialog.getByRole('button', { name: 'إضافة عرض الزيارات المحفوظة', exact: true }).click();
    await dialog.getByLabel('اسم الدور *').fill(`دور خدمات محدود ${fixture.tag}`);
    const saved = f.page.waitForResponse(r => r.url().includes('/users/roles') && r.request().method() === 'POST');
    await dialog.getByRole('button', { name: 'إنشاء الدور', exact: true }).click();
    const response = await saved; assert.equal(response.status(), 201);
    const role = (await response.json()).data;
    assert.deepEqual(role.permissions.map(p => p.code).sort(), ['dossiers.medical.view', 'dossiers.services.update', 'dossiers.visits.view']);
    assert.equal((await api(tokens.get('clerk'), 'users/roles', 'POST', { facility_id: fixture.facilities[0], name_ar: 'غير مسموح', permission_ids: role.permissions.map(p => p.id) })).status, 403);
    assert.equal((await api(f.token, 'users/roles', 'POST', { facility_id: fixture.facilities[1], name_ar: 'خارج المشفى', permission_ids: role.permissions.map(p => p.id) })).status, 403);
  } finally { await f.context.close(); }
});

for (const width of [390, 768, 1440]) test(`real illustrated guide, registration and permission editor at ${width}px with keyboard`, async () => {
  const f = await open('manager', `/guide?facility_id=${fixture.facilities[0]}`, width);
  try {
    await f.page.getByRole('heading', { name: 'ماذا تريد أن تفعل؟' }).waitFor();
    await f.page.evaluate(() => document.fonts.ready);
    // Wait for the real shell transition, including tablet hydration, before visual review.
    await f.page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running'));
    const frame = await f.page.evaluate(() => {
      const sidebar = document.querySelector('aside');
      return { main: document.querySelector('main').getBoundingClientRect().right, sidebar: sidebar?.getBoundingClientRect().left, visible: sidebar && getComputedStyle(sidebar).display !== 'none' };
    });
    if (frame.visible) assert.ok(frame.main <= frame.sidebar + 1, 'content remains outside the sidebar');
    assert.ok(await f.page.getByRole('heading', { name: 'بطاقة واحدة… وزيارات متعددة' }).isVisible());
    assert.equal(await f.page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1), false);
    await f.page.screenshot({ path: `test-results/unified/guide-${width}.png`, fullPage: true });
    await f.page.getByRole('link', { name: 'ابدأ: البحث والتسجيل' }).click();
    await f.page.getByRole('searchbox').fill(`غير موجود ${fixture.tag}`);
    await f.page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
    await f.page.keyboard.press('Tab');
    assert.equal(await f.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).inputValue(), '');
    await f.page.waitForFunction(() => document.getAnimations().every(a => a.playState !== 'running'));
    await f.page.screenshot({ path: `test-results/unified/registration-${width}.png`, fullPage: true });
    await f.page.goto(`${base}/users?facility_id=${fixture.facilities[0]}`);
    await f.page.getByRole('tab', { name: 'الأدوار والصلاحيات' }).click();
    await f.page.getByRole('button', { name: 'إضافة دور', exact: true }).click();
    await f.page.getByRole('button', { name: 'معاينة الطبيب', exact: true }).click();
    await f.page.screenshot({ path: `test-results/unified/permissions-${width}.png`, fullPage: true });
    await f.page.getByRole('button', { name: 'إلغاء المعاينة' }).click();
    await f.page.keyboard.press('Escape');
    assert.equal(await f.page.getByRole('dialog').count(), 0);
  } finally { await f.context.close(); }
});

test('guide hides medical/write tasks from the limited registration account and rejects explicit foreign context', async () => {
  const f = await open('clerk', `/guide?facility_id=${fixture.facilities[0]}`);
  try {
    await f.page.getByRole('heading', { name: 'ماذا تريد أن تفعل؟' }).waitFor();
    assert.equal(await f.page.getByRole('link', { name: 'ابدأ: استكمال مسودة محفوظة' }).count(), 0);
    assert.equal(await f.page.getByRole('link', { name: 'ابدأ: زيارة جديدة للمريض نفسه' }).count(), 0);
    await f.page.goto(`${base}/patient-cards?facility_id=${fixture.facilities[1]}`);
    await f.page.getByRole('alert').waitFor();
    assert.equal(await f.page.getByRole('searchbox').count(), 0);
  } finally { await f.context.close(); }
});
