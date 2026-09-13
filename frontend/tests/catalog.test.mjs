import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { mkdir } from "node:fs/promises";
import { facility, user, paginated } from "./clinic-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3105";
assert.ok(["localhost", "127.0.0.1"].includes(new URL(base).hostname));
let browser;
before(async () => { browser = await chromium.launch({ channel: "chrome" }); });
after(async () => { await browser?.close(); });
const rowOf = kind => ({ id: 1, kind, code: kind === "service" ? "S001" : "P001", name_ar: kind === "service" ? "خدمة اختبار" : "إجراء اختبار", description: "الوصف الأصلي", category_id: kind === "service" ? 1 : null, procedure_type_id: null, is_active: true, archived_at: null, lock_version: 1, patient_count: 2, patient_count_definition: "مرضى فريدون ضمن المنشأة، دون الملغى والمسودة." });
const capabilities = { create: true, update: true, delete: true, export: true, beneficiaries: true, audit: true };
const deferred = () => { let resolve; const promise = new Promise(r => { resolve = r; }); return { resolve, promise }; };
async function setup({ limited = false, width = 1440, capOverrides = {} } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 960 } });
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "synthetic-ui-token"));
  const page = await context.newPage(); page.setDefaultTimeout(8000);
  const rows = [rowOf("service"), rowOf("procedure")]; const calls = []; let conflict = false, reloadFail = false, failSearch = false, gate = null, detailGate = null;
  const caps = { ...(limited ? { ...capabilities, create: false, update: false, delete: false, beneficiaries: false, audit: false } : capabilities), ...capOverrides };
  await page.route("**/*", async route => {
    const req = route.request(), url = new URL(req.url()), path = url.pathname;
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!path.startsWith("/hospital-api/")) return route.continue();
    const body = req.postDataJSON(); calls.push({ path, method: req.method(), url, body });
    if (path.endsWith("/user")) return route.fulfill({ json: { data: { user, access: [1, 2].map(id => ({ facility: { ...facility, id }, roles: [], permissions: ["catalog.view", "catalog.export", ...limited ? [] : ["catalog.beneficiaries", "catalog.audit"]] })) } } });
    if (path.endsWith("/classifications")) return route.fulfill({ json: { data: { categories: [{ id: 1, name_ar: "فئة اختبار" }], procedure_types: [] } } });
    if (path.includes("/export/")) return route.fulfill({ body: "synthetic report", contentType: "application/pdf", headers: { "Content-Disposition": 'attachment; filename="test.pdf"' } });
    const kind = path.includes("/procedure/") ? "procedure" : "service", row = rows.find(row => row.kind === kind);
    if (path.endsWith("/beneficiaries")) return route.fulfill({ json: paginated([{ id: 1, patient_code: "PAT01", first_name: "مريض", family_name: "اختبار" }]) });
    if (path.endsWith("/history")) return route.fulfill({ json: paginated([{ id: 1, event: "created", occurred_at: "2026-09-13 12:00:00", old_values: null, new_values: JSON.stringify({ code: row.code }) }]) });
    if (path.endsWith("/deletion-preview")) return route.fulfill({ json: { data: { action: "archive", has_references: true, lock_version: row.lock_version, archived: false } } });
    if (/\/(archive|restore|reactivate|deactivate)$/.test(path)) { const action = path.split("/").at(-1); row.is_active = action === "reactivate"; row.archived_at = action === "archive" ? "2026-09-13" : null; row.lock_version++; return route.fulfill({ json: { data: row } }); }
    if (req.method() === "PUT") {
      if (conflict) { conflict = false; return route.fulfill({ status: 409, json: { error: { code: "CATALOG_VERSION_CONFLICT", message: "تعارض" } } }); }
      Object.assign(row, body, { lock_version: row.lock_version + 1 }); return route.fulfill({ json: { data: row } });
    }
    if (req.method() === "POST") return route.fulfill({ status: 422, json: { message: "تحقق", errors: { code: ["الكود مستخدم بالفعل"] } } });
    if (/\/(service|procedure)\/1$/.test(path)) {
      if (detailGate) { const current = detailGate; detailGate = null; current.started.resolve(); await current.promise; }
      if (reloadFail) return route.fulfill({ status: 500, json: { error: { code: "CATALOG_UNAVAILABLE", message: "تعذّر التحميل" } } });
      return route.fulfill({ json: { data: row, capabilities: caps } });
    }
    if (path === "/hospital-api/service-catalog") {
      if (gate) { const current = gate; gate = null; current.started.resolve(); await current.promise; }
      if (failSearch && url.searchParams.get("search")) return route.fulfill({ status: 500, json: { error: { code: "CATALOG_UNAVAILABLE", message: "تعذّر التحميل" } } });
      const selected = rows.filter(row => (!url.searchParams.get("kind") || row.kind === url.searchParams.get("kind")) && (!url.searchParams.get("search") || row.name_ar.includes(url.searchParams.get("search"))));
      try { return await route.fulfill({ json: { ...paginated(selected, Number(url.searchParams.get("page") || 1)), capabilities: caps } }); } catch { return; }
    }
    return route.fulfill({ status: 404, json: { error: { code: "NOT_FOUND", message: "غير موجود" } } });
  });
  await page.goto(`${base}/services-procedures?facility_id=1`); await page.getByRole("link", { name: "S001", exact: true }).waitFor();
  await page.evaluate(async () => { await document.fonts.ready; await Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => {}))); });
  return { page, rows, calls, setConflict: value => { conflict = value; }, reloadFail: value => { reloadFail = value; }, failSearch: value => { failSearch = value; }, hold: () => { gate = { ...deferred(), started: deferred() }; return gate; }, holdDetail: () => { detailGate = { ...deferred(), started: deferred() }; return detailGate; }, close: () => context.close() };
}

