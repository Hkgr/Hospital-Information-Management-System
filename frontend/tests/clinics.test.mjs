import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { mkdir, writeFile } from "node:fs/promises";
import { chromium } from "playwright";
import { clinics, doctors, facility, permissions, user, paginated } from "./clinic-fixtures.mjs";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3101";
if (!["127.0.0.1", "localhost", "[::1]"].includes(new URL(base).hostname)) throw new Error("Clinic UI tests require a local target.");
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });

async function setup({ width = 1440, access = [{ facility, permissions, roles: [] }], override = () => false } = {}) {
  const context = await browser.newContext({ viewport: { width, height: 900 }, reducedMotion: "reduce" });
  await context.addInitScript(() => sessionStorage.setItem("hospital.bearer", "clinic-ui-fixture"));
  const page = await context.newPage();
  const calls = [], errors = [];
  page.on("pageerror", error => errors.push(error.message));
  await page.route("**/*", async route => {
    const request = route.request(), url = new URL(request.url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith("/hospital-api/")) return route.continue();
    calls.push({ url, method: request.method(), body: request.postDataJSON(), auth: request.headers().authorization });
    if (await override(route, url)) return;
    if (url.pathname.endsWith("/user")) return route.fulfill({ json: { data: { user, access } } });
    if (url.pathname.endsWith("/options/specialties")) return route.fulfill({ json: { data: [{ id: 1, name_ar: "الطب الداخلي" }] } });
    if (url.pathname.endsWith("/options/doctors")) return route.fulfill({ json: { ...paginated(doctors.map(d => ({ ...d, is_linked: url.searchParams.has("clinic_id") && d.is_linked }))), doctor_types_configured: true } });
    if (url.pathname.endsWith("/doctors")) return route.fulfill({ json: paginated(doctors.slice(0, 2)) });
    const id = Number(url.pathname.match(/\/clinics\/(\d+)/)?.[1]);
    if (id) return route.fulfill({ json: { data: clinics.find(c => c.id === id) ?? clinics[0] } });
    return route.fulfill({ json: paginated(clinics) });
  });
  await page.goto(`${base}/clinics`);
  return { page, context, calls, errors };
}

test("navigation, columns, direct detail and back preserve list context", async () => {
  const { page, context, calls, errors } = await setup();
  try {
    await page.getByRole("link", { name: "001", exact: true }).waitFor();
    assert.equal(await page.locator("#desktop-navigation").getByRole("link", { name: "العيادات", exact: true }).count(), 1);
    assert.equal(await page.locator("#desktop-navigation").getByRole("link", { name: "الأطباء", exact: true }).count(), 0);
    await page.getByText("الأعمدة", { exact: true }).click();
    await page.getByRole("checkbox", { name: "التوصيف", exact: true }).uncheck();
    assert.equal(await page.getByRole("columnheader", { name: "التوصيف", exact: true }).count(), 0);
    await page.getByText("الأعمدة", { exact: true }).click();
    await page.getByLabel("البحث في العيادات").fill("الداخلية");
    await page.waitForURL(/search=/);
    await page.getByRole("link", { name: "001", exact: true }).click();
    await page.getByRole("heading", { name: "العيادة الداخلية", exact: true }).waitFor();
    assert.ok(calls.some(call => call.url.pathname === "/hospital-api/clinics/1"));
    await page.getByRole("link", { name: "العودة إلى قائمة العيادات" }).click();
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "الداخلية");
    assert.ok(calls.every(call => call.auth === "Bearer clinic-ui-fixture"));
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test("editor preserves input on field errors, applies doctor deltas, blocks double submission and restores focus", async () => {
  let writes = 0, release;
  const gate = new Promise(resolve => { release = resolve; });
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (route.request().method() !== "PUT" || !url.pathname.endsWith("/1")) return false;
    writes++; await gate;
    await route.fulfill({ status: 422, json: { message: "Validation", errors: { code: ["كود العيادة مستخدم في هذه المنشأة."] } } }); return true;
  } });
  try {
    const opener = page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true });
    await opener.click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("كود العيادة *").fill("changed");
    await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).check();
    await dialog.getByRole("checkbox", { name: /أحمد الاختباري/ }).uncheck();
    await dialog.getByRole("button", { name: "حفظ العيادة" }).click();
    assert.equal(await dialog.getByRole("button", { name: "جارٍ الحفظ…" }).isDisabled(), true);
    await page.keyboard.press("Escape");
    assert.equal(await dialog.count(), 1);
    release();
    await dialog.getByText("كود العيادة مستخدم في هذه المنشأة.").waitFor();
    assert.equal(writes, 1);
    assert.equal(await dialog.getByLabel("كود العيادة *").inputValue(), "changed");
    const body = calls.find(call => call.method === "PUT").body;
    assert.deepEqual(body.doctor_add_ids, [3]); assert.deepEqual(body.doctor_remove_ids, [1]); assert.equal(body.lock_version, 1);
    const last = dialog.getByRole("button", { name: "إلغاء", exact: true });
    await last.focus(); await page.keyboard.press("Tab");
    assert.equal(await dialog.evaluate(el => el.contains(document.activeElement)), true);
    await page.keyboard.press("Escape");
    assert.equal(await opener.evaluate(el => el === document.activeElement), true);
  } finally { release(); await context.close(); }
});

