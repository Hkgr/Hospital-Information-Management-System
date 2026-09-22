import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3101";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("User UI tests require a local target.");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

const facility = { id: 1, code: "USR-A", name_ar: "منشأة المستخدمين", timezone: "Asia/Damascus" };
const user = { id: 11, staff_id: null, username: "admin-user", name: "مدير الاختبار", email: null, must_change_password: false, last_login_at: null };
const roles = [{ id: 3, code: "NURSE", name_ar: "ممرض" }];
const members = [
  { id: 11, username: "admin-user", name: "مدير الاختبار", email: null, is_active: true, last_login_at: null, roles: [{ id: 2, code: "ADMIN", name_ar: "مدير" }] },
  { id: 22, username: "nurse-one", name: "ممرض اختباري", email: null, is_active: true, last_login_at: null, roles },
];

function paginated(data) { return { data, meta: { page: 1, per_page: 20, total: data.length, last_page: 1 } }; }

async function setup({ permissions = ["users.view", "users.create", "users.delete"], override = () => false } = {}) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce" });
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "users-ui-fixture"));
  const page = await context.newPage();
  const calls = [];
  let rows = [...members];
  await page.route("**/*", async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push({ url, method: request.method(), body: request.postDataJSON() });
    if (await override(route, url)) return;
    if (url.pathname.endsWith("/user")) return route.fulfill({ json: { data: { user, access: [{ facility, roles: [], permissions }] } } });
    if (url.pathname.endsWith("/users/options")) return route.fulfill({ json: { data: { roles, capabilities: { view: permissions.includes("users.view"), create: permissions.includes("users.create"), delete: permissions.includes("users.delete") } } } });
    if (url.pathname.match(/\/users\/\d+$/) && request.method() === "DELETE") {
      rows = rows.filter(row => row.id !== Number(url.pathname.split("/").at(-1)));
      return route.fulfill({ status: 204, body: "" });
    }
    if (url.pathname.endsWith("/users") && request.method() === "POST") {
      const body = request.postDataJSON();
      const created = { id: 33, username: body.username, name: body.name, email: body.email, is_active: true, last_login_at: null, roles };
      rows = [...rows, created];
      return route.fulfill({ status: 201, json: { data: created } });
    }
    return route.fulfill({ json: paginated(rows) });
  });
  await page.goto(`${base}/users`);
  return { page, context, calls, getRows: () => rows };
}

test("navbar users item and add/delete members when permitted", async () => {
  const { page, context, calls } = await setup();
  try {
    await page.getByRole("heading", { name: "إدارة المستخدمين", exact: true }).waitFor();
    assert.equal(await page.locator("#desktop-navigation").getByRole("link", { name: "المستخدمون", exact: true }).getAttribute("href"), "/users");
    await page.getByRole("button", { name: "إضافة مستخدم" }).click();
    await page.getByRole("dialog", { name: "إضافة مستخدم" }).waitFor();
    await page.getByLabel("اسم المستخدم *").fill("new-nurse");
    await page.getByLabel("الاسم *").fill("مستخدم جديد");
    await page.getByLabel("كلمة المرور *").fill("secret-pass");
    await page.getByRole("button", { name: "حفظ المستخدم" }).click();
    await page.getByText("new-nurse").waitFor();
    assert.equal(calls.some(call => call.method === "POST" && call.url.pathname.endsWith("/users")), true);
    await page.getByRole("button", { name: "حذف nurse-one" }).click();
    await page.getByRole("dialog", { name: "حذف nurse-one" }).waitFor();
    await page.getByRole("button", { name: "تأكيد الحذف" }).click();
    await page.getByRole("dialog", { name: "حذف nurse-one" }).waitFor({ state: "hidden" });
    assert.equal(await page.getByText("nurse-one").count(), 0);
    assert.equal(calls.some(call => call.method === "DELETE" && call.url.pathname.endsWith("/users/22")), true);
  } finally { await context.close(); }
});

test("create and delete controls stay hidden without write permissions", async () => {
  const { page, context } = await setup({ permissions: ["users.view"] });
  try {
    await page.getByRole("heading", { name: "إدارة المستخدمين", exact: true }).waitFor();
    assert.equal(await page.getByRole("button", { name: "إضافة مستخدم" }).count(), 0);
    assert.equal(await page.getByRole("button", { name: "حذف nurse-one" }).count(), 0);
    assert.equal(await page.getByText("ممرض اختباري").count(), 1);
  } finally { await context.close(); }
});
