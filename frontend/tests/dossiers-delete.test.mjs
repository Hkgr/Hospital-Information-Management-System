import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3101";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("Dossier UI tests require a local target.");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

const facility = { id: 4, code: "DOS-A", name_ar: "منشأة البطاقات", timezone: "Asia/Damascus" };
const user = { id: 8, staff_id: null, username: "card-admin", name: "مستخدم البطاقات", email: null, must_change_password: false, last_login_at: null };
const row = {
  id: 91, card_id: 5, code: "DOS-001", opening_date: "2020-01-01", status: "active", patient_code: "P001",
  patient_name: "مريض اختباري", is_oncology: false, visit_count: 1, latest_visit_id: 3, latest_visit_status: "complete",
  latest_visit_date: "2026-01-01", procedure_count: 0, pathology_status: "not_assessed", pathology_visit_id: null,
  mother_name: null, gender: "male", birth_date: "1990-01-01", birth_date_accuracy: "exact", phone: null,
  paper_file_number: null, legacy_without_visits: false, diagnoses: [],
  workflow: { subsequent_create: false, personal_update: false, medical_update: false, resume_section: null, visit: { id: null, action: null, label: null } },
};

async function openCards({ deleteAllowed = true } = {}) {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce" });
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "dossier-ui-fixture"));
  const page = await context.newPage();
  const calls = [];
  let rows = [row];
  await page.route("**/*", async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push({ method: request.method(), path: url.pathname });
    if (url.pathname.endsWith("/user")) return route.fulfill({ json: { data: { user, access: [{ facility, roles: [], permissions: ["dossiers.view", ...(deleteAllowed ? ["dossiers.delete"] : [])] }] } } });
    if (url.pathname.endsWith("/dossiers/options")) return route.fulfill({ json: { data: { today: "2026-09-22", capabilities: { delete: deleteAllowed, create: false, export: false, audit: false, treatment_view: false, import_view: false }, creation: { allowed: false, reason: "لا توجد صلاحية إضافة." }, governorates: [] } } });
    if (url.pathname.match(/\/dossiers\/\d+$/) && request.method() === "DELETE") {
      rows = [];
      return route.fulfill({ status: 204, body: "" });
    }
    if (url.pathname.endsWith("/dossiers")) return route.fulfill({ json: { data: rows, meta: { page: 1, per_page: 20, total: rows.length, last_page: 1 }, totals: { dossiers: rows.length } } });
    return route.fulfill({ json: { data: [] } });
  });
  await page.goto(`${base}/patient-cards`);
  return { page, context, calls };
}

test("patient card actions include delete when permitted and confirm before the request", async () => {
  const { page, context, calls } = await openCards();
  try {
    await page.getByText("DOS-001", { exact: true }).waitFor();
    await page.getByRole("button", { name: "حذف DOS-001" }).click();
    await page.getByRole("dialog", { name: "حذف بطاقة DOS-001" }).waitFor();
    assert.equal(calls.some(call => call.method === "DELETE"), false);
    await page.getByRole("button", { name: "تأكيد الحذف" }).click();
    await page.getByRole("dialog", { name: "حذف بطاقة DOS-001" }).waitFor({ state: "hidden" });
    assert.equal(calls.some(call => call.method === "DELETE" && call.path.endsWith("/dossiers/91")), true);
    await page.getByText("لا توجد بطاقات مرضى مطابقة.").waitFor();
  } finally { await context.close(); }
});

test("patient card delete stays hidden without dossiers.delete", async () => {
  const { page, context } = await openCards({ deleteAllowed: false });
  try {
    await page.getByText("DOS-001", { exact: true }).waitFor();
    assert.equal(await page.getByRole("button", { name: "حذف DOS-001" }).count(), 0);
  } finally { await context.close(); }
});
