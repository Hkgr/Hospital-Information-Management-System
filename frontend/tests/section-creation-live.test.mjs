import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3198';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname));
let browser, fixture, permissions;
function control(mode) {
  const r = spawnSync('php', ['tests/Support/explicit-access-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => {
  control('prepare');
  fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/explicit-access-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: 'chrome' });
  mkdirSync('test-results/section-creation', { recursive: true });
  const admin = await login('admin');
  const options = await api(admin, `users/roles/options?facility_id=${fixture.facility}`);
  assert.equal(options.status, 200);
  permissions = options.body.data.permission_groups.flatMap(g => g.permissions);
});
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body) {
  const r = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers');
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function login(kind) {
  const r = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(r.status, 200); return r.body.data.token;
}
async function open(token, path, width = 1440) {
  const context = await browser.newContext({ viewport: { width, height: 960 }, reducedMotion: 'reduce' });
  await context.addInitScript(token => { sessionStorage.setItem('hospital.bearer', token); }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  await page.goto(`${base}${path}`);
  return { context, page };
}
async function setRole(admin, codes) {
  const role = (await api(admin, `users/roles/${fixture.roles.clerk}?facility_id=${fixture.facility}`)).body.data;
  const r = await api(admin, `users/roles/${role.id}`, 'PUT', { facility_id: fixture.facility, name_ar: role.name_ar, lock_version: role.lock_version, reason: 'اختبار اختيار صلاحيات الإضافة', permission_ids: codes.map(code => { const p = permissions.find(p => p.code === code); assert.ok(p, code); return p.id; }) });
  assert.equal(r.status, 200, JSON.stringify(r.body));
}

for (const width of [390, 768, 1440]) test(`existing local role enables separate add buttons and actual saves at ${width}px without global assignment`, async () => {
  const admin = await login('admin');
  await setRole(admin, ['patients.basic.view']);
  // Start this session before editing its existing role; no reassignment/relogin required by the API.
  const employee = await login('clerk');
  const editor = await open(admin, `/users?facility_id=${fixture.facility}&role=${fixture.roles.clerk}`, width);
  try {
    await editor.page.getByRole('button', { name: 'تعديل الصلاحيات', exact: true }).click();
    const dialog = editor.page.getByRole('dialog');
    for (const code of ['clinics.create', 'doctors.directory.create', 'medications.create', 'catalog.directory.create']) {
      const p = permissions.find(p => p.code === code);
      await dialog.getByRole('checkbox', { name: new RegExp(`^${p.name_ar}`) }).check();
      for (const prerequisite of p.prerequisites) {
        const required = permissions.find(p => p.code === prerequisite);
        assert.equal(await dialog.getByRole('checkbox', { name: new RegExp(`^${required.name_ar}`) }).isChecked(), true);
      }
    }
    await dialog.getByLabel('سبب تعديل الصلاحيات *').fill('إضافة فقط دون تعديل أو حذف');
    await dialog.getByRole('button', { name: 'معاينة وحفظ الدور' }).click();
    const response = editor.page.waitForResponse(r => r.request().method() === 'PUT' && r.url().includes(`/users/roles/${fixture.roles.clerk}`));
    await dialog.getByRole('button', { name: 'تأكيد حفظ الدور' }).click();
    assert.equal((await response).status(), 200);
  } finally { await editor.context.close(); }
  const access = (await api(admin, `users/${fixture.users.clerk.id}/access?facility_id=${fixture.facility}`)).body.data;
  assert.deepEqual(access.global_effective_codes, []);
  const identity = (await api(employee, 'user')).body.data;
  assert.ok(identity.access.find(e => e.facility.id === fixture.facility).permissions.includes('clinics.create'));
  const session = await open(employee, `/clinics?facility_id=${fixture.facility}`, width);
  try {
    const page = session.page;
    await page.getByRole('button', { name: 'إضافة عيادة جديدة', exact: true }).click();
    await page.getByLabel('اسم العيادة *', { exact: true }).fill(`عيادة ${fixture.tag} ${width}`);
    let saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/clinics'));
    await page.getByRole('button', { name: 'حفظ العيادة', exact: true }).click();
    assert.equal((await saved).status(), 201);

    await page.goto(`${base}/doctors?facility_id=${fixture.facility}`);
    await page.getByRole('button', { name: /إضافة طبيب/, exact: false }).first().click();
    await page.getByLabel('الاسم الكامل *', { exact: true }).fill(`طبيب ${fixture.tag} ${width}`);
    const options = (await api(employee, `doctors/options?facility_id=${fixture.facility}`)).body.data;
    assert.ok(options.staff_types.length, 'The local server must configure existing test doctor types');
    await page.getByLabel(/^نوع الطبيب/).selectOption(String(options.staff_types[0].id));
    if (options.specialties.length) await page.getByRole('group', { name: /^التخصصات/ }).getByRole('checkbox').first().check();
    saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/doctors'));
    await page.getByRole('button', { name: 'حفظ الطبيب', exact: true }).click();
    assert.equal((await saved).status(), 201);

    for (const [path, kind, button] of [['medications', 'medication', 'إضافة دواء'], ['services-procedures', 'procedure', 'إضافة إجراء']]) {
      await page.goto(`${base}/${path}?facility_id=${fixture.facility}`);
      await page.getByRole('button', { name: button, exact: true }).click();
      const modal = page.getByRole('dialog');
      await modal.getByLabel('الاسم *', { exact: true }).fill(`${kind} ${fixture.tag} ${width}`);
      saved = page.waitForResponse(r => r.request().method() === 'POST' && r.url().endsWith('/service-catalog'));
      await modal.getByRole('button', { name: /^حفظ/ }).click();
      const response = await saved; assert.equal(response.status(), 201, await response.text());
      const record = (await response.json()).data;
      assert.equal(record.kind, kind);
      assert.equal((await api(employee, `service-catalog/${kind}/${record.id}`, 'PUT', { facility_id: fixture.facility, name_ar: record.name_ar, is_active: true, lock_version: record.lock_version })).status, 403);
      await page.getByRole('dialog').waitFor({ state: 'hidden' });
      await page.screenshot({ path: `test-results/section-creation/${path}-${width}.png`, fullPage: true });
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    }
    for (const path of ['dossiers', 'users/options', 'service-catalog/export/xlsx']) assert.equal((await api(employee, `${path}?facility_id=${fixture.facility}`)).status, 403);
  } finally { await session.context.close(); }
});

test('medication and service grants stay independent and revocation affects the same token', async () => {
  const admin = await login('admin');
  await setRole(admin, ['catalog.view', 'medications.create']);
  const employee = await login('clerk');
  const session = await open(employee, `/medications?facility_id=${fixture.facility}`);
  try {
    await session.page.getByRole('button', { name: 'إضافة دواء', exact: true }).waitFor();
    await session.page.goto(`${base}/services-procedures?facility_id=${fixture.facility}`);
    await session.page.getByRole('status').filter({ hasText: 'جارٍ تحميل الخدمات' }).waitFor({ state: 'hidden' });
    assert.equal(await session.page.getByRole('button', { name: /^إضافة (خدمة|إجراء)$/ }).count(), 0);
    await setRole(admin, ['catalog.view', 'catalog.directory.create']);
    await session.page.goto(`${base}/medications?facility_id=${fixture.facility}`);
    await session.page.getByRole('status').filter({ hasText: 'جارٍ تحميل الأدوية' }).waitFor({ state: 'hidden' });
    assert.equal(await session.page.getByRole('button', { name: 'إضافة دواء', exact: true }).count(), 0);
    const denied = await api(employee, 'service-catalog', 'POST', { facility_id: fixture.facility, kind: 'medication', name_ar: 'ممنوع', is_active: true, request_id: crypto.randomUUID() });
    assert.equal(denied.status, 403);
  } finally { await session.context.close(); }
});
