import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { dashboardFixture } from "./dashboard-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3000";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("Only local dashboard test targets are permitted");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });
const users = {
  first: { id: 1, name: "المستخدم الأول", username: "first", must_change_password: false },
  second: { id: 2, name: "المستخدم الثاني", username: "second", must_change_password: false },
};
const facilities = [1, 2].map(id => ({ id, code: `FAC-${id}`, name_ar: `المنشأة ${id}`, timezone: "Asia/Damascus" }));

async function setup(override = () => false) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce" });
  const page = await context.newPage();
  const calls = [], external = [], errors = [];
  page.on("pageerror", error => errors.push(error.message));
  await context.addInitScript(() => { if (!sessionStorage.getItem("hospital.bearer")) sessionStorage.setItem("hospital.bearer", "first"); });
  await page.route("**/*", async route => {
    const url = new URL(route.request().url());
    if (url.origin !== new URL(base).origin) { external.push(url.href); return route.abort(); }
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push(url.pathname + url.search);
    const user = route.request().headers().authorization === "Bearer second" ? users.second : users.first;
    if (await override(route, url, user)) return;
    if (url.pathname === "/hospital-api/user") return route.fulfill({ json: { data: { user, access: [] } } });
    if (url.pathname === "/hospital-api/logout") return route.fulfill({ status: 204 });
    const fixture = dashboardFixture(user, facilities);
    if (url.pathname === "/hospital-api/dashboards") return route.fulfill({ json: { data: fixture.catalog } });
    if (url.pathname === "/hospital-api/dashboards/general") {
      const id = url.searchParams.has("facility_id") ? Number(url.searchParams.get("facility_id")) : null;
      return route.fulfill({ json: { data: { ...fixture.detail, selected_facility_id: id, facilities: id ? facilities.filter(f => f.id === id) : facilities } } });
    }
    return route.fulfill({ status: 404, json: { error: { code: "DASHBOARD_NOT_FOUND" } } });
  });
  return { context, page, calls, errors, external };
}

