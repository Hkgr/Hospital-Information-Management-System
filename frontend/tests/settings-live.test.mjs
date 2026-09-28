import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync, mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194';
let fixture, browser;
function control(mode) {
  const r = spawnSync('php', ['tests/Support/settings-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), encoding: 'utf8', env: { ...process.env, APP_ENV: 'testing' } });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => { control('prepare'); fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/settings-live.json', import.meta.url))); browser = await chromium.launch({ channel: 'chrome' }); mkdirSync('test-results/settings', { recursive: true }); });
after(async () => { await browser?.close(); if (fixture) control('cleanup'); });
async function api(token, path, method = 'GET', body, direct = false) {
  const r = await fetch(`${direct ? 'http://127.0.0.1:8194/api' : base + '/hospital-api'}/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, ...(body ? { body: JSON.stringify(body) } : {}) });
  return { status: r.status, body: r.status === 204 ? null : await r.json() };
}
async function open(kind, path, width = 1440) {
  const login = await api(null, 'login', 'POST', { username: fixture.users[kind].username, password: 'password' });
  assert.equal(login.status, 200);
  const token = login.body.data.token, context = await browser.newContext({ viewport: { width, height: 950 } });
  await context.addInitScript(t => { if (!sessionStorage.getItem('fixture-loaded')) { sessionStorage.setItem('hospital.bearer', t); sessionStorage.setItem('fixture-loaded', '1'); } }, token);
  const page = await context.newPage(); page.setDefaultTimeout(12000);
  await page.goto(`${base}${path}`);
  return { token, page, context };
}

test('real Next settings read/write, version conflict, isolation, default preview and audit', async () => {
  const f = await open('admin', '/settings');
  try {
    await f.page.getByRole('heading', { name: 'إعدادات المنشأة', exact: true }).waitFor();
    const id = fixture.facilities[0];
    const via = await api(f.token, `settings?facility_id=${id}`), direct = await api(f.token, `settings?facility_id=${id}`, 'GET', null, true);
    assert.deepEqual(via, direct); assert.equal(via.status, 200);
    await f.page.getByLabel('اسم المنشأة', { exact: true }).fill('منشأة تحقق إعدادات العرض');
    await f.page.getByRole('button', { name: 'حفظ الإعدادات', exact: true }).click();
    await f.page.getByText('حُفظت الإعدادات وسُجّل التغيير بنجاح.').waitFor();
    await f.page.getByRole('button', { name: 'معاينة استعادة المدة الافتراضية' }).click();
    await f.page.getByRole('dialog').waitFor();
    await f.page.getByRole('button', { name: 'إلغاء', exact: true }).click();
    const body = { facility_id: id, name_ar: 'اسم متزامن', idle_minutes: 3, lock_version: 2, reason: 'اختبار التزامن' };
    assert.equal((await api(f.token, 'settings', 'PUT', body)).status, 200);
    await f.page.getByLabel('اسم المنشأة', { exact: true }).fill('مسودة المسؤول');
    await f.page.getByRole('button', { name: 'حفظ الإعدادات', exact: true }).click();
    await f.page.getByRole('button', { name: 'جلب القيم الحالية للمراجعة' }).click();
    await f.page.getByText('اسم المنشأة الحالي: اسم متزامن').waitFor();
    assert.equal(await f.page.getByLabel('اسم المنشأة', { exact: true }).inputValue(), 'مسودة المسؤول');
    assert.equal((await api(f.token, 'settings', 'PUT', body)).status, 409);
    assert.equal((await api(f.token, 'settings/system-session', 'PUT', { facility_id: id, idle_minutes: 3, lock_version: 1, reason: 'forbidden' })).status, 403);
    const audit = await api(f.token, `audit?facility_id=${id}&entity=facility_settings`);
    assert.equal(audit.status, 200);
    assert.ok(audit.body.data.some(row => row.entity === 'facility_settings' && row.changes.some(change => change.field === 'idle_minutes')));
  } finally { await f.context.close(); }
});

test('real account creation reports missing globals; protected roles and facility tampering rejected', async () => {
  const f = await open('admin', '/users');
  try {
    await f.page.getByRole('button', { name: 'إضافة مستخدم', exact: true }).click();
    await f.page.getByLabel('اسم المستخدم *', { exact: true }).fill(`settingslive-${fixture.tag}-clerk`);
    await f.page.getByLabel('الاسم *', { exact: true }).fill('استقبال اختباري');
    await f.page.getByLabel('كلمة المرور *', { exact: true }).fill('test-password');
    await f.page.getByRole('combobox', { name: /^الدور/ }).selectOption(String(fixture.clerk_role));
    await f.page.getByRole('button', { name: 'حفظ المستخدم', exact: true }).click();
    await f.page.getByText(/ينتظران تفويضًا عالميًا/).waitFor();
    const body = { facility_id: fixture.facilities[0], username: `settingslive-${fixture.tag}-blocked`, name: 'محظور', password: 'test-password', role_id: fixture.users.system.role };
    assert.equal((await api(f.token, 'users', 'POST', body)).status, 403);
    const created = await api(null, 'login', 'POST', { username: `settingslive-${fixture.tag}-clerk`, password: 'test-password' });
    assert.equal(created.status, 200);
    assert.equal((await api(created.body.data.token, `reception/patients?facility_id=${fixture.facilities[0]}&search=test`)).status, 403);
    assert.equal((await api(created.body.data.token, `settings?facility_id=${fixture.facilities[0]}`)).status, 403);
  } finally { await f.context.close(); }
});

test('guides follow server permissions, global role and facility; narrow roles cannot write settings', async () => {
  for (const kind of ['clerk', 'stats', 'reader', 'system', 'none']) {
    const f = await open(kind, `/guide?facility_id=${fixture.facilities[0]}`);
    try {
      if (kind === 'none') { await f.page.getByText(/لا يتوفر سياق منشأة مسموح/).waitFor(); continue; }
      await f.page.getByRole('heading', { name: 'الجلسة والمساعدة' }).waitFor();
      assert.equal(await f.page.getByRole('heading', { name: 'مدير النظام الشامل', exact: true }).count(), kind === 'system' ? 1 : 0);
      if (kind === 'stats') assert.equal(await f.page.locator('#main-content a[href^="/reception"]').count(), 0);
      if (kind === 'reader') {
        assert.equal((await api(f.token, 'settings', 'PUT', { facility_id: fixture.facilities[0], name_ar: 'forbidden', lock_version: 1, idle_minutes: 2 })).status, 403);
        assert.equal((await api(f.token, `guide?facility_id=${fixture.facilities[1]}`)).status, 403);
      }
    } finally { await f.context.close(); }
  }
});

test('responsive real portals, long text, settings validation and user/role dialogs at 390/768/1440', async () => {
  const f = await open('admin', '/settings');
  try {
    for (const width of [390, 768, 1440]) {
      await f.page.setViewportSize({ width, height: 950 });
      for (const path of ['/settings', '/guide', '/users', '/reception', '/reception-admin', '/statistics', '/dashboard/general']) {
        await f.page.goto(`${base}${path}?facility_id=${fixture.facilities[0]}`);
        await f.page.locator('#main-content').waitFor();
        await f.page.waitForLoadState('networkidle');
        assert.ok(await f.page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${path} ${width} overflow`);
        await f.page.screenshot({ path: `test-results/settings/${path.replaceAll('/', '-')}-${width}.png` });
        if (path === '/reception') {
          await f.page.getByRole('button', { name: 'تسجيل مريض جديد', exact: true }).click();
          await f.page.getByLabel('الاسم الأول', { exact: true }).fill('اسم عربي تجريبي طويل لمراجعة تنسيق الحقول');
          await f.page.getByRole('button', { name: 'حفظ البطاقة والزيارة الأولى', exact: true }).click();
          await f.page.locator('[aria-invalid="true"]').first().waitFor();
          await f.page.screenshot({ path: `test-results/settings/reception-validation-${width}.png` });
        }
        if (path === '/users') {
          await f.page.getByRole('button', { name: 'إضافة مستخدم', exact: true }).click();
          await f.page.getByRole('dialog').waitFor();
          await f.page.screenshot({ path: `test-results/settings/user-dialog-${width}.png` });
          await f.page.getByRole('button', { name: 'إنشاء دور جديد وتحديد صلاحياته' }).click();
          await f.page.getByRole('dialog').last().waitFor();
          await f.page.screenshot({ path: `test-results/settings/role-dialog-${width}.png` });
        }
      }
    }
  } finally { await f.context.close(); }
});
