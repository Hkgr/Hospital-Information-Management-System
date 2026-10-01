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

test('existing role explains blocked save and automatically includes task prerequisites', async () => {
  const f = await open('delegator', `/users?facility_id=${fixture.facility}&role=${fixture.roles.clerk}`);
  try {
    const identity = await api(f.token, 'user');
    assert.ok(identity.body.data.access.find(e => e.facility.id === fixture.facility).permissions.includes('roles.update'));
    await f.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    assert.equal(await f.page.getByRole('button', { name: 'معاينة وحفظ الدور' }).isDisabled(), true);
    await f.page.getByText('أدخل سبب تعديل الصلاحيات (ثلاثة محارف على الأقل).', { exact: true }).waitFor();
    assert.ok(await f.page.getByText('أدخل سبب تعديل الصلاحيات (ثلاثة محارف على الأقل).', { exact: true }).evaluate(el => { const rect = el.getBoundingClientRect(); return rect.top >= 0 && rect.bottom <= innerHeight; }), 'Blocked reason stays visible beside the sticky save action');
    await f.page.getByLabel('سبب تعديل الصلاحيات *').fill('تجهيز دور تشغيلي');
    const options = await api(f.token, `users/roles/options?facility_id=${fixture.facility}`);
    const permissions = options.body.data.permission_groups.flatMap(g => g.permissions);
    const task = permissions.find(p => p.code === 'dossiers.treatment.administration.void');
    await f.page.getByRole('checkbox', { name: new RegExp(`^${task.name_ar}`) }).check();
    for (const code of task.prerequisites) {
      const required = permissions.find(p => p.code === code);
      assert.equal(await f.page.getByRole('checkbox', { name: new RegExp(`^${required.name_ar}`) }).isChecked(), true, code);
    }
    assert.equal(await f.page.getByRole('button', { name: 'معاينة وحفظ الدور' }).isEnabled(), true);
    await f.page.getByRole('button', { name: 'معاينة وحفظ الدور' }).click();
    const response = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/roles/${fixture.roles.clerk}`));
    await f.page.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    assert.equal((await response).status(), 200);
  } finally { await f.context.close(); }
});

test('protected delegator saves mixed-scope role and assigns only its explicit global subset in the same review', async () => {
  const f = await open('delegator', `/users?facility_id=${fixture.facility}`);
  try {
    const identity = await api(f.token, 'user');
    assert.ok(!identity.body.data.access.find(e => e.facility.id === fixture.facility).permissions.includes('patients.basic.search'));
    await f.page.getByRole('tab', { name: 'الأدوار والصلاحيات' }).click();
    await f.page.getByRole('button', { name: 'إضافة دور', exact: true }).click();
    const dialog = f.page.getByRole('dialog');
    const name = `بحث بطاقات ${fixture.tag}`;
    await dialog.getByLabel('اسم الدور *').fill(name);
    await dialog.getByRole('checkbox', { name: /^عرض البيانات الأساسية لبطاقة المريض/ }).check();
    await dialog.getByRole('checkbox', { name: /^البحث المحدود عن المريض — تفويض عالمي/ }).check();
    await dialog.getByRole('button', { name: 'إنشاء الدور', exact: true }).click();
    await dialog.getByRole('region', { name: 'فرق صلاحيات الدور' }).getByText('تؤثر في الدليل المشترك', { exact: false }).waitFor();
    const saved = f.page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/users/roles'));
    await dialog.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    const response = await saved; assert.equal(response.status(), 201);
    await f.page.getByRole('button', { name: 'اختيار مستخدم وإسناد دور' }).click();
    await f.page.getByRole('link', { name: `استعراض ${fixture.users.clerk.username}`, exact: true }).click();
    await f.page.getByText('مراجعة الأدوار المحلية وتغييرها', { exact: true }).click();
    await f.page.getByLabel(`مدخل بيانات التدريب ${fixture.tag}`, { exact: true }).uncheck();
    await f.page.getByLabel(name, { exact: true }).check();
    assert.equal(await f.page.getByLabel('البحث المحدود عن المريض — تفويض عالمي', { exact: true }).isChecked(), true);
    await f.page.getByLabel('سبب التغيير', { exact: true }).fill('إسناد مراجَع بنطاقين واضحين');
    await f.page.getByRole('button', { name: 'معاينة فرق الوصول' }).click();
    const assigned = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/${fixture.users.clerk.id}/access`));
    await f.page.getByRole('button', { name: 'تأكيد التفويض' }).click();
    assert.equal((await assigned).status(), 200);
    const current = await api(f.token, `users/${fixture.users.clerk.id}/access?facility_id=${fixture.facility}`);
    assert.deepEqual(current.body.data.global_effective_codes, ['patients.basic.search'], 'Local view is not copied into global authority');
    const clerk = await open('clerk', `/patient-cards?facility_id=${fixture.facility}`);
    try {
      assert.equal((await api(clerk.token, `patient-cards/patients?facility_id=${fixture.facility}&search=nobody`)).status, 200);
      for (const path of ['users/options', 'users/roles', 'dossiers']) assert.equal((await api(clerk.token, `${path}?facility_id=${fixture.facility}`)).status, 403);
      assert.equal((await api(clerk.token, 'users/roles', 'POST', { facility_id: fixture.facility, name_ar: 'forbidden', permission_ids: [1] })).status, 403);
    } finally { await clerk.context.close(); }
  } finally { await f.context.close(); }
});

