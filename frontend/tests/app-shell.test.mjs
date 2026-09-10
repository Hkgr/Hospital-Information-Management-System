import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { dashboardFixture } from "./dashboard-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3000";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) {
  throw new Error("Shell tests require a local server; production targets are forbidden.");
}
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

async function openShell({ width = 1440, height = 900, logoutFailure = false, clock } = {}) {
  const context = await browser.newContext({ viewport: { width, height }, reducedMotion: "reduce", timezoneId: "America/Los_Angeles" });
  const page = await context.newPage();
  if (clock) await page.clock.install({ time: new Date(clock) });
  const requests = [], errors = [];
  page.on("pageerror", error => errors.push(error.message));
  page.on("console", message => { if (/hydration|hydrated|server rendered/i.test(message.text()) && message.type() === "error") errors.push(message.text()); });
  page.on("response", response => { if (response.status() >= 400 && !response.url().includes("/hospital-api/")) errors.push(String(response.status())); });
  await page.route("**/*", route => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (url.pathname.startsWith("/hospital-api/")) {
      requests.push({ path: url.pathname, authorization: request.headers().authorization });
      if (url.pathname.startsWith("/hospital-api/dashboards")) {
        const fixture = dashboardFixture({ id: 11, name: "مستخدم الاختبار", username: "shell-test", must_change_password: false });
        return route.fulfill({ json: { data: url.pathname.endsWith("/general") ? fixture.detail : fixture.catalog } });
      }
      if (url.pathname.endsWith("/user")) return route.fulfill({ json: { data: { user: { id: 11, staff_id: null, username: "shell-test", name: "مستخدم الاختبار", email: null, must_change_password: false, last_login_at: null }, access: [] } } });
      if (url.pathname.endsWith("/logout")) return logoutFailure ? route.abort() : route.fulfill({ status: 204 });
      throw new Error("Unexpected API call in layout test");
    }
    return route.continue();
  });
  // Test fixture only. Production components use the unchanged auth client.
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "shell-test-token"));
  await page.goto(`${base}/dashboard/general`);
  await page.getByRole("heading", { name: "لوحة التحكم", exact: true }).waitFor();
  await page.getByRole("heading", { name: "مرحبًا، مستخدم الاختبار", exact: true }).waitFor();
  await page.evaluate(() => document.fonts.ready);
  return { context, page, requests, errors };
}