test("adding zero doctors is supported and deletion errors keep explicit deactivation separate", async () => {
  const { page, context, calls } = await setup({ override: async route => {
    if (route.request().method() !== "DELETE") return false;
    await route.fulfill({ status: 409, json: { error: { code: "CLINIC_REFERENCED" } } }); return true;
  } });
  try {
    await page.getByRole("button", { name: "إضافة عيادة جديدة", exact: true }).click();
    await page.getByLabel("كود العيادة *").fill("NEW");
    await page.getByLabel("اسم العيادة *").fill("عيادة جديدة");
    await page.getByRole("button", { name: "حفظ العيادة" }).click();
    await page.getByRole("dialog").waitFor({ state: "detached" });
    const create = calls.find(call => call.method === "POST");
    assert.deepEqual(create.body.doctor_add_ids, []); assert.equal(create.body.facility_id, 1);
    await page.getByRole("button", { name: "حذف العيادة الداخلية", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "حذف نهائي" }).click();
    await dialog.getByRole("alert").waitFor();
    assert.match(await dialog.innerText(), /يمكنك تعطيلها بإجراء منفصل/);
    await dialog.getByRole("button", { name: "إلغاء", exact: true }).click();
    assert.equal(calls.filter(call => call.url.pathname.endsWith("/deactivate")).length, 0);
    await page.getByRole("button", { name: "تعطيل العيادة الداخلية", exact: true }).click();
    await page.getByRole("dialog").getByRole("button", { name: "تعطيل العيادة", exact: true }).click();
    await page.getByRole("dialog").waitFor({ state: "detached" });
    assert.equal(calls.filter(call => call.url.pathname.endsWith("/deactivate")).length, 1);
  } finally { await context.close(); }
});

test("facility changes discard pending old results and close old editors", async () => {
  let release; const gate = new Promise(resolve => { release = resolve; });
  const access = [1, 2].map(id => ({ facility: { ...facility, id, name_ar: `منشأة ${id}` }, permissions, roles: [] }));
  const { page, context, calls } = await setup({ access, override: async (route, url) => {
    if (url.pathname !== "/hospital-api/clinics") return false;
    const id = Number(url.searchParams.get("facility_id"));
    if (id === 1) { await gate; try { await route.fulfill({ json: paginated([{ ...clinics[0], name_ar: "سياق سابق" }]) }); } catch {} }
    else await route.fulfill({ json: paginated([{ ...clinics[0], facility_id: 2, name_ar: "سياق جديد" }]) });
    return true;
  } });
  try {
    await page.getByRole("combobox", { name: "المنشأة", exact: true }).waitFor();
    await page.getByRole("button", { name: "إضافة عيادة جديدة", exact: true }).click();
    await page.keyboard.press("Escape");
    await page.getByRole("combobox", { name: "المنشأة", exact: true }).selectOption("2");
    await page.getByText("سياق جديد", { exact: true }).waitFor();
    release(); await page.waitForTimeout(350);
    assert.equal(await page.getByText("سياق سابق", { exact: true }).count(), 0);
    assert.ok(calls.some(call => call.url.searchParams.get("facility_id") === "2"));
  } finally { release(); await context.close(); }
});