for (const kind of ["service", "procedure"]) test(`${kind}: independent archive requires delete, not update, in list and details`, async () => {
  const s = await setup({ capOverrides: { update: false } }); try {
    const row = s.rows.find(row => row.kind === kind);
    await s.page.getByText(`إجراءات ${row.name_ar}`, { exact: true }).click();
    assert.equal(await s.page.getByRole("button", { name: "تعديل", exact: true }).count(), 0);
    await s.page.getByRole("button", { name: "أرشفة", exact: true }).waitFor();
    await s.page.getByRole("link", { name: row.code, exact: true }).click();
    await s.page.getByText(`إجراءات ${row.name_ar}`, { exact: true }).click();
    await s.page.getByRole("button", { name: "أرشفة", exact: true }).click();
    const dialog = s.page.getByRole("dialog"); await dialog.getByText(/تغيير حالته يسري على جميع المنشآت/).waitFor();
    await dialog.getByRole("button", { name: /تأكيد أرشفة/ }).click(); await dialog.waitFor({ state: "hidden" });
    await s.page.getByText("مؤرشف", { exact: true }).waitFor();
    assert.equal(s.calls.filter(call => call.path.endsWith("/archive")).length, 1);
    assert.equal(s.calls.find(call => call.path.endsWith("/archive")).body.lock_version, 1);
    assert.equal(s.calls.filter(call => call.path.endsWith("/deletion-preview")).length, 0);
    assert.equal(await s.page.getByRole("button", { name: "أرشفة", exact: true }).count(), 0);
  } finally { await s.close(); }
  const denied = await setup({ capOverrides: { delete: false, update: true } }); try {
    await denied.page.getByText(`إجراءات ${rowOf(kind).name_ar}`, { exact: true }).click();
    assert.equal(await denied.page.getByRole("button", { name: "أرشفة", exact: true }).count(), 0);
    await denied.page.getByRole("button", { name: "تعديل", exact: true }).waitFor();
  } finally { await denied.close(); }
});

test("typed directory uses distinct identities, filters, and explicit create kinds; validation preserves draft", async () => {
  const s = await setup(); try {
    assert.equal(await s.page.getByRole("link", { name: "الخدمات والإجراءات", exact: true }).count(), 1);
    for (const [name, kind] of [["خدمة", "service"], ["إجراء", "procedure"]]) {
      await s.page.getByRole("button", { name: `إضافة ${name}`, exact: true }).click();
      const dialog = s.page.getByRole("dialog"); await dialog.getByLabel("الكود *", { exact: true }).fill("DUP"); await dialog.getByLabel("الاسم *", { exact: true }).fill("مسودة باقية");
      if (kind === "service") await dialog.getByRole("combobox", { name: /فئة الخدمة/ }).selectOption("1");
      await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await dialog.getByText("الكود مستخدم بالفعل", { exact: true }).waitFor();
      assert.equal(s.calls.filter(c => c.method === "POST").at(-1).body.kind, kind); assert.equal(await dialog.getByLabel("الاسم *", { exact: true }).inputValue(), "مسودة باقية"); await s.page.keyboard.press("Escape");
    }
    await s.page.getByRole("combobox", { name: "النوع", exact: true }).selectOption("procedure"); await s.page.getByRole("link", { name: "S001", exact: true }).waitFor({ state: "hidden" });
    await s.page.getByRole("link", { name: "P001", exact: true }).waitFor();
  } finally { await s.close(); }
});

