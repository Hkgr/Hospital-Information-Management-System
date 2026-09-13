import assert from "node:assert/strict";
import { before, after, test } from "node:test";
import { readFileSync } from "node:fs";
import { mkdir } from "node:fs/promises";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { chromium } from "playwright";

// No page.route, fake API responses or direct Laravel HTTP calls in this suite.
const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3105";
assert.ok(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
const backend = fileURLToPath(new URL("../../backend/", import.meta.url));
let f, browser;
function fixture(mode) {
  const result = spawnSync("php", ["tests/Support/catalog-live.php", mode], { cwd: backend, env: { ...process.env, APP_ENV: "testing" }, encoding: "utf8", timeout: 30000 });
  assert.equal(result.status, 0, `Safe fixture ${mode}: ${result.stdout}\n${result.stderr}`); process.stdout.write(result.stdout);
}
before(async () => { fixture("prepare"); f = JSON.parse(readFileSync(new URL("../../backend/storage/framework/testing/catalog-live.json", import.meta.url))); browser = await chromium.launch({ channel: "chrome" }); });
after(async () => { try { await browser?.close(); } finally { if (f) fixture("cleanup"); } });

async function api(method, path, expected, body, token = f.token) {
  const url = new URL(`/hospital-api/service-catalog${path}`, base);
  if (method === "GET") { url.searchParams.set("facility_id", String(body?.facility_id ?? f.facility)); for (const [key, value] of Object.entries(body ?? {})) url.searchParams.set(key, String(value)); }
  const response = await fetch(url, { method, redirect: "error", signal: AbortSignal.timeout(30000), headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) },
    body: method === "GET" ? undefined : JSON.stringify({ facility_id: f.facility, ...body }) });
  assert.equal(response.status, expected, `${method} ${path}`); assert.equal(response.headers.get("x-test-laravel"), "directory-routing"); assert.match(response.headers.get("cache-control"), /no-store/);
  if (expected === 204) { assert.equal(await response.text(), ""); return; }
  if (/application\/pdf|spreadsheetml/.test(response.headers.get("content-type"))) { assert.ok((await response.arrayBuffer()).byteLength > 1000); return; }
  assert.match(response.headers.get("content-type"), /application\/json/); return response.json();
}

for (const kind of ["service", "procedure"]) test(`${kind}: actual standalone transport, count/list parity, CRUD, lifecycle, permissions and exports`, async () => {
  const id = f.items[kind][1], path = `/${kind}/${id}`, count = kind === "service" ? 2 : 3;
  const row = (await api("GET", path, 200)).data; assert.equal(row.patient_count, count);
  const people = await api("GET", `${path}/beneficiaries`, 200); assert.equal(people.meta.total, count); assert.equal(new Set(people.data.map(row => row.id)).size, count);
  assert.equal((await api("GET", `${path}/beneficiaries`, 200, { search: `${f.tag}-P2` })).meta.total, 1);
  assert.equal((await api("GET", path, 401, undefined, null)).error.code, "UNAUTHENTICATED");
  assert.equal((await api("GET", `${path}/beneficiaries`, 403, undefined, f.viewer_token)).error.code, "CATALOG_ACCESS_DENIED");
  await api("GET", path, 403, { facility_id: f.other });
  await api("POST", `${path}/archive`, 403, { lock_version: 1 }, f.viewer_token);
  await api("POST", `${path}/archive`, 422, {});
  const input = { kind, code: `LIVE-${kind}-${f.tag}`, name_ar: `تعريف حي ${kind}`, is_active: true, ...(kind === "service" ? { category_id: f.category } : {}) };
  const created = (await api("POST", "", 201, input)).data;
  await api("POST", "", 422, input);
  const { kind: ignored, ...update } = input; assert.equal(ignored, kind);
  const updated = (await api("PUT", `/${kind}/${created.id}`, 200, { ...update, name_ar: "تم التعديل", lock_version: 1 })).data;
  assert.equal(updated.lock_version, 2); assert.equal(updated.name_ar, "تم التعديل");
  await api("PUT", `/${kind}/${created.id}`, 409, { ...update, lock_version: 1 });
  assert.equal((await api("GET", `/${kind}/${created.id}/deletion-preview`, 200)).data.action, "delete");
  await api("DELETE", `/${kind}/${created.id}`, 204, { lock_version: 2 }); await api("GET", `/${kind}/${created.id}`, 404);
  assert.equal((await api("GET", `${path}/deletion-preview`, 200)).data.action, "archive");
  await api("DELETE", path, 409, { lock_version: 1 });
  for (const [index, action] of ["deactivate", "reactivate", "archive", "restore", "reactivate"].entries()) {
    const changed = (await api("POST", `${path}/${action}`, 200, { lock_version: index + 1 })).data;
    assert.equal(changed.is_active, action === "reactivate"); assert.equal(changed.patient_count, count);
    if (action !== "reactivate") assert.ok(!(await api("GET", "/options", 200, { kind, search: row.code, status: "archived" })).data.some(row => row.id === id));
  }
  const history = await api("GET", `${path}/history`, 200); assert.equal(history.meta.total, 5);
  assert.equal((await api("GET", `${path}/beneficiaries`, 200)).meta.total, count);
  await api("GET", "/export/xlsx", 200, { kind, search: row.code, page: 9 }); await api("GET", `${path}/report`, 200);
});

