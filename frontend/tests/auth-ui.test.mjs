import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { mkdir } from "node:fs/promises";
import { chromium } from "playwright";
import { dashboardFixture } from "./dashboard-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3000";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) {
  throw new Error("UI tests must target a local server; remote/production targets are forbidden.");
}
let browser;
before(async () => {
  await mkdir("test-results", { recursive: true });
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined });
});
after(async () => { await browser?.close(); });

async function pageFor({ mockDashboards = true, ...options } = {}) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, ...options });
  const page = await context.newPage();
  // Every test is isolated and denies external resources, including university APIs.
  await page.route("**/*", route => {
    if (new URL(route.request().url()).origin !== new URL(base).origin) return route.abort();
    const path = new URL(route.request().url()).pathname;
    if (mockDashboards && path.startsWith("/hospital-api/dashboards")) {
      const fixture = dashboardFixture(identity.user);
      return route.fulfill({ json: { data: path.endsWith("/general") ? fixture.detail : fixture.catalog } });
    }
    return route.continue();
  });
  return { context, page };
}
async function fill(page, username = "example-user", password = "example-password") {
  await page.getByLabel("اسم المستخدم", { exact: true }).fill(username);
  await page.getByLabel("كلمة المرور", { exact: true }).fill(password);
}
const identity = { user: { id: 1, staff_id: null, username: "example-user", name: "مستخدم الواجهة", email: null, must_change_password: true, last_login_at: null }, access: [] };

test("desktop geometry, local assets, labels, validation and keyboard password toggle", async () => {
  const { context, page } = await pageFor({ reducedMotion: "reduce" });
  try {
    const failures = [];
    const fontRequests = [];
    page.on("request", request => { if (request.resourceType() === "font") fontRequests.push(request.url()); });
    page.on("pageerror", error => failures.push(error.message));
    page.on("response", response => { if (response.status() >= 400) failures.push(String(response.status())); });
    await page.goto(`${base}/login`);
    await page.evaluate(() => document.fonts.ready);
    const geometry = await page.locator('section[aria-labelledby="login-heading"]').evaluate(el => ({ width: el.getBoundingClientRect().width, radius: getComputedStyle(el).borderRadius }));
    assert.deepEqual(geometry, { width: 440, radius: "26px" });
    assert.equal(await page.evaluate(() => [...document.images].every(i => i.complete && i.naturalWidth > 0)), true);
    assert.equal(await page.evaluate(() => document.fonts.check('14px "Cairo"', 'مشفى Hospital')), true);
    assert.equal(await page.evaluate(() => [...document.fonts].some(font => font.family.replaceAll('"', '') === "Cairo" && font.status === "loaded")), true);
    assert.match(await page.locator("body").evaluate(el => getComputedStyle(el).fontFamily), /Cairo/);
    assert.ok(fontRequests.length > 0);
    assert.ok(fontRequests.every(url => new URL(url).origin === new URL(base).origin));
    await page.screenshot({ path: "test-results/hospital-desktop.png", fullPage: true });
    await page.getByRole("button", { name: "دخول", exact: true }).click();
    assert.equal(await page.locator("form").getByRole("alert").count(), 2);
    assert.equal(await page.getByLabel("اسم المستخدم", { exact: true }).evaluate(el => document.activeElement === el), true);
    await fill(page);
    const toggle = page.getByRole("button", { name: "إظهار كلمة المرور", exact: true });
    await toggle.focus(); await toggle.press("Enter");
    assert.equal(await page.getByLabel("كلمة المرور", { exact: true }).getAttribute("type"), "text");
    await page.getByRole("button", { name: "إخفاء كلمة المرور", exact: true }).press("Space");
    assert.equal(await page.getByLabel("كلمة المرور", { exact: true }).getAttribute("type"), "password");
    assert.equal(await page.locator('a[href="#"], input[type="checkbox"], input[type="email"]').count(), 0);
    assert.deepEqual(failures, []);
  } finally { await context.close(); }
});

test("Arabic messages for 401, inactive/ability 403, 422, 429, bad server response and network failure (mocked)", async () => {
  const { context, page } = await pageFor({ reducedMotion: "reduce" });
  try {
    const cases = [
      [401, { error: { code: "INVALID_CREDENTIALS" } }, "اسم المستخدم أو كلمة المرور غير صحيحة."],
      [403, { error: { code: "ACCOUNT_INACTIVE" } }, "هذا الحساب غير فعال. راجع مسؤول النظام."],
      [403, { error: { code: "MISSING_API_ABILITY" } }, "لا يملك هذا الدخول صلاحية الوصول المطلوبة. راجع مسؤول النظام."],
      [422, { errors: { username: ["Invalid"], password: ["Invalid"] } }, "تحقق من بيانات الحقول ثم حاول مجددًا."],
      [429, {}, "تجاوزت عدد محاولات الدخول. انتظر دقيقة ثم حاول مجددًا."],
      [200, {}, "تعذّر قراءة استجابة الخادم. حاول مجددًا."],
      [500, {}, "تعذّر إتمام الطلب الآن. حاول مجددًا بعد قليل."],
      [0, null, "تعذّر الاتصال بالخادم. تحقق من اتصالك ثم حاول مجددًا."],
    ];
    for (const [status, body, message] of cases) {
      await page.route("**/hospital-api/login", route => status ? route.fulfill({ status, json: body }) : route.abort());
      await page.goto(`${base}/login`); await fill(page);
      await page.getByLabel("كلمة المرور", { exact: true }).press("Enter");
      await page.getByRole("alert").filter({ hasText: message }).waitFor();
      if (status === 422) assert.equal(await page.locator('[aria-invalid="true"]').count(), 2);
      assert.equal(await page.getByRole("button", { name: "دخول", exact: true }).isEnabled(), true);
      await page.unroute("**/hospital-api/login");
    }
  } finally { await context.close(); }
});