test("entry resolves the allowed local default; direct links and reload verify access; one dashboard has no switcher", async () => {
  const { context, page, calls, external, errors } = await setup();
  try {
    await page.goto(`${base}/?next=https://attacker.invalid&returnTo=//attacker.invalid`);
    await page.waitForURL(`${base}/dashboard/general`);
    await page.getByRole("heading", { name: "مرحبًا، المستخدم الأول" }).waitFor();
    assert.equal(await page.getByRole("combobox").count(), 0);
    const reads = calls.filter(path => path === "/hospital-api/dashboards/general").length;
    await page.reload();
    await page.getByRole("heading", { name: "مرحبًا، المستخدم الأول" }).waitFor();
    assert.ok(calls.filter(path => path === "/hospital-api/dashboards/general").length > reads);
    assert.deepEqual(external, []); assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test("empty or unimplemented/malicious defaults stay local without redirect loops", async () => {
  for (const key of [null, "https://attacker.invalid", "//attacker.invalid", "__proto__", "future-dashboard"]) {
    const { context, page, calls, external } = await setup(async (route, url) => {
      if (url.pathname !== "/hospital-api/dashboards") return false;
      await route.fulfill({ json: { data: { dashboards: key ? [{ key, title: "Unsupported", requires_facility: false, facilities: [], default_facility_id: null }] : [], default_dashboard_key: key } } });
      return true;
    });
    try {
      await page.goto(base);
      await page.getByRole("heading", { name: "لا توجد لوحة تحكم متاحة" }).waitFor();
      assert.equal(new URL(page.url()).pathname, "/");
      assert.equal(calls.filter(path => path === "/hospital-api/dashboards").length, 1);
      assert.deepEqual(external, []);
    } finally { await context.close(); }
  }
});

test("network retry and dashboard 403/404 never disclose old data or log out an active user", async () => {
  let failure = "network";
  const { context, page } = await setup(async (route, url) => {
    if (url.pathname !== "/hospital-api/dashboards/general" || !failure) return false;
    if (failure === "network") await route.abort();
    else await route.fulfill({ status: Number(failure), json: { error: { code: failure === "403" ? "DASHBOARD_ACCESS_DENIED" : "DASHBOARD_NOT_FOUND" } } });
    return true;
  });
  try {
    for (const mode of ["network", "403", "404"]) {
      failure = mode;
      await page.goto(`${base}/dashboard/general`);
      await page.locator("#main-content").getByRole("alert").waitFor();
      assert.equal(await page.locator("#welcome-heading").count(), 0);
      assert.equal(await page.evaluate(() => sessionStorage.getItem("hospital.bearer")), "first");
    }
    failure = "";
    await page.getByRole("button", { name: "إعادة المحاولة", exact: true }).click();
    await page.getByRole("heading", { name: "مرحبًا، المستخدم الأول" }).waitFor();
  } finally { await context.close(); }
});

test("dashboard 401 and ACCOUNT_INACTIVE clear the shell and sensitive session immediately", async () => {
  for (const [status, code] of [[401, "UNAUTHENTICATED"], [403, "ACCOUNT_INACTIVE"]]) {
    const { context, page } = await setup(async (route, url) => {
      if (url.pathname !== "/hospital-api/dashboards/general") return false;
      await route.fulfill({ status, json: { error: { code } } }); return true;
    });
    try {
      await page.goto(`${base}/dashboard/general`);
      await page.waitForURL(`${base}/login`);
      assert.equal(await page.evaluate(() => sessionStorage.getItem("hospital.bearer")), null);
      assert.equal(await page.getByRole("complementary").count(), 0);
      assert.doesNotMatch(await page.locator("body").innerText(), /المستخدم الأول/);
    } finally { await context.close(); }
  }
});

test("switching facility cancels pending responses and never paints the previous facility", async () => {
  let release;
  let announce, finish;
  const requested = new Promise(resolve => { announce = resolve; });
  const finished = new Promise(resolve => { finish = resolve; });
  const held = new Promise(resolve => { release = resolve; });
  const { context, page } = await setup(async (route, url) => {
    if (url.pathname !== "/hospital-api/dashboards/general" || url.searchParams.get("facility_id") !== "1") return false;
    announce();
    await held;
    await route.fulfill({ json: { data: { ...dashboardFixture(users.first, [facilities[0]]).detail, selected_facility_id: 1 } } }).catch(() => {});
    finish();
    return true;
  });
  try {
    await page.goto(`${base}/dashboard/general?facility_id=1`);
    await page.getByText("جارٍ تحميل لوحة التحكم…", { exact: true }).waitFor();
    await requested;
    await page.evaluate(() => history.pushState(null, "", "/dashboard/general?facility_id=2"));
    await page.getByRole("heading", { name: "المنشأة 2", exact: true }).waitFor();
    release();
    await finished;
    await page.evaluate(() => new Promise(requestAnimationFrame));
    await page.getByRole("heading", { name: "المنشأة 2", exact: true }).waitFor();
    assert.equal(await page.getByRole("heading", { name: "المنشأة 1", exact: true }).count(), 0);
    await page.goto(`${base}/dashboard/unknown`);
    await page.locator("#main-content").getByRole("alert").waitFor();
    assert.equal(await page.locator("#welcome-heading").count(), 0);
  } finally { release(); await context.close(); }
});

test("late old-user unauthorized response cannot clear the next user session or expose old data", async () => {
  let release;
  let announce, finish;
  const requested = new Promise(resolve => { announce = resolve; });
  const finished = new Promise(resolve => { finish = resolve; });
  const held = new Promise(resolve => { release = resolve; });
  const { context, page } = await setup(async (route, url, user) => {
    if (url.pathname !== "/hospital-api/dashboards/general" || user.id !== 1) return false;
    announce();
    await held;
    await route.fulfill({ status: 401, json: { error: { code: "UNAUTHENTICATED" } } }).catch(() => {});
    finish();
    return true;
  });
  try {
    await page.goto(`${base}/dashboard/general`);
    await page.getByRole("button", { name: "حساب المستخدم الأول" }).waitFor();
    await requested;
    await page.evaluate(() => { sessionStorage.setItem("hospital.bearer", "second"); window.dispatchEvent(new Event("hospital-session-change")); });
    await page.getByRole("heading", { name: "مرحبًا، المستخدم الثاني" }).waitFor();
    release();
    await finished;
    await page.evaluate(() => new Promise(requestAnimationFrame));
    assert.doesNotMatch(await page.locator("body").innerText(), /المستخدم الأول/);
    assert.equal(await page.evaluate(() => sessionStorage.getItem("hospital.bearer")), "second");
  } finally { release(); await context.close(); }
});

test("invalid facility parameters and mismatched user payloads are not rendered", async () => {
  const { context, page } = await setup(async (route, url) => {
    if (url.pathname !== "/hospital-api/dashboards/general") return false;
    await route.fulfill({ json: { data: dashboardFixture(users.second).detail } }); return true;
  });
  try {
    for (const suffix of ["?facility_id=0", "?facility_id=1&facility_id=2", "?facility_id=99999999999999999999999", ""]) {
      await page.goto(`${base}/dashboard/general${suffix}`);
      await page.locator("#main-content").getByRole("alert").waitFor();
      assert.equal(await page.locator("#welcome-heading").count(), 0);
      assert.doesNotMatch(await page.locator("body").innerText(), /المستخدم الثاني/);
    }
  } finally { await context.close(); }
});