test("RTL joined header/brand/sidebar and footer geometry at all six requested widths", async () => {
  const { context, page, requests, errors } = await openShell();
  try {
    const initialRequests = requests.length; // Development StrictMode can replay the existing auth effect.
    for (const width of [320, 390, 768, 1024, 1440, 1920]) {
      await page.setViewportSize({ width, height: 900 });
      const sidebarWidth = width >= 1280 ? 268 : width >= 768 ? 84 : 0;
      await page.waitForFunction(expected => Math.round(document.querySelector("header").getBoundingClientRect().width) === innerWidth - expected, sidebarWidth);
      await page.waitForFunction(() => [...document.images].filter(image => image.getBoundingClientRect().width > 0).every(image => image.complete && image.naturalWidth > 0));
      const geometry = await page.evaluate(() => {
        const rect = selector => { const r = document.querySelector(selector).getBoundingClientRect(); return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom }; };
        return { dir: getComputedStyle(document.documentElement).direction, scrollWidth: document.documentElement.scrollWidth,
          sidebar: rect("aside"), brand: rect("aside > div"), header: rect("header"), main: rect("#main-content"), footer: rect("footer"),
          personal: document.querySelector("#welcome-heading") !== null,
          images: [...document.images].filter(image => image.getBoundingClientRect().width > 0).every(image => image.complete && image.naturalWidth > 0),
          seamless: ["header", "footer", "aside", "aside > div", "main"].every(selector => {
            const css = getComputedStyle(document.querySelector(selector));
            return [css.borderTopWidth, css.borderRightWidth, css.borderBottomWidth, css.borderLeftWidth].every(width => width === "0px");
          }),
          corner: getComputedStyle(document.querySelector("main")).borderStartStartRadius,
          frame: ["header", "footer", "aside"].map(selector => getComputedStyle(document.querySelector(selector)).backgroundColor),
          font: getComputedStyle(document.querySelector("header")).fontFamily };
      });
      assert.equal(geometry.dir, "rtl");
      assert.ok(geometry.scrollWidth <= width);
      assert.equal(geometry.header.x, 0);
      assert.equal(geometry.header.y, 0);
      assert.equal(geometry.footer.x, 0);
      assert.equal(geometry.footer.width, width - sidebarWidth);
      assert.equal(geometry.footer.bottom, 900);
      assert.ok(geometry.main.y >= geometry.header.bottom);
      assert.equal(geometry.personal, true);
      assert.equal(geometry.images, true);
      assert.equal(geometry.seamless, true);
      assert.equal(geometry.corner, width < 768 ? "16px" : "20px");
      assert.deepEqual(geometry.frame, ["rgb(11, 97, 120)", "rgb(11, 97, 120)", "rgb(11, 97, 120)"]);
      assert.match(geometry.font, /Cairo/);
      if (sidebarWidth) {
        assert.equal(geometry.sidebar.x, width - sidebarWidth);
        assert.equal(geometry.sidebar.height, 900);
        assert.equal(geometry.brand.y, 0);
        assert.equal(geometry.brand.height, geometry.header.height);
        assert.equal(geometry.header.right, geometry.sidebar.x);
        assert.ok(geometry.main.right <= geometry.sidebar.x);
      }
    }
    assert.ok(initialRequests >= 1);
    assert.equal(requests.length, initialRequests);
    assert.ok(requests.every(request => request.authorization === "Bearer shell-test-token"));
    assert.equal(requests[0].authorization, "Bearer shell-test-token");
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test("Damascus clock ignores device timezone, updates across midnight and fits narrow headers without hydration errors", async () => {
  const { context, page, errors } = await openShell({ clock: "2026-09-10T20:59:30Z" });
  try {
    const clock = page.getByRole("group", { name: "التاريخ والوقت بتوقيت دمشق" });
    const expected = date => ({
      date: new Intl.DateTimeFormat("ar-SY", { timeZone: "Asia/Damascus", weekday: "long", day: "numeric", month: "long", year: "numeric" }).format(date),
      time: new Intl.DateTimeFormat("ar-SY", { timeZone: "Asia/Damascus", hour: "2-digit", minute: "2-digit", hour12: true }).format(date),
    });
    const before = expected(new Date("2026-09-10T20:59:30Z"));
    assert.equal(await clock.locator("time").first().locator("span:visible").innerText(), before.date);
    assert.equal(await clock.locator("time").last().innerText(), before.time);
    await page.clock.runFor(60_000);
    const after = expected(new Date("2026-09-10T21:00:30Z"));
    assert.notEqual(before.date, after.date);
    assert.notEqual(before.time, after.time);
    await page.waitForFunction(text => document.querySelector('header time:last-child')?.textContent === text, after.time);
    assert.equal(await clock.locator("time").first().locator("span:visible").innerText(), after.date);
    for (const width of [390, 320]) {
      await page.setViewportSize({ width, height: 844 });
      assert.equal(await clock.locator("time").first().isVisible(), width === 390);
      assert.equal(await clock.locator("time").last().isVisible(), true);
      const clockBox = await clock.boundingBox();
      const heading = await page.getByRole("heading", { name: "لوحة التحكم", exact: true }).boundingBox();
      const notification = await page.getByRole("button", { name: "التنبيهات — غير متاحة بعد" }).boundingBox();
      assert.ok(clockBox.x + clockBox.width <= heading.x);
      assert.ok(notification.x + notification.width <= clockBox.x);
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    }
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test("desktop and tablet collapse controls retain usable navigation names without inventing routes", async () => {
  const { context, page } = await openShell();
  try {
    const nav = page.getByRole("navigation", { name: "التنقل الرئيسي" });
    const labels = ["الإضبارات", "المرضى", "الأطباء والعيادات", "الخدمات والإجراءات", "الأدوية", "تقارير", "السجل"];
    assert.equal(await nav.getByRole("link").count(), 0);
    assert.equal(await nav.locator("button:disabled").count(), labels.length);
    assert.deepEqual(await nav.getByRole("button").evaluateAll(items => items.map(item => item.getAttribute("aria-label"))), labels.map(label => `${label} — قريبًا، غير متاح بعد`));
    assert.deepEqual(await nav.locator("small").allTextContents(), labels.map(() => "قريبًا"));
    const brand = page.getByRole("complementary").getByRole("link", { name: "مشفى محمد بن زايد الإماراتي — لوحة التحكم" });
    assert.equal(await brand.getAttribute("href"), "/");
    await page.getByRole("button", { name: "طي القائمة الجانبية" }).click();
    assert.equal((await page.getByRole("complementary").boundingBox()).width, 84);
    for (const label of labels) assert.equal(await nav.getByRole("button", { name: `${label} — قريبًا، غير متاح بعد`, exact: true }).isVisible(), true);
    await page.getByRole("button", { name: "توسيع القائمة الجانبية" }).focus();
    await page.keyboard.press("Enter");
    assert.equal((await page.getByRole("complementary").boundingBox()).width, 268);
    await page.setViewportSize({ width: 1024, height: 900 });
    assert.equal((await page.getByRole("complementary").boundingBox()).width, 268); // User choice retained.
    await page.setViewportSize({ width: 768, height: 900 });
    const clock = await page.getByRole("group", { name: "التاريخ والوقت بتوقيت دمشق" }).boundingBox();
    const heading = await page.getByRole("heading", { name: "لوحة التحكم", exact: true }).boundingBox();
    assert.ok(clock.x + clock.width <= heading.x);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.getByRole("button", { name: "طي القائمة الجانبية" }).click();
    assert.equal((await page.getByRole("complementary").boundingBox()).width, 84);
    assert.equal(await page.locator('a[href="#"]').count(), 0);
    await brand.click();
    await page.waitForURL(`${base}/dashboard/general`);
    await page.getByRole("heading", { name: "مرحبًا، مستخدم الاختبار" }).waitFor();
  } finally { await context.close(); }
});

test("collapse and expansion keep frame edges synchronized without overflow, and reduced motion skips interpolation", async () => {
  const { context, page } = await openShell();
  try {
    const sidebar = page.getByRole("complementary");
    const logo = sidebar.locator('img[src="/brand/logos/logo-ar-white.svg"]');
    const mark = sidebar.locator('img[src="/brand/logos/mark-white.svg"]');
    assert.equal((await logo.boundingBox()).width, 140);
    assert.equal(await logo.evaluate(el => getComputedStyle(el).opacity), "1");
    const sampleToggle = () => page.evaluate(async () => {
      const samples = [];
      const toggle = document.querySelector('aside button[aria-controls="desktop-navigation"]');
      toggle.click();
      for (let frame = 0; frame < 90; frame++) {
        await new Promise(requestAnimationFrame);
        const side = document.querySelector("aside").getBoundingClientRect();
        const nav = document.querySelector("aside nav");
        const iconsContained = [...nav.querySelectorAll("button")].every(button => {
          const item = button.getBoundingClientRect(), icon = button.querySelector("svg").getBoundingClientRect();
          return item.left >= side.left && item.right <= side.right && icon.left >= item.left && icon.right <= item.right;
        });
        samples.push({ width: side.width, edges: ["header", "main", "footer"].map(selector => document.querySelector(selector).getBoundingClientRect().right - side.left), overflow: document.documentElement.scrollWidth > innerWidth || nav.scrollWidth > nav.clientWidth, iconsContained });
        if (frame > 1 && !document.getAnimations().some(animation => animation.playState === "running")) break;
      }
      return samples;
    });
    const assertJoined = samples => {
      assert.ok(samples.every(sample => !sample.overflow && sample.iconsContained && sample.edges.every(gap => Math.abs(gap) < 1)), "Frame parts and navigation icons must stay contained throughout the transition");
    };
    const reduced = await sampleToggle();
    assertJoined(reduced);
    assert.ok(reduced.every(sample => sample.width === 84));
    assert.equal(await mark.evaluate(el => getComputedStyle(el).opacity), "1");
    assert.equal((await mark.boundingBox()).width, 42);
    await page.emulateMedia({ reducedMotion: "no-preference" });
    for (const target of [268, 84]) {
      const samples = await sampleToggle();
      assertJoined(samples);
      assert.ok(samples.some(sample => sample.width > 85 && sample.width < 267), "Motion should interpolate rather than jump");
      assert.equal(samples.at(-1).width, target);
    }
    assert.equal(await sidebar.getByRole("button", { name: "توسيع القائمة الجانبية" }).evaluate(el => document.elementFromPoint(el.getBoundingClientRect().x + 20, el.getBoundingClientRect().y + 20)?.closest("button") === el), true);
  } finally { await context.close(); }
});

test("mobile drawer traps focus, restores it, dismisses with Escape/backdrop/navigation and closes on resize", async () => {
  const { context, page } = await openShell({ width: 390, height: 700 });
  try {
    const menu = page.getByRole("button", { name: "فتح قائمة التنقل" });
    const dialog = page.getByRole("dialog", { name: "قائمة التنقل" });
    await menu.click(); await dialog.waitFor();
    assert.equal(await page.evaluate(() => document.body.style.overflow), "hidden");
    const rect = await dialog.boundingBox();
    assert.equal(rect.x + rect.width, 390);
    for (let i = 0; i < 7; i++) {
      await page.keyboard.press("Tab");
      assert.equal(await dialog.evaluate(el => el.contains(document.activeElement)), true);
    }
    await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(() => document.body.style.overflow === "");
    assert.equal(await menu.evaluate(el => document.activeElement === el), true);
    assert.equal(await page.evaluate(() => document.body.style.overflow), "");
    await menu.click(); await page.mouse.click(20, 150);
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(() => document.body.style.overflow === "");
    await menu.click(); await dialog.getByRole("link", { name: "مشفى محمد بن زايد الإماراتي — لوحة التحكم", exact: true }).click();
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(() => document.body.style.overflow === "");
    await menu.click(); await page.setViewportSize({ width: 1024, height: 900 });
    await dialog.waitFor({ state: "hidden" });
    await page.waitForFunction(() => document.body.style.overflow === "");
    assert.equal(await page.evaluate(() => document.body.style.overflow), "");
    await page.setViewportSize({ width: 320, height: 500 });
    assert.equal(await menu.getAttribute("aria-expanded"), "false");
    await menu.click();
    await dialog.getByRole("button", { name: "السجل — قريبًا، غير متاح بعد" }).scrollIntoViewIfNeeded();
    const lastItem = await dialog.getByRole("button", { name: "السجل — قريبًا، غير متاح بعد" }).boundingBox();
    assert.ok(lastItem.y >= 80 && lastItem.y + lastItem.height <= 440);
    await dialog.getByRole("button", { name: "إغلاق قائمة التنقل" }).click();
    await dialog.waitFor({ state: "hidden" });
  } finally { await context.close(); }
});

test("long and wide child content scrolls within the canvas without covering sidebar or footer", async () => {
  const { context, page } = await openShell();
  try {
    // A DOM-only geometry probe, never a shipped dashboard widget or route.
    await page.locator("#main-content > div").evaluate(canvas => {
      const probe = document.createElement("div");
      probe.style.height = "2400px"; probe.style.width = "2400px";
      probe.textContent = "Layout test probe"; canvas.append(probe);
    });
    for (const width of [1440, 390]) {
      await page.setViewportSize({ width, height: 700 });
      const sizes = await page.evaluate(() => {
        const canvas = document.querySelector("#main-content > div");
        return { viewport: innerWidth, document: document.documentElement.scrollWidth,
          canvasClient: canvas.clientWidth, canvasScroll: canvas.scrollWidth,
          mainHeight: document.querySelector("main").getBoundingClientRect().height,
          footerTop: document.querySelector("footer").getBoundingClientRect().top + scrollY };
      });
      assert.ok(sizes.document <= sizes.viewport);
      assert.ok(sizes.canvasScroll > sizes.canvasClient);
      assert.ok(sizes.mainHeight >= 2400 && sizes.footerTop > 2400);
      await page.getByRole("contentinfo").scrollIntoViewIfNeeded();
      assert.equal((await page.getByRole("banner").boundingBox()).y, 0);
    }
  } finally { await context.close(); }
});

test("account disclosure is keyboard accessible, uses current-user data and calls the existing logout", async () => {
  const { context, page, requests } = await openShell({ width: 320, height: 600 });
  try {
    const account = page.getByRole("button", { name: "حساب مستخدم الاختبار" });
    await account.focus(); await page.keyboard.press("Enter");
    await page.getByRole("region", { name: "الحساب", exact: true }).waitFor();
    await page.keyboard.press("Escape");
    assert.equal(await account.getAttribute("aria-expanded"), "false");
    assert.equal(await account.evaluate(el => document.activeElement === el), true);
    await account.click();
    const panel = await page.getByRole("region", { name: "الحساب", exact: true }).boundingBox();
    assert.ok(panel.x >= 0 && panel.x + panel.width <= 320);
    await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).click();
    await page.waitForURL(`${base}/login`);
    assert.equal(requests.filter(request => request.path.endsWith("/logout")).length, 1);
    assert.equal(requests.at(-1).authorization, "Bearer shell-test-token");
    assert.equal(await page.evaluate(() => sessionStorage.getItem("hospital.bearer")), null);
    assert.equal(await page.getByRole("banner").count(), 0);
    assert.equal(await page.getByRole("complementary").count(), 0);
  } finally { await context.close(); }
});

test("failed logout shows its error and retains the existing session for retry", async () => {
  const { context, page } = await openShell({ logoutFailure: true });
  try {
    await page.getByRole("button", { name: "حساب مستخدم الاختبار" }).click();
    await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).click();
    await page.getByRole("alert").filter({ hasText: "تعذّر الاتصال بالخادم" }).waitFor();
    assert.equal(await page.getByRole("button", { name: "تسجيل الخروج", exact: true }).isEnabled(), true);
    assert.equal(await page.evaluate(() => sessionStorage.getItem("hospital.bearer") !== null), true);
    assert.equal(await page.getByRole("heading", { name: "لوحة التحكم", exact: true }).isVisible(), true);
  } finally { await context.close(); }
});
