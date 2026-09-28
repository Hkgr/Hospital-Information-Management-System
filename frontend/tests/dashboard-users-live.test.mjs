import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194', direct = 'http://127.0.0.1:8194/api';
let browser, fixture;
function control(mode) {
  const r = spawnSync('php', ['tests/Support/dashboard-users-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => { control('prepare'); fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dashboard-users-live.json', import.meta.url))); browser = await chromium.launch({ channel: 'chrome' }); });
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body, origin = `${base}/hospital-api`) {
  return fetch(`${origin}/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
}
async function login(kind) {
  const r = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(r.status, 200); return (await r.json()).data.token;
}
async function open(kind, path) {
  const token = await login(kind), context = await browser.newContext();
  await context.addInitScript(t => { if (!sessionStorage.getItem('fixture-loaded')) { sessionStorage.setItem('hospital.bearer', t); sessionStorage.setItem('fixture-loaded', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  const calls = [];
  page.on('response', r => { if (r.url().includes('/hospital-api/')) calls.push({ path: new URL(r.url()).pathname + new URL(r.url()).search, status: r.status() }); });
  await page.goto(`${base}${path}`);
  return { context, page, token, calls };
}
async function logout(page, kind) {
  await page.getByRole('button', { name: `حساب اختبار التوجيه ${kind}`, exact: true }).click();
  await page.getByRole('button', { name: 'تسجيل الخروج', exact: true }).click();
  await page.waitForURL('**/login');
  assert.equal(await page.evaluate(() => sessionStorage.getItem('hospital.bearer')), null);
}

for (const kind of ['single', 'multi']) test(`direct facility dashboard resolves server default for ${kind} before requesting details`, async () => {
  const f = await open(kind, '/dashboard/general');
  try {
    const catalog = await (await api(f.token, 'dashboards')).json();
    const id = catalog.data.dashboards.find(d => d.key === 'general').default_facility_id;
    await f.page.waitForURL(`${base}/dashboard/general?facility_id=${id}`);
    await f.page.locator('#welcome-heading').waitFor();
    assert.ok(f.calls.every(c => c.path !== '/hospital-api/dashboards/general'), JSON.stringify(f.calls));
    assert.equal(await f.page.getByRole('combobox', { name: /^المنشأة/ }).inputValue(), String(id));
    if (kind === 'multi') {
      await f.page.getByRole('combobox', { name: /^المنشأة/ }).selectOption(String(fixture.facilities[1]));
      await f.page.waitForURL(`**/dashboard/general?facility_id=${fixture.facilities[1]}`);
      await f.page.getByRole('heading', { name: 'منشأة اختبار التوجيه B', exact: true }).waitFor();
      assert.equal(await f.page.getByRole('heading', { name: 'منشأة اختبار التوجيه A', exact: true }).count(), 0);
    }
  } finally { await f.context.close(); }
});

test('explicit authorized, unauthorized and invalid facilities never silently change; empty access can log out', async () => {
  for (const [kind, suffix, success] of [['single_errors', `?facility_id=${fixture.facilities[0]}`, true], ['single_errors', `?facility_id=${fixture.facilities[2]}`, false], ['single_errors', '?facility_id=bad', false], ['single_errors', '?facility_id=', false], ['none', '', false]]) {
    const f = await open(kind, `/dashboard/general${suffix}`);
    try {
      if (success) await f.page.locator('#welcome-heading').waitFor();
      else { await f.page.locator('#main-content').getByText(/لا توجد لوحة|معرّف المنشأة غير صالح|ليس لديك صلاحية/).first().waitFor(); assert.equal(await f.page.locator('#welcome-heading').count(), 0); }
      assert.equal(new URL(f.page.url()).search, suffix);
      try { await logout(f.page, kind); }
      catch (error) { throw new Error(`Logout ${kind}${suffix}: ${new URL(f.page.url()).pathname}; ${JSON.stringify(f.calls)}; ${(await f.page.locator('body').innerText()).slice(-600)}`, { cause: error }); }
    } finally { await f.context.close(); }
  }
});

test('real options/list/roles match Laravel through Next; numeric role create/update and protection remain enforced', async () => {
  const token = await login('multi'), id = fixture.facilities[0];
  for (const path of ['users/options', 'users', 'users/roles']) {
    const upstream = await api(token, `${path}?facility_id=${id}`, 'GET', undefined, direct);
    const proxy = await api(token, `${path}?facility_id=${id}`);
    const body = await upstream.json();
    console.log(`${path}: Laravel ${upstream.status}, Next ${proxy.status}, ${proxy.headers.get('content-type')}`);
    assert.equal(upstream.status, 200); assert.equal(proxy.status, 200);
    assert.equal(proxy.headers.get('x-test-laravel'), 'dossiers');
    assert.deepEqual(await proxy.json(), body);
  }
  const body = { facility_id: id, name_ar: 'دور اختبار التحويل', permission_ids: [fixture.permission] };
  const created = await api(token, 'users/roles', 'POST', body); assert.equal(created.status, 201);
  const role = (await created.json()).data;
  const updated = await api(token, `users/roles/${role.id}`, 'PUT', { ...body, name_ar: 'دور معدّل عبر Next' });
  assert.equal(updated.status, 200); assert.equal((await updated.json()).data.name_ar, 'دور معدّل عبر Next');
  const protectedRole = await api(token, `users/roles/${fixture.users.protected.role}`, 'PUT', body);
  assert.equal(protectedRole.status, 403); assert.equal((await protectedRole.json()).error.code, 'PROTECTED_SYSTEM_ROLE');
  const protectedUser = await api(token, `users/${fixture.users.protected.id}?facility_id=${id}`, 'DELETE');
  assert.equal(protectedUser.status, 403); assert.equal((await protectedUser.json()).error.code, 'PROTECTED_SYSTEM_USER');
  const reader = await login('reader');
  for (const [path, method, input] of [[`users/roles?facility_id=${id}`, 'GET'], ['users/roles', 'POST', body], [`users/roles/${role.id}`, 'PUT', body]]) {
    const r = await api(reader, path, method, input); assert.equal(r.status, 403); assert.equal((await r.json()).error.code, 'ROLES_ACCESS_DENIED');
  }
  assert.equal((await api(null, `users/roles?facility_id=${id}`)).status, 401);
  const invalid = await api(token, 'users/roles/not-numeric', 'PUT', body);
  assert.equal(invalid.status, 404); assert.equal(invalid.headers.get('x-test-laravel'), null);
});

test('users direct and sidebar navigation load options, list and roles; real role form creates and updates', async () => {
  const f = await open('multi', '/users');
  try {
    await f.page.getByRole('button', { name: 'إضافة مستخدم', exact: true }).waitFor();
    await f.page.getByRole('tab', { name: 'الأدوار والصلاحيات' }).click();
    await f.page.getByRole('button', { name: 'إضافة دور', exact: true }).click();
    const dialog = f.page.getByRole('dialog', { name: 'إضافة دور', exact: true });
    const name = `دور واجهة ${Date.now()}`;
    await dialog.getByLabel('اسم الدور *').fill(name);
    await dialog.locator('input[type=checkbox]').first().check();
    await dialog.getByRole('button', { name: 'إنشاء الدور', exact: true }).click();
    await dialog.waitFor({ state: 'hidden' });
    const row = f.page.getByRole('row').filter({ has: f.page.getByText(name, { exact: true }) });
    await row.getByRole('button', { name: `تعديل ${name}`, exact: true }).click();
    const edit = f.page.getByRole('dialog', { name: `تعديل ${name}`, exact: true });
    await edit.getByLabel('اسم الدور *').fill(`${name} معدّل`);
    await edit.getByRole('button', { name: 'حفظ الدور', exact: true }).click();
    await edit.waitFor({ state: 'hidden' });
    await f.page.getByText(`${name} معدّل`, { exact: true }).waitFor();
    await f.page.locator('#desktop-navigation').getByRole('link', { name: 'الرئيسية', exact: true }).click();
    await f.page.locator('#welcome-heading').waitFor();
    await f.page.locator('#desktop-navigation').getByRole('link', { name: 'المستخدمون', exact: true }).click();
    await f.page.getByRole('button', { name: 'إضافة مستخدم', exact: true }).waitFor();
    for (const path of ['users/options', 'users', 'users/roles']) assert.ok(f.calls.some(c => c.path === `/hospital-api/${path}?facility_id=${fixture.facilities[0]}` && c.status === 200), JSON.stringify(f.calls));
    await f.page.getByLabel('المنشأة', { exact: true }).selectOption(String(fixture.facilities[1]));
    await f.page.waitForURL(`**/users?facility_id=${fixture.facilities[1]}`);
    await f.page.getByRole('row').filter({ hasText: fixture.users.multi.username }).waitFor();
    assert.equal(await f.page.getByText(fixture.users.single.username, { exact: true }).count(), 0);
  } finally { await f.context.close(); }
});

test('idle deadline still rejects requests and clears user-management UI', async () => {
  const f = await open('single', '/users');
  try {
    await f.page.getByRole('heading', { name: 'إدارة المستخدمين', exact: true }).waitFor();
    control('expire');
    const r = await api(f.token, `users/options?facility_id=${fixture.facilities[0]}`);
    assert.equal(r.status, 401); assert.equal((await r.json()).error.code, 'SESSION_IDLE_EXPIRED');
    await f.page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await f.page.waitForURL('**/login?reason=idle');
    assert.equal(await f.page.evaluate(() => sessionStorage.getItem('hospital.bearer')), null);
    assert.equal(await f.page.getByRole('heading', { name: 'إدارة المستخدمين', exact: true }).count(), 0);
  } finally { await f.context.close(); }
});

test('users facility denial and missing authority show no prior data and allow real logout', async () => {
  for (const [kind, suffix] of [['single_users', `?facility_id=${fixture.facilities[2]}`], ['single_users', '?facility_id=invalid'], ['none', '']]) {
    const f = await open(kind, `/users${suffix}`);
    try {
      await f.page.locator('#main-content').getByRole('alert').waitFor();
      assert.equal(await f.page.getByRole('region', { name: 'جدول المستخدمين', exact: true }).count(), 0);
      assert.equal(new URL(f.page.url()).search, suffix);
      await logout(f.page, kind);
    } finally { await f.context.close(); }
  }
});