test("real browser creates both kinds, reads patient list/details/history and revisits filtered list", async () => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
  await context.addInitScript(token => sessionStorage.setItem("hospital.bearer", token), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  const errors = []; page.on("pageerror", e => errors.push(e.message));
  try {
    await page.goto(`${base}/services-procedures?facility_id=${f.facility}&search=${f.tag}`);
    await page.getByRole("heading", { name: "الخدمات والإجراءات", level: 2, exact: true }).waitFor();
    for (const [kind, label] of [["service", "خدمة"], ["procedure", "إجراء"]]) {
      await page.getByRole("button", { name: `إضافة ${label}`, exact: true }).click(); const dialog = page.getByRole("dialog");
      await dialog.getByRole("textbox", { name: "الكود *", exact: true }).fill(`BROWSER-${kind}-${f.tag}`);
      await dialog.getByRole("textbox", { name: "الاسم *", exact: true }).fill(`${label} متصفح ${f.tag}`);
      await dialog.getByRole("textbox", { name: "الوصف", exact: true }).fill("تعريف اختباري عبر Next وLaravel الحقيقيين");
      if (kind === "service") await dialog.getByRole("combobox", { name: /فئة الخدمة/ }).selectOption(String(f.category));
      await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await dialog.waitFor({ state: "hidden" });
      await page.getByRole("link", { name: `BROWSER-${kind}-${f.tag}`, exact: true }).waitFor();
    }
    await Promise.all([page.waitForResponse(response => response.url().includes("/hospital-api/service-catalog?") && new URL(response.url()).searchParams.get("kind") === "procedure" && response.status() === 200), page.getByRole("combobox", { name: "النوع", exact: true }).selectOption("procedure")]);
    await page.locator('a[href^="/services-procedures/service/"]').first().waitFor({ state: "hidden" });
    const detailLink = page.getByRole("link", { name: `${f.tag}-001`, exact: true }); await detailLink.waitFor(); await detailLink.click();
    await page.getByRole("heading", { name: "إجراء اختبار 1", exact: true }).waitFor();
    await page.getByRole("button", { name: "المستفيدون من إجراء اختبار 1: 3", exact: true }).click(); await page.getByText(`${f.tag}-P8`, { exact: true }).waitFor();
    await mkdir(".superdesign/catalog-review", { recursive: true }); await page.screenshot({ path: ".superdesign/catalog-review/live-beneficiaries.png", fullPage: true });
    await page.keyboard.press("Escape"); await page.getByRole("button", { name: "سجل التغييرات", exact: true }).click(); await page.getByRole("dialog").getByText("6 حدث مطابق", { exact: true }).waitFor(); await page.keyboard.press("Escape");
    await page.getByRole("link", { name: "العودة إلى الخدمات والإجراءات", exact: true }).click(); assert.equal(await page.getByRole("searchbox").inputValue(), f.tag);
    await page.screenshot({ path: ".superdesign/catalog-review/live-list.png", fullPage: true }); assert.deepEqual(errors, []);
  } finally { await context.close(); }
});
