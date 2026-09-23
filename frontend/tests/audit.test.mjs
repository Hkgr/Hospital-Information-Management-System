import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3000";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("Only local audit test targets are permitted");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

const access = [{ facility: { id: 3, code: "FAC-3", name_ar: "منشأة السجل", timezone: "Asia/Damascus" }, roles: [], permissions: ["audit.view"] }];
const event = {
  id: 9, occurred_at: "2026-09-22T11:00:00+03:00", actor: { id: 11, name: "مستخدم الاختبار" },
  category: "technical", category_label: "خطأ تقني", entity: "system_error", entity_label: "خطأ تقني",
  entity_id: 0, action: "failed", action_label: "خطأ تقني", reason: null,
  changes: [{ field: "message", label: "الرسالة", before: null, after: "تعذّر إتمام العملية.", before_recorded: false }],
};

async function setup() {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce", timezoneId: "Asia/Damascus" });
  const page = await context.newPage();
  const calls = [], errors = [];
  page.on("pageerror", error => errors.push(error.message));
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "audit-token"));
  await page.route("**/*", route => {
    const url = new URL(route.request().url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push(url.pathname + url.search);
    if (url.pathname === "/hospital-api/user") return route.fulfill({ json: { data: { user: { id: 11, staff_id: null, username: "audit-user", name: "مستخدم الاختبار", email: null, must_change_password: false, last_login_at: null }, access } } });
    if (url.pathname === "/hospital-api/audit") {
      assert.equal(url.searchParams.get("facility_id"), "3");
      return route.fulfill({ json: { data: [event], meta: { page: 1, per_page: 10, total: 1, last_page: 1 }, filters: { categories: { technical: "خطأ تقني" }, entities: { system_error: "خطأ تقني" }, actions: { failed: "خطأ تقني" } }, timezone: "Asia/Damascus" } });
    }
    if (url.pathname === "/hospital-api/audit/9") {
      assert.equal(url.searchParams.get("facility_id"), "3");
      return route.fulfill({ json: { data: event } });
    }
    throw new Error(`Unexpected API call ${url.pathname}`);
  });
  return { context, page, calls, errors };
}

test("system log table lists badges and opens a unique activity page", async () => {
  const { context, page, calls, errors } = await setup();
  try {
    await page.goto(`${base}/audit?facility_id=3`);
    await page.getByRole("heading", { name: "السجل", exact: true }).waitFor();
    await page.getByRole("heading", { name: "سجل الحركة" }).waitFor();
    const table = page.getByRole("region", { name: "جدول سجل الحركة" });
    await table.getByRole("columnheader", { name: "التصنيف" }).waitFor();
    await table.getByText("مستخدم الاختبار", { exact: true }).waitFor();
    await table.locator("[data-kind='technical']").waitFor();
    await table.locator("[data-kind='failed']").waitFor();
    await table.locator("tbody tr").first().click();
    await page.waitForURL(/\/audit\/9\?facility_id=3/);
    await page.getByRole("heading", { name: "خطأ تقني", exact: true }).waitFor();
    await page.locator("dd").filter({ hasText: "مستخدم الاختبار" }).waitFor();
    await page.getByText("تعذّر إتمام العملية.").first().waitFor();
    assert.ok(calls.some(path => path.startsWith("/hospital-api/audit?facility_id=3")));
    assert.ok(calls.some(path => path.startsWith("/hospital-api/audit/9?facility_id=3")));
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});
