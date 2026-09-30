import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3198';
assert.ok(['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname));
let browser, fixture;
function control(mode) {
  const result = spawnSync('php', ['tests/Support/explicit-access-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}
before(async () => { control('prepare'); fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/explicit-access-live.json', import.meta.url))); browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' }); mkdirSync('test-results/explicit-access', { recursive: true }); });
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body) {
  const r = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers', 'Request must reach guarded real Laravel through Next');
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function open(kind, path, width = 1440) {
  const login = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(login.status, 200);
  const token = login.body.data.token;
  const context = await browser.newContext({ viewport: { width, height: 960 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => { if (!sessionStorage.getItem('ea-fixture')) { sessionStorage.setItem('hospital.bearer', token); sessionStorage.setItem('ea-fixture', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000); await page.goto(`${base}${path}`);
  return { page, context, token };
}

test('real UI role selection, separate global delegation, registration and reopening canonical card', async () => {
  const admin = await open('admin', `/users?facility_id=${fixture.facility}&role=${fixture.roles.registration}`);
  try {
    await admin.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    await admin.page.getByRole('button', { name: 'معاينة التسجيل والبيانات الأساسية', exact: true }).click();
    await admin.page.getByRole('button', { name: 'إضافة الصلاحيات المتاحة صراحة' }).click();
    await admin.page.getByLabel('سبب تعديل الصلاحيات *').fill('تجهيز موظف التسجيل — اختبار اصطناعي');
    await admin.page.getByRole('button', { name: 'معاينة وحفظ الدور', exact: true }).click();
    const saved = admin.page.waitForResponse(r => r.url().includes(`/users/roles/${fixture.roles.registration}`) && r.request().method() === 'PUT');
    await admin.page.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    assert.equal((await saved).status(), 200);
    await admin.page.goto(`${base}/users?facility_id=${fixture.facility}&account=${fixture.users.clerk.id}`);
    await admin.page.getByText('مراجعة الأدوار المحلية وتغييرها', { exact: true }).click();
    await admin.page.getByLabel('ابحث عن دور', { exact: true }).fill(fixture.tag);
    await admin.page.getByLabel(`مدخل بيانات التدريب ${fixture.tag}`, { exact: true }).uncheck();
    await admin.page.getByLabel(`التسجيل المعتمد ${fixture.tag}`, { exact: true }).check();
    await admin.page.getByLabel('سبب التغيير', { exact: true }).fill('إسناد التسجيل داخل المشفى');
    await admin.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const local = admin.page.waitForResponse(r => r.url().includes(`/users/${fixture.users.clerk.id}/access`) && r.request().method() === 'PUT');
    await admin.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await local).status(), 200);
    const clerkLogin = await api(null, 'login', 'POST', { username: fixture.users.clerk.username, password: 'password' });
    assert.equal((await api(clerkLogin.body.data.token, `reception/patients?facility_id=${fixture.facility}&search=nobody`)).status, 403, 'Local assignment alone must not enable global search');
    await admin.page.goto(`${base}/users?facility_id=${fixture.facility}&account=${fixture.users.clerk.id}`);
    await admin.page.getByLabel('البحث المحدود عن المريض — تفويض عالمي', { exact: true }).check();
    await admin.page.getByLabel('إنشاء هوية مريض جديد — تفويض عالمي', { exact: true }).check();
    await admin.page.getByLabel('سبب التغيير', { exact: true }).fill('تفويض عالمي محدود وصريح');
    await admin.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const delegated = admin.page.waitForResponse(r => r.url().includes(`/users/${fixture.users.clerk.id}/access`) && r.request().method() === 'PUT');
    await admin.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await delegated).status(), 200);
  } finally { await admin.context.close(); }
  const clerk = await open('clerk', `/patient-cards/new?facility_id=${fixture.facility}`);
  try {
    await clerk.page.getByRole('searchbox').fill(`تدريب صلاحيات ${fixture.tag}`);
    await clerk.page.getByText('لا توجد نتائج مطابقة. راجع الاسم والمعرّف قبل إنشاء مريض جديد.').waitFor();
    await clerk.page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
    await clerk.page.getByLabel('الاسم الأول', { exact: true }).fill('تدريب صلاحيات');
    await clerk.page.getByLabel('اسم العائلة', { exact: true }).fill(fixture.tag);
    await clerk.page.getByLabel('تاريخ الزيارة الفعلي', { exact: true }).fill('2020-03-04');
    const saved = clerk.page.waitForResponse(r => r.url().includes('/reception/registrations') && r.status() === 201);
    await clerk.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
    const card = (await (await saved).json()).data;
    await clerk.page.goto(`${base}/patient-cards/${card.id}?facility_id=${fixture.facility}&view=registration`);
    await clerk.page.getByRole('region', { name: 'البيانات الأساسية للبطاقة' }).waitFor();
    for (const [method, path] of [['GET', `dossiers/${card.id}`], ['DELETE', `dossiers/${card.id}`], ['POST', `dossiers/${card.id}/report/pdf`]]) assert.equal((await api(clerk.token, `${path}?facility_id=${fixture.facility}`, method)).status, 403);
    control('verify');
  } finally { await clerk.context.close(); }
});

for (const width of [390, 768, 1440]) test(`real role/account detail and illustrated guide at ${width}px`, async () => {
  const f = await open('admin', `/users?facility_id=${fixture.facility}&role=${fixture.roles.admin}`, width);
  try {
    await f.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    await f.page.getByText('الخيارات المعطّلة محمية', { exact: false }).waitFor();
    assert.ok(await f.page.getByRole('checkbox', { disabled: true }).count() >= 7);
    assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1));
    await f.page.screenshot({ path: `test-results/explicit-access/protected-${width}.png`, fullPage: true });
    await f.page.goto(`${base}/users?facility_id=${fixture.facility}&account=${fixture.users.clerk.id}`);
    await f.page.getByRole('group', { name: 'تفويض عالمي مستقل' }).waitFor();
    await f.page.screenshot({ path: `test-results/explicit-access/account-${width}.png`, fullPage: true });
    await f.page.goto(`${base}/guide?facility_id=${fixture.facility}`);
    await f.page.getByRole('heading', { name: 'جهّز الموظف بخطوتي الوصول' }).waitFor();
    await f.page.screenshot({ path: `test-results/explicit-access/guide-${width}.png`, fullPage: true });
  } finally { await f.context.close(); }
});

test('roles-only viewer opens details without account access or write buttons', async () => {
  const f = await open('viewer', `/users?facility_id=${fixture.facility}&role=${fixture.roles.clerk}`);
  try {
    await f.page.getByRole('dialog').waitFor();
    assert.equal(await f.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).count(), 0);
    assert.equal((await api(f.token, `users/${fixture.users.clerk.id}/access?facility_id=${fixture.facility}`)).status, 403);
    assert.equal((await api(f.token, `users/roles/${fixture.roles.clerk}`, 'PUT', { facility_id: fixture.facility, name_ar: 'forbidden', permission_ids: [1], lock_version: 0, reason: 'forbidden' })).status, 403);
  } finally { await f.context.close(); }
});