test('protected administrator edits its own policy without an SQL or consolidation prerequisite', async () => {
  const f = await open('delegator', `/users?facility_id=${fixture.facility}&role=${fixture.roles.delegator}`);
  try {
    await f.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    assert.ok(await f.page.getByRole('checkbox', { disabled: true, checked: true }).count() >= 8);
    await f.page.getByLabel('سبب تعديل الصلاحيات *').fill('تأكيد سياسة المدير دون صلاحيات تشغيلية');
    await f.page.getByRole('button', { name: 'معاينة وحفظ الدور' }).click();
    const saved = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes('/protected-permissions'));
    await f.page.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    assert.equal((await saved).status(), 200);
    const options = await api(f.token, `users/options?facility_id=${fixture.facility}`);
    assert.equal(options.body.data.capabilities.roles_create, true);
  } finally { await f.context.close(); }
});

test('role conflict keeps draft then explicitly restarts from the reviewed current version', async () => {
  const f = await open('delegator', `/users?facility_id=${fixture.facility}`);
  try {
    const options = await api(f.token, `users/options?facility_id=${fixture.facility}`);
    const permissions = options.body.data.permission_groups.flatMap(g => g.permissions);
    const ids = codes => permissions.filter(p => codes.includes(p.code)).map(p => p.id);
    const created = await api(f.token, 'users/roles', 'POST', { facility_id: fixture.facility, name_ar: `تعارض ${fixture.tag}`, permission_ids: ids(['patients.basic.view']) });
    assert.equal(created.status, 201); const role = created.body.data;
    await f.page.goto(`${base}/users?facility_id=${fixture.facility}&role=${role.id}`);
    await f.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    const register = f.page.getByRole('checkbox', { name: /^إضافة مريض وبطاقته وزيارته الأولى/ });
    await register.check();
    await f.page.getByLabel('سبب تعديل الصلاحيات *').fill('اختيار قبل التعديل المتزامن');
    assert.equal((await api(f.token, `users/roles/${role.id}`, 'PUT', { facility_id: fixture.facility, name_ar: role.name_ar, lock_version: role.lock_version, permission_ids: ids(['patients.basic.view', 'patients.own.correct']), reason: 'concurrent role change' })).status, 200);
    await f.page.getByRole('button', { name: 'معاينة وحفظ الدور' }).click();
    const rejected = f.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/roles/${role.id}`));
    await f.page.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    assert.equal((await rejected).status(), 409);
    assert.equal(await register.isChecked(), true);
    await f.page.getByRole('button', { name: 'جلب أحدث نسخة من الدور' }).click();
    await f.page.getByRole('button', { name: 'بدء مراجعة الدور الحالي' }).click();
    assert.equal(await register.isChecked(), false);
    assert.equal(await f.page.getByRole('checkbox', { name: /^تصحيح بيانات أدخلتها خلال المهلة/ }).isChecked(), true);
    assert.ok((await api(f.token, `users/roles/${role.id}?facility_id=${fixture.facility}`)).body.data.permissions.every(p => p.code !== 'patient_cards.register'));
  } finally { await f.context.close(); }
});

for (const width of [390, 768, 1440]) test(`group selection, prerequisite removal confirmation and missing-field focus at ${width}px`, async () => {
  const f = await open('delegator', `/users?facility_id=${fixture.facility}`, width);
  try {
    await f.page.getByRole('tab', { name: 'الأدوار والصلاحيات' }).click();
    await f.page.getByRole('button', { name: 'إضافة دور', exact: true }).click();
    const dialog = f.page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'الانتقال إلى الحقل الناقص' }).click();
    assert.equal(await dialog.getByLabel('اسم الدور *').evaluate(el => el === document.activeElement), true);
    await dialog.getByLabel('اسم الدور *').fill('اختبار المجموعة');
    await dialog.getByLabel('البحث في الصلاحيات').fill('العيادات');
    const group = dialog.getByRole('group', { name: 'العيادات', exact: true });
    await group.getByRole('button', { name: 'تحديد المجموعة', exact: true }).click();
    await dialog.getByLabel('البحث في الصلاحيات').fill('');
    const clinics = dialog.getByRole('group', { name: 'العيادات', exact: true });
    assert.ok(await clinics.getByRole('checkbox', { checked: true }).count() > 1);
    await clinics.getByRole('checkbox', { name: /^استعراض العيادات/ }).click();
    await dialog.getByRole('region', { name: 'تأكيد إزالة المتطلب' }).waitFor();
    assert.equal(await clinics.getByRole('checkbox', { name: /^استعراض العيادات/ }).isChecked(), true, 'No removal before explicit review');
    await dialog.getByRole('button', { name: 'الاحتفاظ بالاختيارات' }).click();
    await clinics.getByRole('checkbox', { name: /^استعراض العيادات/ }).click();
    await dialog.getByRole('button', { name: 'تأكيد إزالة المتطلب والمهام' }).click();
    assert.equal(await clinics.getByRole('checkbox', { checked: true }).count(), 0);
    await clinics.getByRole('button', { name: 'تحديد المجموعة', exact: true }).click();
    await clinics.getByRole('button', { name: 'إلغاء تحديد المجموعة', exact: true }).click();
    assert.equal(await clinics.getByRole('checkbox', { checked: true }).count(), 0);
    await dialog.getByLabel('البحث في الصلاحيات').fill('العيادات');
    await dialog.getByLabel('البحث في الصلاحيات').scrollIntoViewIfNeeded();
    await f.page.evaluate(() => document.fonts.ready);
    assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
    assert.ok(await dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1));
    await f.page.screenshot({ path: `test-results/explicit-access/simple-role-${width}.png`, fullPage: true });
  } finally { await f.context.close(); }
});
