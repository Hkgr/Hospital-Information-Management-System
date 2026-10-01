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

for (const kind of ['removal', 'addition']) test(`real concurrent global role ${kind} rejects stale preview and requires explicit fresh review`, async () => {
  const target = fixture.users[kind].id;
  const f = await open('admin', `/users?facility_id=${fixture.facility}&account=${target}`);
  let writes = 0;
  f.page.on('request', r => { if (r.method() === 'PUT' && r.url().includes(`/users/${target}/access`)) writes++; });
  try {
    const search = f.page.getByLabel('البحث المحدود عن المريض — تفويض عالمي', { exact: true });
    const create = f.page.getByLabel('إنشاء هوية مريض جديد — تفويض عالمي', { exact: true });
    await (kind === 'removal' ? create : search).uncheck();
    await f.page.getByLabel('سبب التغيير', { exact: true }).fill('معاينة قبل تغيير دور متزامن');
    await f.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const role = await api(f.token, `users/roles/${fixture.roles[kind]}?facility_id=${fixture.facility}`);
    const options = await api(f.token, `users/roles/options?facility_id=${fixture.facility}`);
    const permissions = options.body.data.permission_groups.flatMap(g => g.permissions);
    const codes = kind === 'removal' ? ['patients.basic.create'] : ['patients.basic.search', 'patients.basic.create'];
    assert.equal((await api(f.token, `users/roles/${fixture.roles[kind]}`, 'PUT', { facility_id: fixture.facility, name_ar: role.body.data.name_ar, lock_version: role.body.data.lock_version, permission_ids: permissions.filter(p => codes.includes(p.code)).map(p => p.id), reason: 'concurrent reviewed role update' })).status, 200);
    const rejected = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/${target}/access`));
    await f.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await rejected).status(), 409);
    await f.page.getByRole('region', { name: 'تعارض حالة الوصول' }).waitFor();
    assert.equal(await f.page.getByRole('button', { name: 'تأكيد التفويض' }).isDisabled(), true);
    assert.equal(await (kind === 'removal' ? create : search).isChecked(), false, 'The old draft remains visible after rejection');
    await f.page.getByRole('button', { name: 'جلب أحدث حالة الوصول' }).click();
    const comparison = f.page.locator('details').filter({ has: f.page.getByText('مقارنة صلاحيات الأدوار المحلية', { exact: true }) });
    await comparison.locator('summary').click();
    const currentPermissions = comparison.locator('p').filter({ hasText: /^الآن:/ });
    for (const permission of permissions.filter(p => codes.includes(p.code))) assert.ok((await currentPermissions.allTextContents()).some(text => text.includes(permission.name_ar)));
    await f.page.getByRole('button', { name: 'بدء مراجعة جديدة بالقيم الحالية' }).click();
    assert.equal(writes, 1, 'Fetching and reviewing must not submit the old draft');
    assert.equal(await create.isChecked(), true, 'The concurrent addition/current permission is preserved');
    assert.equal(await search.isChecked(), kind === 'addition');
    if (kind === 'addition') await search.uncheck(); else await search.check();
    await f.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const saved = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/${target}/access`));
    await f.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await saved).status(), 200);
    const current = await api(f.token, `users/${target}/access?facility_id=${fixture.facility}`);
    assert.deepEqual(current.body.data.global_effective_codes.sort(), (kind === 'addition' ? ['patients.basic.create'] : ['patients.basic.create', 'patients.basic.search']).sort());
    assert.equal(writes, 2);
  } finally { await f.context.close(); }
});

test('real disabled-only member stays visible and can receive an active role without deleting historical membership', async () => {
  const member = fixture.users.disabled;
  const f = await open('admin', `/users?facility_id=${fixture.facility}&search=${member.username}`);
  try {
    await f.page.getByText('لا يوجد دور فعال — يحتاج إسنادًا', { exact: true }).waitFor();
    const listing = await api(f.token, `users?facility_id=${fixture.facility}&search=${member.username}`);
    assert.equal(listing.body.meta.total, 1); assert.equal(listing.body.data.length, 1); assert.deepEqual(listing.body.data[0].roles, []);
    await f.page.getByRole('link', { name: `استعراض ${member.username}`, exact: true }).click();
    await f.page.getByText('مراجعة الأدوار المحلية وتغييرها', { exact: true }).click();
    await f.page.getByLabel('ابحث عن دور', { exact: true }).fill(fixture.tag);
    assert.equal(await f.page.getByLabel(`دور تدريب disabled ${fixture.tag}`, { exact: true }).count(), 0);
    await f.page.getByLabel(`التسجيل المعتمد ${fixture.tag}`, { exact: true }).check();
    await f.page.getByLabel('سبب التغيير', { exact: true }).fill('إصلاح الإسناد مع حفظ التاريخ');
    await f.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const saved = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/${member.id}/access`));
    await f.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await saved).status(), 200);
    await f.page.getByRole('cell', { name: `التسجيل المعتمد ${fixture.tag}`, exact: true }).waitFor();
    control('verify-reassignment');
  } finally { await f.context.close(); }
});

for (const width of [390, 768, 1440]) test(`shared spacing keeps real screens and dialog actions usable at ${width}px`, async () => {
  const f = await open('admin', `/users?facility_id=${fixture.facility}`, width);
  const errors = [];
  f.page.on('pageerror', e => errors.push(e.message));
  const screens = ['/users', '/doctors', '/clinics', '/services-procedures', '/medications', '/blood-bank', '/patient-cards', '/patient-cards/new', '/visits', '/stock/receipts', '/reports', '/statistics', '/settings', '/guide', '/dashboard/general'];
  try {
    for (const path of screens) {
      await f.page.goto(`${base}${path}?facility_id=${fixture.facility}`);
      await f.page.getByRole('main').waitFor();
      await f.page.waitForLoadState('networkidle');
      await f.page.evaluate(() => document.fonts.ready);
      assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${path} must keep horizontal scroll inside tables, not the page`);
      const name = path.replaceAll('/', '-').replace(/^-/, '');
      await f.page.screenshot({ path: `test-results/explicit-access/spacing-${name}-${width}.png`, fullPage: true });
      if (path === '/clinics' || path === '/doctors' || path === '/blood-bank') {
        const action = path === '/clinics' ? 'إضافة عيادة جديدة' : path === '/doctors' ? 'إضافة طبيب جديد' : 'تسجيل تبرع';
        const trigger = f.page.getByRole('button', { name: action, exact: true });
        await trigger.click();
        const dialog = f.page.getByRole('dialog'); await dialog.waitFor();
        await f.page.waitForLoadState('networkidle');
        assert.ok(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1), `${action} must not clip the form horizontally`);
        const fields = dialog.locator('input:visible:not([type="checkbox"]):not([type="radio"]),select:visible,textarea:visible');
        if (await fields.count()) { await fields.last().focus(); await fields.last().scrollIntoViewIfNeeded(); assert.equal(await fields.last().evaluate(el => el === document.activeElement), true); }
        await dialog.screenshot({ path: `test-results/explicit-access/spacing-${name}-dialog-${width}.png` });
        await f.page.getByRole('button', { name: 'إغلاق النافذة', exact: true }).click();
        assert.equal(await trigger.evaluate(el => el === document.activeElement), true, 'Closing returns focus to the initiating action');
      }
    }
    assert.deepEqual(errors, []);
  } finally { await f.context.close(); }
});