test("debounced search does not resurrect a late previous query", async () => {
  let release; const gate = new Promise(resolve => { release = resolve; });
  const { page, context } = await setup({ override: async (route, url) => {
    if (url.pathname !== "/hospital-api/clinics" || !url.searchParams.has("search")) return false;
    const search = url.searchParams.get("search");
    if (search === "قديم") await gate;
    try { await route.fulfill({ json: paginated([{ ...clinics[0], name_ar: search }]) }); } catch {}
    return true;
  } });
  try {
    await page.getByLabel("البحث في العيادات").fill("قديم"); await page.waitForTimeout(450);
    await page.getByLabel("البحث في العيادات").fill("حديث");
    await page.getByText("حديث", { exact: true }).waitFor(); release(); await page.waitForTimeout(350);
    assert.equal(await page.getByText("قديم", { exact: true }).count(), 0);
  } finally { release(); await context.close(); }
});

test("server denies direct clinic URL and controls are hidden without permissions", async () => {
  const { page, context } = await setup({ access: [{ facility, permissions: ["clinics.view"], roles: [] }], override: async (route, url) => {
    if (url.pathname !== "/hospital-api/clinics/1") return false;
    await route.fulfill({ status: 403, json: { error: { code: "CLINIC_ACCESS_DENIED" } } }); return true;
  } });
  try {
    await page.getByRole("link", { name: "001", exact: true }).waitFor();
    assert.equal(await page.getByRole("button", { name: "إضافة عيادة جديدة" }).count(), 0);
    assert.equal(await page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true }).count(), 0);
    assert.equal(await page.getByRole("button", { name: "PDF", exact: true }).count(), 0);
    await page.goto(`${base}/clinics/1?facility_id=1`);
    await page.locator("main").getByRole("alert").waitFor();
    assert.equal(await page.getByRole("heading", { name: "العيادة الداخلية", exact: true }).count(), 0);
  } finally { await context.close(); }
});

test("export carries current filters and visible columns to server, downloads attachment", async () => {
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (!url.pathname.endsWith("/export/xlsx")) return false;
    await route.fulfill({ status: 200, headers: { "Content-Type": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet", "Content-Disposition": 'attachment; filename="CL-TEST.xlsx"' }, body: "fixture-bytes" }); return true;
  } });
  try {
    await page.getByText("الأعمدة", { exact: true }).click();
    await page.getByRole("checkbox", { name: "التوصيف", exact: true }).uncheck();
    await page.getByText("الأعمدة", { exact: true }).click();
    await page.getByRole("combobox", { name: "الحالة", exact: true }).selectOption("active");
    await page.waitForURL(/status=active/);
    const download = page.waitForEvent("download"); await page.getByRole("button", { name: "Excel", exact: true }).click();
    assert.equal((await download).suggestedFilename(), "CL-TEST.xlsx");
    const query = calls.find(call => call.url.pathname.endsWith("/export/xlsx")).url.searchParams;
    assert.equal(query.get("status"), "active"); assert.ok(!query.getAll("columns[]").includes("description")); assert.ok(!query.has("ids"));
  } finally { await context.close(); }
});

test("regression: version conflict keeps draft and offers explicit latest-version review", async () => {
  const latest = { ...clinics[0], code: "SERVER", description: "تعديل المستخدم الآخر", lock_version: 2 };
  let conflicted = false;
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (url.pathname === "/hospital-api/clinics/1" && route.request().method() === "PUT" && !conflicted) {
      conflicted = true;
      await route.fulfill({ status: 409, json: { error: { code: "CLINIC_VERSION_CONFLICT" } } }); return true;
    }
    if (conflicted && url.pathname === "/hospital-api/clinics/1") { await route.fulfill({ json: { data: latest } }); return true; }
    return false;
  } });
  page.setDefaultTimeout(4000);
  try {
    await page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("كود العيادة *").fill("MY-CODE");
    await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).check();
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("alert").waitFor();
    assert.equal(await dialog.getByLabel("كود العيادة *").inputValue(), "MY-CODE");
    assert.equal(await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).isChecked(), true);
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByRole("heading", { name: "مراجعة أحدث نسخة مع مسودتك" }).waitFor();
    assert.equal(calls.filter(call => call.method === "PUT").length, 1);
    await dialog.getByRole("checkbox", { name: "تطبيق مسودتي: كود العيادة", exact: true }).check();
    await dialog.getByRole("checkbox", { name: "تطبيق اختياري للطبيب: سامر النموذجي", exact: true }).check();
    await dialog.getByRole("button", { name: "اعتماد الاختيارات للمراجعة", exact: true }).click();
    assert.equal(await dialog.getByLabel("التوصيف").inputValue(), latest.description);
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.waitFor({ state: "detached" });
    const body = calls.filter(call => call.method === "PUT").at(-1).body;
    assert.equal(body.lock_version, 2); assert.equal(body.code, "MY-CODE"); assert.equal(body.description, latest.description);
    assert.deepEqual(body.doctor_add_ids, [3]); assert.deepEqual(body.doctor_remove_ids, []);
  } finally { await context.close(); }
});

