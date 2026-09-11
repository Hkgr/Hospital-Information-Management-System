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
    assert.equal(await page.locator("#desktop-navigation").getByRole("button", { name: /الأطباء — قريبًا/ }).isDisabled(), true);
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

for (const width of [390, 1440]) test(`responsive list, editor, doctors and detail at ${width}px`, async () => {
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
