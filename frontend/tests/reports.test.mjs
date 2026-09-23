import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3000";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("Only local report test targets are permitted");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

const access = [{ facility: { id: 3, code: "FAC-3", name_ar: "منشأة التقارير", timezone: "Asia/Damascus" }, roles: [], permissions: ["reports.view", "reports.export", "dossiers.view", "clinics.view"] }];
const snapshot = {
  facility: { id: 3, code: "FAC-3", name_ar: "منشأة التقارير", timezone: "Asia/Damascus" },
  period: { key: "day", label: "اليوم · 2026-09-22", starts_on: "2026-09-22", ends_on: "2026-09-22" },
  counters: [{ key: "visits", label: "الزيارات", value: 4 }],
  visit_status: [{ key: "complete", label: "مكتملة", value: 3 }, { key: "draft", label: "مسودة", value: 1 }],
  series: [{ key: "2026-09-22", label: "2026-09-22", value: 4 }],
  mix: [{ key: "services", label: "الخدمات", value: 2 }],
  clinics: [{ id: 8, name_ar: "عيادة النشاط", visit_count: 3 }],
  doctors: [],
  procedures: [{ id: 2, name_ar: "إجراء النشاط", visit_count: 2 }],
  patients: [{ id: 11, dossier_id: 21, patient_code: "P-21", patient_name: "سامي التقرير", visit_count: 2, last_visit_on: "2026-09-22" }],
  patients_definition: "مرضى ظهرت لهم زيارة غير ملغاة بتاريخ داخل الفترة المحددة في المنشأة.",
};

async function setup() {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, reducedMotion: "reduce", acceptDownloads: true });
  const page = await context.newPage();
  const calls = [], errors = [];
  page.on("pageerror", error => errors.push(error.message));
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "report-token"));
  await page.route("**/*", route => {
    const url = new URL(route.request().url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push(url.pathname + url.search);
    if (url.pathname === "/hospital-api/user") {
      return route.fulfill({ json: { data: { user: { id: 11, staff_id: null, username: "report-user", name: "مستخدم الاختبار", email: null, must_change_password: false, last_login_at: null }, access } } });
    }
    if (url.pathname === "/hospital-api/reports") {
      assert.equal(url.searchParams.get("facility_id"), "3");
      const period = url.searchParams.get("period");
      const body = { ...snapshot, period: { ...snapshot.period, key: period, label: period === "week" ? "آخر 7 أيام · 2026-09-16 — 2026-09-22" : period === "custom" ? "فترة مخصصة · 2026-09-01 — 2026-09-10" : snapshot.period.label } };
      if (period === "week") body.series = ["2026-09-16", "2026-09-17", "2026-09-18", "2026-09-19", "2026-09-20", "2026-09-21", "2026-09-22"].map((day, index) => ({ key: day, label: day, value: index }));
      if (period === "custom") {
        assert.equal(url.searchParams.get("from"), "2026-09-01");
        assert.equal(url.searchParams.get("to"), "2026-09-10");
      }
      return route.fulfill({ json: { data: body } });
    }
    if (url.pathname === "/hospital-api/reports/export/pdf") {
      assert.equal(url.searchParams.get("facility_id"), "3");
      return route.fulfill({
        status: 200,
        headers: { "Content-Type": "application/pdf", "Content-Disposition": 'attachment; filename="RP-3-2026-000001.pdf"' },
        body: "%PDF-1.4 mock",
      });
    }
    throw new Error(`Unexpected API call ${url.pathname}`);
  });
  return { context, page, calls, errors };
}

test("reports hub switches day week and custom windows then exports pdf", async () => {
  const { context, page, calls, errors } = await setup();
  try {
    await page.goto(`${base}/reports?facility_id=3&period=day`);
    await page.getByRole("heading", { name: "تقارير", exact: true }).waitFor();
    await page.getByRole("heading", { name: "نشاط المنشأة حسب الفترة" }).waitFor();
    await page.getByText("اليوم · 2026-09-22").waitFor();
    await page.getByLabel("الزيارات: 4").waitFor();
    await page.getByRole("img", { name: /توزيع الزيارات/ }).waitFor();
    await page.getByRole("link", { name: "عيادة النشاط" }).first().waitFor();
    await page.getByRole("region", { name: "جدول المرضى" }).getByText("سامي التقرير").waitFor();
    const group = page.getByRole("radiogroup", { name: "الفترة الزمنية" });
    await group.getByRole("radio", { name: "أسبوع" }).click();
    await page.getByText("آخر 7 أيام · 2026-09-16 — 2026-09-22").waitFor();
    await page.getByRole("img", { name: "الزيارات حسب اليوم" }).waitFor();
    await group.getByRole("radio", { name: "فترة مخصصة" }).click();
    await page.getByLabel("من تاريخ").fill("2026-09-01");
    await page.getByLabel("إلى تاريخ").fill("2026-09-10");
    await page.getByText("فترة مخصصة · 2026-09-01 — 2026-09-10").waitFor();
    const [download] = await Promise.all([
      page.waitForEvent("download"),
      page.getByRole("button", { name: "تصدير PDF" }).click(),
    ]);
    assert.equal(download.suggestedFilename(), "RP-3-2026-000001.pdf");
    assert.ok(calls.some(path => path.startsWith("/hospital-api/reports?facility_id=3&period=day")));
    assert.ok(calls.some(path => path.startsWith("/hospital-api/reports?facility_id=3&period=week")));
    assert.ok(calls.some(path => path.includes("period=custom&from=2026-09-01&to=2026-09-10") || path.includes("period=custom") && path.includes("from=2026-09-01")));
    assert.ok(calls.some(path => path.startsWith("/hospital-api/reports/export/pdf?")));
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});