test("regression: real history entries restore URL search, filters and page without stale replacement", async () => {
  const { page, context } = await setup();
  try {
    await page.goto(`${base}/clinics?facility_id=1&search=first&status=active&page=2`);
    await page.getByRole("link", { name: "001", exact: true }).waitFor();
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "first");
    // A genuine new history entry, not an assumption that typing uses push.
    await page.evaluate(() => history.pushState(null, "", "/clinics?facility_id=1&search=second&status=inactive&page=3"));
    await page.waitForTimeout(650);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "second");
    assert.equal(new URL(page.url()).searchParams.get("page"), "3");
    await page.goBack(); await page.waitForTimeout(650);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "first");
    assert.equal(await page.getByRole("combobox", { name: "الحالة", exact: true }).inputValue(), "active");
    assert.equal(new URL(page.url()).searchParams.get("page"), "2");
    await page.goForward(); await page.waitForTimeout(650);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "second");
    assert.equal(new URL(page.url()).searchParams.get("status"), "inactive");
  } finally { await context.close(); }
});

test("conflict review recalculates already applied doctor deltas and preserves unseen concurrent links", async () => {
  let conflict = false;
  const extra = [4, 99].map(id => ({ ...doctors[0], id, code: `D${id}`, name: `طبيب مخفي ${id}`, is_linked: true }));
  const latest = { ...clinics[0], name_ar: "اسم المستخدم الآخر", lock_version: 2, doctor_count: 4 };
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (url.pathname.endsWith("/1") && route.request().method() === "PUT") {
      if (!conflict) { conflict = true; await route.fulfill({ status: 409, json: { error: { code: "CLINIC_VERSION_CONFLICT" } } }); }
      else await route.fulfill({ json: { data: latest } });
      return true;
    }
    if (!conflict) return false;
    if (url.pathname.endsWith("/options/doctors")) {
      await route.fulfill({ json: paginated(doctors.map(d => ({ ...d, is_linked: d.id !== 1 }))) }); return true;
    }
    if (url.pathname.endsWith("/1/doctors")) {
      const all = [...doctors.slice(1).map(d => ({ ...d, is_linked: true })), ...extra];
      const p = Number(url.searchParams.get("page") || 1);
      await route.fulfill({ json: paginated(all.slice((p - 1) * 2, p * 2), p, 2, all.length) }); return true;
    }
    if (url.pathname.endsWith("/1")) { await route.fulfill({ json: { data: latest } }); return true; }
    if (url.pathname === "/hospital-api/clinics") { await route.fulfill({ json: paginated([latest]) }); return true; }
    return false;
  } });
  try {
    await page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("checkbox", { name: /أحمد الاختباري/ }).uncheck();
    await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).check();
    await dialog.getByLabel("البحث لاختيار الأطباء").fill("سامر");
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    const review = dialog.getByRole("region", { name: "مراجعة تعارض التعديل" });
    await review.getByText("طبيب مخفي 99 · D99", { exact: true }).waitFor();
    assert.equal(await review.getByRole("checkbox", { name: /تطبيق اختياري للطبيب/ }).count(), 0);
    await review.getByRole("button", { name: "اعتماد الاختيارات للمراجعة" }).click();
    assert.equal(await dialog.getByLabel("اسم العيادة *").inputValue(), latest.name_ar);
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.waitFor({ state: "detached" });
    const body = calls.filter(call => call.method === "PUT").at(-1).body;
    assert.equal(body.lock_version, 2); assert.equal(body.name_ar, latest.name_ar);
    assert.deepEqual(body.doctor_add_ids, []); assert.deepEqual(body.doctor_remove_ids, []);
    assert.ok(calls.some(call => call.url.pathname.endsWith("/1/doctors") && call.url.searchParams.get("page") === "2"));
  } finally { await context.close(); }
});