test("Enter submits username contract once; loading, Bearer current-user, identity and current-device logout (mocked)", async () => {
  const { context, page } = await pageFor({ reducedMotion: "reduce" });
  try {
    let logins = 0, users = 0, logouts = 0;
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    await page.route("**/hospital-api/login", async route => {
      logins++;
      assert.deepEqual(route.request().postDataJSON(), { username: "example-user", password: "example-password", device_name: "hospital-web" });
      assert.equal(route.request().headers().authorization, undefined);
      await pending;
      await route.fulfill({ json: { data: { ...identity, token: "test-only-token", token_type: "Bearer", expires_at: null } } });
    });
    await page.route("**/hospital-api/user", async route => {
      users++;
      assert.equal(route.request().headers().authorization, "Bearer test-only-token");
      await route.fulfill({ json: { data: identity } });
    });
    await page.route("**/hospital-api/logout", async route => {
      logouts++;
      assert.equal(route.request().headers().authorization, "Bearer test-only-token");
      await route.fulfill({ status: 204 });
    });
    await page.goto(`${base}/login`); await fill(page, " example-user ");
    await page.getByLabel("كلمة المرور", { exact: true }).press("Enter");
    await page.getByRole("button", { name: "جارٍ تسجيل الدخول", exact: true }).waitFor();
    assert.equal(await page.getByRole("button", { name: "جارٍ تسجيل الدخول", exact: true }).isDisabled(), true);
    await page.getByLabel("كلمة المرور", { exact: true }).press("Enter");
    await page.screenshot({ path: "test-results/hospital-loading.png" });
    assert.equal(logins, 1); release();
    await page.getByRole("button", { name: "حساب مستخدم الواجهة" }).waitFor();
    await page.getByRole("button", { name: "حساب مستخدم الواجهة" }).click();
    await page.getByText("يتطلب حسابك تغيير كلمة المرور. راجع مسؤول النظام.").waitFor();
    assert.ok(users > 0);
    assert.equal(await page.evaluate(() => localStorage.length), 0);
    assert.doesNotMatch(await page.locator("body").innerText(), /test-only-token|example-password/);
    await page.reload(); await page.getByRole("button", { name: "حساب مستخدم الواجهة" }).waitFor();
    await page.getByRole("button", { name: "حساب مستخدم الواجهة" }).click();
    await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).click();
    await page.waitForURL(`${base}/login`);
    assert.equal(logouts, 1);
    assert.equal(await page.evaluate(() => sessionStorage.length), 0);
  } finally { await context.close(); }
});

test("mobile, narrow and short screens scroll without horizontal clipping; reduced motion and touch disable tilt", async () => {
  const { context, page } = await pageFor({ viewport: { width: 390, height: 844 }, hasTouch: true, isMobile: true, reducedMotion: "reduce" });
  try {
    await page.goto(`${base}/login`);
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: "test-results/hospital-mobile.png", fullPage: true });
    for (const [width, height] of [[390, 844], [320, 480], [844, 390]]) {
      await page.setViewportSize({ width, height });
      const metrics = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth, height: innerHeight, scrollHeight: document.documentElement.scrollHeight,
        animations: document.getAnimations().filter(a => a.playState === 'running').length }));
      assert.ok(metrics.scrollWidth <= metrics.width, JSON.stringify(metrics));
      assert.equal(metrics.animations, 0);
      if (height === 390) assert.ok(metrics.scrollHeight > metrics.height);
      await page.getByRole("button", { name: "دخول", exact: true }).scrollIntoViewIfNeeded();
      const button = await page.getByRole("button", { name: "دخول", exact: true }).boundingBox();
      assert.ok(button.y >= 0 && button.y + button.height <= height);
    }
    await page.screenshot({ path: "test-results/hospital-short.png", fullPage: true });
    await page.mouse.move(500, 250);
    assert.equal(await page.locator('section[aria-labelledby="login-heading"]').evaluate(el => getComputedStyle(el.parentElement).transform), "none");
  } finally { await context.close(); }
});