test("two-user conflict reload failure, explicit field review, repeated conflict and reopening preserve current data", async () => {
  const s = await setup(); try {
    await s.page.getByText("إجراءات خدمة اختبار", { exact: true }).click(); await s.page.getByRole("button", { name: "تعديل", exact: true }).click();
    const dialog = s.page.getByRole("dialog"); await dialog.getByRole("textbox", { name: "الوصف", exact: true }).fill("مسودتي المهمة");
    s.rows[0].name_ar = "اسم المستخدم الآخر"; s.rows[0].lock_version = 2; s.setConflict(true);
    await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await dialog.getByRole("button", { name: "جلب أحدث نسخة" }).waitFor();
    s.reloadFail(true); await dialog.getByRole("button", { name: "جلب أحدث نسخة" }).click(); await dialog.getByText(/تعذّر جلب أحدث نسخة/).waitFor();
    assert.equal(await dialog.getByRole("textbox", { name: "الوصف", exact: true }).inputValue(), "مسودتي المهمة");
    s.reloadFail(false); await dialog.getByRole("button", { name: "جلب أحدث نسخة" }).click(); await dialog.getByLabel("تطبيق مسودتي: الوصف", { exact: true }).check();
    await dialog.getByRole("button", { name: "اعتماد الاختيارات للمراجعة" }).click(); assert.equal(await dialog.getByLabel("الاسم *", { exact: true }).inputValue(), "اسم المستخدم الآخر");
    s.setConflict(true); s.rows[0].lock_version = 3; await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await dialog.getByRole("button", { name: "جلب أحدث نسخة" }).click();
    await dialog.getByLabel("تطبيق مسودتي: الوصف", { exact: true }).check(); await dialog.getByRole("button", { name: "اعتماد الاختيارات للمراجعة" }).click(); await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await dialog.waitFor({ state: "hidden" });
    const body = s.calls.filter(c => c.method === "PUT").at(-1).body; assert.equal(body.name_ar, "اسم المستخدم الآخر"); assert.equal(body.description, "مسودتي المهمة"); assert.equal(body.lock_version, 3); assert.equal(body.kind, undefined);
    if (!await s.page.getByRole("button", { name: "تعديل", exact: true }).isVisible()) await s.page.getByText("إجراءات اسم المستخدم الآخر", { exact: true }).click();
    await s.page.getByRole("button", { name: "تعديل", exact: true }).click(); assert.equal(await s.page.getByRole("dialog").getByRole("textbox", { name: "الوصف", exact: true }).inputValue(), "مسودتي المهمة");
  } finally { await s.close(); }
});

test("pending and failed current list retain old rows but block reports until successful retry", async () => {
  const s = await setup(); try {
    const gate = s.hold(); s.failSearch(true); await s.page.getByRole("searchbox").fill("خدمة"); await gate.started.promise;
    assert.equal(await s.page.getByRole("button", { name: "Excel", exact: true }).isDisabled(), true); await s.page.getByRole("link", { name: "P001", exact: true }).waitFor(); await s.page.getByText(/جارٍ تحديث النتائج/).waitFor();
    gate.resolve(); await s.page.getByText(/المعروض نتائج سابقة/).waitFor(); assert.equal(await s.page.getByRole("button", { name: "PDF", exact: true }).isDisabled(), true); assert.equal(s.calls.filter(c => c.path.includes("/export/")).length, 0);
    s.failSearch(false); await s.page.getByRole("button", { name: "إعادة التحميل", exact: true }).click(); await s.page.getByRole("link", { name: "P001", exact: true }).waitFor({ state: "hidden" });
    await Promise.all([s.page.waitForRequest(request => request.url().includes("/export/pdf")), s.page.getByRole("button", { name: "PDF", exact: true }).click()]);
    assert.equal(s.calls.filter(c => c.path.includes("/export/")).at(-1).url.searchParams.get("search"), "خدمة");
  } finally { await s.close(); }
});

test("beneficiary identities require capability; detail navigation and actual history restore filters", async () => {
  const limited = await setup({ limited: true }); try { assert.equal(await limited.page.getByRole("button", { name: /المستفيدون من/ }).count(), 0); assert.equal(await limited.page.getByRole("button", { name: "إضافة خدمة", exact: true }).count(), 0); } finally { await limited.close(); }
  const s = await setup(); try {
    await s.page.evaluate(() => history.pushState(null, "", "/services-procedures?facility_id=1&search=خدمة&kind=service")); await s.page.getByRole("link", { name: "P001", exact: true }).waitFor({ state: "hidden" });
    await s.page.getByRole("link", { name: "S001", exact: true }).click(); await s.page.getByRole("heading", { name: "خدمة اختبار", exact: true }).waitFor();
    await s.page.getByRole("button", { name: "المستفيدون من خدمة اختبار: 2", exact: true }).click(); await s.page.getByText("PAT01", { exact: true }).waitFor(); await s.page.keyboard.press("Escape");
    await s.page.getByRole("link", { name: "العودة إلى الخدمات والإجراءات", exact: true }).click(); assert.equal(await s.page.getByRole("searchbox").inputValue(), "خدمة");
    await s.page.getByRole("searchbox").fill("قيمة معلقة"); await s.page.goBack(); await s.page.waitForTimeout(500); assert.ok(!s.page.url().includes(encodeURIComponent("قيمة معلقة")));
  } finally { await s.close(); }
});