for (const detail of [false, true]) test(`reload failure, retry and second conflict retain draft and refresh ${detail ? "detail" : "table"} before reopening`, async () => {
  let version = 1, failReload = true;
  const current = () => ({ ...clinics[0], code: `SERVER-${version}`, description: `تحديث آخر ${version}`, lock_version: version });
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (url.pathname.endsWith("/1") && route.request().method() === "PUT") {
      version++; await route.fulfill({ status: 409, json: { error: { code: "CLINIC_VERSION_CONFLICT" } } }); return true;
    }
    if (url.pathname.endsWith("/1")) {
      if (version === 2 && failReload) { failReload = false; await route.fulfill({ status: 500, json: { error: { code: "CLINICS_UNAVAILABLE" } } }); }
      else await route.fulfill({ json: { data: current() } });
      return true;
    }
    if (url.pathname === "/hospital-api/clinics") { await route.fulfill({ json: paginated([current()]) }); return true; }
    return false;
  } });
  try {
    if (detail) { await page.getByRole("link", { name: "SERVER-1", exact: true }).click(); await page.getByRole("heading", { name: clinics[0].name_ar, exact: true }).waitFor(); }
    const edit = () => page.getByRole("button", { name: detail ? "تعديل العيادة" : "تعديل العيادة الداخلية", exact: true });
    await edit().click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("كود العيادة *").fill("MY-DRAFT");
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByText("تعذّر إتمام الطلب الآن. حاول مجددًا بعد قليل.", { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel("كود العيادة *").inputValue(), "MY-DRAFT");
    assert.equal(await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).isDisabled(), true);
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByRole("checkbox", { name: "تطبيق مسودتي: كود العيادة", exact: true }).check();
    await dialog.getByRole("button", { name: "اعتماد الاختيارات للمراجعة" }).click();
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).waitFor();
    assert.equal(await dialog.getByLabel("كود العيادة *").inputValue(), "MY-DRAFT");
    assert.equal(calls.filter(call => call.method === "PUT").at(-1).body.lock_version, 2);
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByRole("heading", { name: "مراجعة أحدث نسخة مع مسودتك" }).waitFor();
    await page.keyboard.press("Escape");
    await edit().click();
    assert.equal(await page.getByRole("dialog").getByLabel("كود العيادة *").inputValue(), "SERVER-3");
    assert.equal(calls.filter(call => call.method === "PUT").length, 2);
  } finally { await context.close(); }
});

for (const changeFacility of [false, true]) test(`late conflict reload is ignored after ${changeFacility ? "facility navigation" : "closing editor"}`, async () => {
  let conflict = false, started = false, release;
  const gate = new Promise(resolve => { release = resolve; });
  const access = [1, 2].map(id => ({ facility: { ...facility, id, name_ar: `منشأة ${id}` }, permissions, roles: [] }));
  const { page, context, calls, errors } = await setup({ access, override: async (route, url) => {
    if (route.request().method() === "PUT") { conflict = true; await route.fulfill({ status: 409, json: { error: { code: "CLINIC_VERSION_CONFLICT" } } }); return true; }
    if (conflict && url.pathname.endsWith("/1")) { started = true; await gate; try { await route.fulfill({ json: { data: { ...clinics[0], code: "LATE", lock_version: 2 } } }); } catch {} return true; }
    return false;
  } });
  try {
    await page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await page.waitForTimeout(150); assert.equal(started, true);
    if (changeFacility) await page.evaluate(() => history.pushState(null, "", "/clinics?facility_id=2"));
    else await page.keyboard.press("Escape");
    await dialog.waitFor({ state: "detached" });
    const listCalls = calls.filter(call => call.url.pathname === "/hospital-api/clinics").length;
    release(); await page.waitForTimeout(450);
    assert.equal(await page.getByRole("heading", { name: "مراجعة أحدث نسخة مع مسودتك" }).count(), 0);
    assert.equal(await page.getByText("LATE", { exact: true }).count(), 0);
    if (!changeFacility) assert.equal(calls.filter(call => call.url.pathname === "/hospital-api/clinics").length, listCalls);
    assert.deepEqual(errors, []);
  } finally { release(); await context.close(); }
});