test("unauthenticated root returns to the login page", async () => {
  const { context, page } = await pageFor();
  try { await page.goto(base); await page.waitForURL(`${base}/login`); }
  finally { await context.close(); }
});

test("protected API rejection clears expired or disabled-account tokens (mocked)", async () => {
  for (const [status, code] of [[401, "UNAUTHENTICATED"], [403, "ACCOUNT_INACTIVE"]]) {
    const { context, page } = await pageFor({ reducedMotion: "reduce" });
    try {
      await page.route("**/hospital-api/login", route => route.fulfill({ json: { data: { ...identity, token: "test-only-token", token_type: "Bearer" } } }));
      await page.route("**/hospital-api/user", route => route.fulfill({ status, json: { error: { code } } }));
      await page.goto(`${base}/login`); await fill(page);
      const rejectedUser = page.waitForResponse(response => response.url().endsWith("/hospital-api/user"));
      await page.getByRole("button", { name: "دخول", exact: true }).click();
      await rejectedUser;
      await page.waitForURL(`${base}/login`);
      await page.waitForFunction(() => sessionStorage.getItem("hospital.bearer") === null);
    } finally { await context.close(); }
  }
});

test("desktop pointer tilts the card while the original mark remains fixed; touch disables tilt without reduced-motion", async () => {
  const { context, page } = await pageFor({ reducedMotion: "no-preference" });
  try {
    await page.goto(`${base}/login`);
    const card = page.locator('section[aria-labelledby="login-heading"]');
    await card.waitFor();
    await page.waitForTimeout(1000); // Allow the intentional .9s entrance to finish.
    const box = await card.boundingBox();
    await page.mouse.move(box.x + box.width - 45, box.y + 100);
    await page.waitForFunction(() => {
      const element = document.querySelector('section[aria-labelledby="login-heading"]').parentElement;
      return getComputedStyle(element).transform.startsWith('matrix3d');
    });
    const mark = page.locator('img[src="/brand/logos/mark-color.svg"]');
    assert.equal(await mark.evaluate(el => getComputedStyle(el).transform), "none");
    assert.ok(await page.evaluate(() => document.getAnimations().some(a => a.playState === "running")));
  } finally { await context.close(); }
  const touch = await pageFor({ hasTouch: true, isMobile: true, reducedMotion: "no-preference" });
  try {
    await touch.page.goto(`${base}/login`);
    assert.equal(await touch.page.evaluate(() => matchMedia('(pointer: coarse)').matches), true);
    await touch.page.mouse.move(800, 300);
    assert.equal(await touch.page.locator('section[aria-labelledby="login-heading"]').evaluate(el => getComputedStyle(el.parentElement).transform), "none");
  } finally { await touch.context.close(); }
});

test("real local Laravel/MySQL login, Bearer user, logout and token revocation", { skip: process.env.AUTH_LIVE_TEST !== "1" }, async () => {
  // Opt in only after test-db:check and serving Laravel with --env=testing.
  // Uses an EXISTING local/testing seeder account; never creates users or schemas.
  const { context, page } = await pageFor({ reducedMotion: "reduce", mockDashboards: false });
  let token;
  try {
    await page.goto(`${base}/login`);
    await fill(page, "demo", "password");
    const loginResponse = page.waitForResponse(r => r.url().endsWith("/hospital-api/login"));
    await page.getByRole("button", { name: "دخول", exact: true }).click();
    const response = await loginResponse;
    assert.equal(response.status(), 200);
    const body = await response.json();
    token = body.data.token;
    assert.ok(typeof token === "string" && token.length > 20);
    assert.equal(body.data.token_type, "Bearer");
    const user = await context.request.get(`${base}/hospital-api/user`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
    assert.equal(user.status(), 200);
    assert.equal((await user.json()).data.user.username, "demo");
    await page.waitForURL(`${base}/dashboard/general`);
    await page.getByRole("heading", { name: "مرحبًا، Test User" }).waitFor();
    await page.reload();
    await page.getByRole("heading", { name: "مرحبًا، Test User" }).waitFor();
    await page.goto(`${base}/dashboard/general`);
    await page.getByRole("heading", { name: "مرحبًا، Test User" }).waitFor();
    await page.getByRole("button", { name: "حساب Test User" }).waitFor();
    await page.getByRole("button", { name: "حساب Test User" }).click();
    const logoutResponse = page.waitForResponse(r => r.url().endsWith("/hospital-api/logout"));
    await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).click();
    assert.equal((await logoutResponse).status(), 204);
    await page.waitForURL(`${base}/login`);
    const revoked = await context.request.get(`${base}/hospital-api/user`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
    assert.equal(revoked.status(), 401);
    const revokedDashboard = await context.request.get(`${base}/hospital-api/dashboards/general`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
    assert.equal(revokedDashboard.status(), 401);
  } finally {
    if (token) await context.request.post(`${base}/hospital-api/logout`, { headers: { Authorization: `Bearer ${token}`, Accept: "application/json" } });
    await context.close();
  }
});
