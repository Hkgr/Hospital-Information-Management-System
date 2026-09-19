import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { facility, user, paginated } from "./clinic-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3105";
assert.ok(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
let browser;
before(async () => { browser = await chromium.launch({ channel: "chrome" }); });
after(async () => { await browser?.close(); });

const caps = { manage: true, receive: true, adjust: false, issue: false, return: false, export: false };
const store = { id: 1, facility_id: 1, code: "PH", name_ar: "صيدلية الاختبار", location: "الأرضي", is_active: true, archived_at: null, lock_version: 1 };
const receipt = { id: 4, facility_id: 1, store_id: 1, store_name_ar: "صيدلية الاختبار", receipt_no: "R-100", supplier_id: 1, supplier_name_ar: "مورد", funding_source_id: 1, funding_name_ar: "وزارة الصحة", received_on: "2026-09-16", invoice_number: null, status: "draft", note: null, confirmed_at: null, lock_version: 1,
  items: [{ id: 9, medication_id: 2, medication_code: "M1", medication_name_ar: "دواء اختبار", batch_number: "B9", expiry_date: "2027-01-01", manufactured_on: null, quantity: "10.0000", free_quantity: "2.0000", unit_cost: null, note: null, batch_id: null }] };

test("receipts list, create draft and confirm", async () => {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "synthetic-stock-token"));
  const page = await context.newPage();
  const calls = [];
  await page.route("**/*", async route => {
    const req = route.request(), url = new URL(req.url()), path = url.pathname;
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!path.startsWith("/hospital-api/")) return route.continue();
    calls.push({ path, method: req.method(), body: req.postDataJSON() });
    if (path.endsWith("/user")) return route.fulfill({ json: { data: { user, access: [{ facility, roles: [], permissions: ["stock.view", "stock.receive", "stock.suppliers.manage"] }] } } });
    if (path.endsWith("/options")) return route.fulfill({ json: { data: { stores: [{ id: 1, code: "PH", name_ar: "صيدلية الاختبار" }], suppliers: [{ id: 1, code: "S", name_ar: "مورد" }], medications: [{ id: 2, code: "M1", name_ar: "دواء اختبار" }], funding_sources: [{ id: 1, code: "F", name_ar: "وزارة الصحة" }] } } });
    if (path === "/hospital-api/stock/receipts" && req.method() === "GET") return route.fulfill({ json: { ...paginated([receipt]), capabilities: caps } });
    if (path === "/hospital-api/stock/receipts" && req.method() === "POST") return route.fulfill({ status: 201, json: { data: { ...receipt, receipt_no: req.postDataJSON().receipt_no } } });
    if (path.endsWith("/confirm")) { receipt.status = "confirmed"; receipt.confirmed_at = "2026-09-16T12:00:00Z"; receipt.items[0].batch_id = 11; return route.fulfill({ json: { data: receipt } }); }
    if (/\/stock\/receipts\/\d+$/.test(path)) return route.fulfill({ json: { data: receipt, capabilities: caps } });
    return route.fulfill({ status: 404, json: { error: { code: "STOCK_NOT_FOUND", message: "غير موجود" } } });
  });
  await page.goto(`${base}/stock/receipts?facility_id=1`);
  await page.getByRole("link", { name: "R-100" }).waitFor();
  await page.getByRole("button", { name: "إذن جديد" }).click();
  await page.getByLabel("رقم الإذن").fill("R-200");
  await page.getByLabel("رقم الدفعة").fill("B9");
  await page.getByLabel("تاريخ الانتهاء").fill("2027-01-01");
  await page.getByRole("button", { name: "حفظ المسودة" }).click();
  await page.waitForURL(/\/stock\/receipts\/4/);
  assert.equal(calls.some(call => call.path === "/hospital-api/stock/receipts" && call.method === "POST" && call.body.request_id), true);
  await page.getByRole("button", { name: "تأكيد الاستلام" }).click();
  await page.getByRole("button", { name: "تأكيد الاستلام" }).waitFor({ state: "detached" });
  assert.equal(calls.some(call => call.path.endsWith("/confirm")), true);
  await context.close();
});