test("doctor reload failure and a changing snapshot require retry; unavailable draft doctors are not reapplied", async () => {
  let version = 1, writes = 0, failDoctors = true, changeDuringRead = true;
  const current = () => ({ ...clinics[0], lock_version: version, description: `نسخة ${version}` });
  const { page, context, calls } = await setup({ width: 390, override: async (route, url) => {
    if (url.pathname.endsWith("/1") && route.request().method() === "PUT") {
      writes++;
      if (writes === 1) { version = 2; await route.fulfill({ status: 409, json: { error: { code: "CLINIC_VERSION_CONFLICT" } } }); }
      else await route.fulfill({ json: { data: current() } });
      return true;
    }
    if (version === 1) return false;
    if (url.pathname.endsWith("/1")) { await route.fulfill({ json: { data: current() } }); return true; }
    if (url.pathname.endsWith("/1/doctors")) {
      if (failDoctors) { failDoctors = false; await route.fulfill({ status: 503, json: { error: { code: "CLINICS_UNAVAILABLE" } } }); }
      else { if (changeDuringRead) { changeDuringRead = false; version = 3; } await route.fulfill({ json: paginated(doctors.slice(0, 2)) }); }
      return true;
    }
    if (url.pathname.endsWith("/options/doctors") && url.searchParams.get("search") === "D003") { await route.fulfill({ json: paginated([]) }); return true; }
    return false;
  } });
  try {
    await page.getByRole("button", { name: "تعديل العيادة الداخلية", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("كود العيادة *").fill("DRAFT");
    await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).check();
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByText("تعذّر إتمام الطلب الآن. حاول مجددًا بعد قليل.", { exact: true }).waitFor();
    assert.equal(await dialog.getByRole("checkbox", { name: /سامر النموذجي/ }).isChecked(), true);
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    await dialog.getByText("تغيرت العيادة أثناء جلب البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة مجددًا.", { exact: true }).waitFor();
    assert.equal(await dialog.getByLabel("كود العيادة *").inputValue(), "DRAFT");
    assert.equal(await dialog.getByRole("heading", { name: "مراجعة أحدث نسخة مع مسودتك" }).count(), 0);
    await dialog.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click();
    const review = dialog.getByRole("region", { name: "مراجعة تعارض التعديل" });
    await review.getByText(/غير متاح للاختيار/).waitFor();
    assert.equal(await review.getByRole("checkbox", { name: /تطبيق اختياري للطبيب/ }).count(), 0);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    if (process.env.CLINIC_REVIEW_CAPTURE) await page.screenshot({ path: process.env.CLINIC_REVIEW_CAPTURE });
    await review.getByRole("checkbox", { name: "تطبيق مسودتي: كود العيادة", exact: true }).check();
    await review.getByRole("button", { name: "اعتماد الاختيارات للمراجعة" }).click();
    await dialog.getByRole("button", { name: "حفظ العيادة", exact: true }).click();
    await dialog.waitFor({ state: "detached" });
    const body = calls.filter(call => call.method === "PUT").at(-1).body;
    assert.equal(body.lock_version, 3); assert.equal(body.code, "DRAFT"); assert.equal(body.description, "نسخة 3");
    assert.deepEqual(body.doctor_add_ids, []); assert.deepEqual(body.doctor_remove_ids, []);
  } finally { await context.close(); }
});

test("back during debounce cancels the draft; typing replaces once and export/detail use restored filters", async () => {
  const { page, context, calls } = await setup({ override: async (route, url) => {
    if (!url.pathname.endsWith("/export/xlsx")) return false;
    await route.fulfill({ headers: { "Content-Disposition": 'attachment; filename="CL-TEST.xlsx"' }, body: "fixture" }); return true;
  } });
  try {
    await page.goto(`${base}/clinics?facility_id=1&search=first&status=active&page=2`);
    await page.getByRole("link", { name: "001", exact: true }).waitFor();
    await page.evaluate(() => history.pushState(null, "", "/clinics?facility_id=1&search=second&status=inactive&page=3"));
    await page.waitForTimeout(400);
    await page.getByLabel("البحث في العيادات").fill("abandoned");
    await page.goBack(); await page.waitForTimeout(650);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "first");
    assert.equal(new URL(page.url()).searchParams.get("page"), "2");
    assert.equal(calls.filter(call => call.url.searchParams.get("search") === "abandoned").length, 0);
    await page.goForward(); await page.waitForTimeout(400);
    const length = await page.evaluate(() => history.length);
    const before = calls.length;
    await page.getByLabel("البحث في العيادات").fill("latest");
    assert.equal(await page.getByRole("button", { name: "Excel", exact: true }).isDisabled(), true);
    await page.waitForURL(/search=latest/); await page.getByRole("link", { name: "001", exact: true }).waitFor();
    await page.waitForTimeout(400);
    assert.equal(await page.evaluate(() => history.length), length);
    assert.equal(new URL(page.url()).searchParams.has("page"), false);
    assert.equal(calls.slice(before).filter(call => call.url.pathname === "/hospital-api/clinics").length, 1);
    const download = page.waitForEvent("download"); await page.getByRole("button", { name: "Excel", exact: true }).click(); await download;
    const exportQuery = calls.find(call => call.url.pathname.endsWith("/export/xlsx")).url.searchParams;
    assert.equal(exportQuery.get("search"), "latest"); assert.equal(exportQuery.get("status"), "inactive");
    await page.getByRole("link", { name: "001", exact: true }).click();
    await page.getByRole("link", { name: "العودة إلى قائمة العيادات" }).click();
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "latest");
    assert.equal(await page.getByRole("combobox", { name: "الحالة", exact: true }).inputValue(), "inactive");
  } finally { await context.close(); }
});