test("late search cannot replace a newer result, and facility changes cancel pending work", async () => {
  const s = await setup(); try {
    const b = s.hold(); await s.page.getByRole("searchbox").fill("خدمة"); await b.started.promise;
    const c = s.hold(); await s.page.getByRole("searchbox").fill("إجراء"); await c.started.promise;
    b.resolve(); await s.page.waitForTimeout(100); assert.equal(await s.page.getByRole("button", { name: "Excel", exact: true }).isDisabled(), true);
    c.resolve(); await s.page.getByRole("link", { name: "S001", exact: true }).waitFor({ state: "hidden" });
    await Promise.all([s.page.waitForRequest(r => r.url().includes("/export/pdf")), s.page.getByRole("button", { name: "PDF", exact: true }).click()]);
    assert.equal(s.calls.filter(c => c.path.includes("/export/")).at(-1).url.searchParams.get("search"), "إجراء");
    const old = s.hold(); await s.page.getByRole("searchbox").fill("خدمة"); await old.started.promise;
    await s.page.getByRole("combobox", { name: "المنشأة", exact: true }).selectOption("2");
    await s.page.getByRole("link", { name: "S001", exact: true }).waitFor(); old.resolve(); await s.page.waitForTimeout(400);
    assert.equal(await s.page.getByRole("searchbox").inputValue(), ""); assert.equal(new URL(s.page.url()).searchParams.get("facility_id"), "2");
    await s.page.getByRole("link", { name: "P001", exact: true }).waitFor();
  } finally { await s.close(); }
});

test("closed conflict reload cannot update a reopened editor; save revision blocks export", async () => {
  const s = await setup(); try {
    await s.page.getByText("إجراءات خدمة اختبار", { exact: true }).click(); await s.page.getByRole("button", { name: "تعديل", exact: true }).click();
    s.setConflict(true); await s.page.getByRole("dialog").getByRole("button", { name: "حفظ التعريف" }).click();
    const held = s.holdDetail(); await s.page.getByRole("button", { name: "جلب أحدث نسخة" }).click(); await held.started.promise;
    await s.page.keyboard.press("Escape"); await s.page.getByRole("button", { name: "تعديل", exact: true }).click();
    const dialog = s.page.getByRole("dialog"); await dialog.getByRole("textbox", { name: "الاسم *", exact: true }).fill("مسودة النافذة الجديدة");
    held.resolve(); await s.page.waitForTimeout(100); assert.equal(await dialog.getByRole("textbox", { name: "الاسم *", exact: true }).inputValue(), "مسودة النافذة الجديدة");
    assert.equal(await dialog.getByRole("button", { name: "جلب أحدث نسخة" }).count(), 0);
    const refresh = s.hold(); await dialog.getByRole("button", { name: "حفظ التعريف" }).click(); await refresh.started.promise;
    assert.equal(await s.page.getByRole("button", { name: "Excel", exact: true }).isDisabled(), true);
    refresh.resolve(); await s.page.getByRole("cell", { name: "مسودة النافذة الجديدة", exact: true }).waitFor();
    assert.equal(await s.page.getByRole("button", { name: "Excel", exact: true }).isEnabled(), true);
  } finally { await s.close(); }
});

for (const width of [390, 768, 1440]) test(`catalog list/editor are readable and keyboard accessible at ${width}px`, async () => {
  const s = await setup({ width }); try {
    await s.page.waitForFunction(() => !document.getAnimations().some(animation => animation.playState === "running"));
    const frame = await s.page.evaluate(() => ({ header: document.querySelector("header").getBoundingClientRect().toJSON(), main: document.querySelector("main").getBoundingClientRect().toJSON(), heading: document.querySelector("main h2").getBoundingClientRect().toJSON() }));
    assert.ok(frame.heading.right <= frame.main.right, JSON.stringify(frame));
    await mkdir(".superdesign/catalog-review", { recursive: true }); await s.page.screenshot({ path: `.superdesign/catalog-review/list-${width}.png`, fullPage: true });
    assert.equal(await s.page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
    await s.page.getByRole("button", { name: "إضافة خدمة", exact: true }).click(); await s.page.getByRole("dialog").getByLabel("الكود *", { exact: true }).waitFor(); await s.page.screenshot({ path: `.superdesign/catalog-review/editor-${width}.png`, fullPage: true });
    await s.page.keyboard.press("Escape"); await s.page.getByRole("dialog").waitFor({ state: "hidden" }); assert.equal(await s.page.getByRole("button", { name: "إضافة خدمة", exact: true }).evaluate(el => el === document.activeElement), true);
  } finally { await s.close(); }
});