test("facility selection cancels pending search and filter changes commit the visible search on page one", async () => {
  const access = [1, 2].map(id => ({ facility: { ...facility, id, name_ar: `منشأة ${id}` }, permissions, roles: [] }));
  const { page, context, calls } = await setup({ access });
  try {
    await page.getByLabel("البحث في العيادات").fill("old-facility");
    await page.getByRole("combobox", { name: "المنشأة", exact: true }).selectOption("2");
    await page.waitForURL(/facility_id=2/); await page.waitForTimeout(650);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "");
    assert.ok(!new URL(page.url()).searchParams.has("search"));
    assert.equal(calls.filter(call => call.url.searchParams.get("search") === "old-facility").length, 0);
    await page.evaluate(() => history.pushState(null, "", "/clinics?facility_id=2&page=4"));
    await page.getByLabel("البحث في العيادات").fill("typed");
    await page.getByRole("combobox", { name: "الحالة", exact: true }).selectOption("active");
    await page.waitForURL(/status=active/); await page.waitForTimeout(650);
    const query = new URL(page.url()).searchParams;
    assert.equal(query.get("search"), "typed"); assert.equal(query.has("page"), false);
    assert.equal(await page.getByLabel("البحث في العيادات").inputValue(), "typed");
  } finally { await context.close(); }
});

for (const width of [390, 768, 1440]) test(`responsive list, editor, doctors and detail at ${width}px`, async () => {
  const { page, context, errors } = await setup({ width });
  const capture = async name => {
    if (process.env.CLINIC_CAPTURE !== "1") return;
    await mkdir("docs/screenshots/clinics", { recursive: true });
    await page.evaluate(() => document.fonts.ready);
    await writeFile(`docs/screenshots/clinics/${name}-${width}.png`, await page.screenshot({ fullPage: await page.getByRole("dialog").count() === 0 }));
  };
  const contained = async () => assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
  try {
    await page.getByRole("link", { name: "001", exact: true }).waitFor(); await contained(); await capture("list");
    await page.getByRole("button", { name: "إضافة عيادة جديدة", exact: true }).click();
    await page.getByRole("checkbox", { name: /أحمد الاختباري/ }).waitFor(); await contained(); await capture("add");
    await page.keyboard.press("Escape");
    await page.getByRole("button", { name: "أطباء العيادة الداخلية: 2", exact: true }).click();
    await page.getByRole("dialog").getByText("D001", { exact: true }).waitFor(); await contained(); await capture("doctors");
    await page.keyboard.press("Escape");
    await page.getByRole("link", { name: "001", exact: true }).click();
    await page.getByRole("heading", { name: "العيادة الداخلية", exact: true }).waitFor(); await contained(); await capture("detail");
    assert.deepEqual(errors, []);
  } finally { await context.close(); }
});
