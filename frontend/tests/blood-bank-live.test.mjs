import assert from "node:assert/strict";
import { before, after, test } from "node:test";
import { readFileSync } from "node:fs";
import { mkdir } from "node:fs/promises";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { randomUUID } from "node:crypto";
import { chromium } from "playwright";

const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3105";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const backend = fileURLToPath(new URL("../../backend/", import.meta.url));
let f, browser, donor, recipient, donation;
function fixture(mode) { const r = spawnSync("php", ["tests/Support/blood-bank-live.php", mode], { cwd: backend, env: { ...process.env, APP_ENV: "testing" }, encoding: "utf8", timeout: 30000 }); assert.equal(r.status, 0, `${r.stdout}\n${r.stderr}`); process.stdout.write(r.stdout); }
before(async () => { fixture("prepare"); f = JSON.parse(readFileSync(new URL("../../backend/storage/framework/testing/blood-bank-live.json", import.meta.url))); browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || "chrome" }); });
after(async () => { try { await browser?.close(); if (f) fixture("verify"); } finally { if (f) fixture("cleanup"); } });

async function api(method, path, expected = 200, body, token = f.token) {
  const url = new URL(`/hospital-api/${path}`, base); if (method === "GET") { url.searchParams.set("facility_id", String(body?.facility_id ?? f.facility)); for (const [k,v] of Object.entries(body ?? {})) url.searchParams.set(k, String(v)); }
  const r = await fetch(url, { method, signal: AbortSignal.timeout(30000), headers: { Accept: "application/json", "Content-Type": "application/json", ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: method === "GET" ? undefined : JSON.stringify({ facility_id: f.facility, ...body }) });
  const json = await r.json(); assert.equal(r.status, expected, `${method} ${url.pathname}: ${JSON.stringify(json.error ?? json.errors ?? {})}`); assert.equal(r.headers.get("x-test-laravel"), "blood-bank"); assert.match(r.headers.get("cache-control"), /no-store/); return json;
}
async function setup(path = "/blood-bank", token = f.token, width = 1440) { const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: "reduce" }); await context.addInitScript(t => sessionStorage.setItem("hospital.bearer", t), token); const page = await context.newPage(); page.setDefaultTimeout(12000); const errors = []; page.on("pageerror", e => errors.push(e.message)); await page.goto(base + path); return { context, page, errors }; }
function updateProfile(p, overrides = {}) { return { ...f.profile, kind: undefined, ...p.person, blood_group: p.blood_group, rh: p.rh, clinic_id: p.clinic_id, responsible_staff_id: p.responsible_staff_id, blood_component_id: p.blood_component_id, screenings: p.screenings, lock_version: p.lock_version, request_id: randomUUID(), ...overrides }; }
async function chooseResponsibility(page) { await page.getByRole("button", { name: /عيادة بنك الدم الاختبارية/ }).click(); await page.getByRole("button", { name: new RegExp(`CAT-${f.tag}`) }).click(); }

test("doctor options match the clinic through real Next and remain scoped", async () => {
  const clinicDoctors = await api("GET", `clinics/${f.clinic}/doctors`);
  const bankDoctors = await api("GET", "blood-bank/doctors", 200, { clinic_id: f.clinic });
  assert.deepEqual(bankDoctors.data.map(r => r.id), clinicDoctors.data.map(r => r.id));
  assert.ok(bankDoctors.data.some(r => r.id === f.staff));
  assert.equal((await api("GET", "blood-bank/doctors", 200, { clinic_id: f.clinic, facility_id: f.second })).data.length, 0);
  const { context, page } = await setup();
  try {
    await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click();
    await chooseResponsibility(page);
    await page.getByRole("button", { name: /CAT-/ , pressed: true }).waitFor();
  } finally { await context.close(); }
});

test("real empty doctor-type configuration explains why all linked doctors disappear", async () => {
  fixture("doctor-types-empty");
  let context;
  try {
    const clinic = await api("GET", `clinics/${f.clinic}/doctors`);
    const bank = await api("GET", "blood-bank/doctors", 200, { clinic_id: f.clinic });
    assert.deepEqual(clinic.data, []); assert.deepEqual(bank.data, []); assert.equal(bank.doctor_types_configured, false);
    const ui = await setup(); context = ui.context; const page = ui.page;
    await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click();
    await page.getByRole("button", { name: /عيادة بنك الدم الاختبارية/ }).click();
    await page.getByRole("alert").filter({ hasText: "دليل أنواع الأطباء غير مهيأ" }).waitFor();
    assert.equal(await page.getByRole("button", { name: new RegExp(`CAT-${f.tag}`) }).count(), 0);
  } finally { await context?.close(); fixture("doctor-types-reset"); }
  assert.equal((await api("GET", "blood-bank/doctors", 200, { clinic_id: f.clinic })).data[0].id, f.staff);
});

test("new profile has optional screenings and manual address modes", async () => {
  const { context, page } = await setup();
  try {
    await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click();
    await page.getByRole("button", { name: "إضافة فحص", exact: true }).waitFor();
    assert.equal(await page.getByRole("combobox", { name: "حالة HCV", exact: true }).count(), 0);
    await page.getByRole("radio", { name: "محافظة خارج سوريا", exact: true }).check();
    await page.getByLabel("اسم المحافظة خارج سوريا", { exact: true }).fill("محافظة اختبار خارجية");
    await page.getByLabel("اسم المدينة", { exact: true }).fill("مدينة اختبار");
    await page.getByRole("radio", { name: "محافظة سورية", exact: true }).check();
    await page.getByRole("radio", { name: "محافظة خارج سوريا", exact: true }).check();
    assert.equal(await page.getByLabel("اسم المحافظة خارج سوريا", { exact: true }).inputValue(), "محافظة اختبار خارجية");
    assert.equal(await page.getByLabel("اسم المدينة", { exact: true }).inputValue(), "مدينة اختبار");
  } finally { await context.close(); }
});

test("address and screening workflows save through Next, Laravel and MySQL for both profile kinds", async () => {
  const options = (await api("GET", "blood-bank/options")).data;
  assert.deepEqual(options.blood_components.map(c => c.name_ar), ["كامل", "ركازة", "بلازما", "صفيحات"]);
  for (const [index, kind] of ["donor", "recipient"].entries()) {
    const label = kind === "donor" ? "متبرع" : "مستفيد";
    const { context, page, errors } = await setup();
    try {
      await page.getByRole("button", { name: `إضافة ${label}`, exact: true }).click();
      await page.getByLabel("الاسم الأول", { exact: true }).fill("اختبار العنوان"); await page.getByLabel("اسم العائلة", { exact: true }).fill(label);
      const governors = page.getByRole("group", { name: "المحافظة السورية", exact: true });
      await governors.getByRole("searchbox").fill("حلب"); await governors.getByRole("button", { name: "حلب", exact: true }).click();
      const cities = page.getByRole("group", { name: "المدينة التابعة للمحافظة", exact: true });
      await cities.getByRole("button", { name: "حلب", exact: true }).click();
      assert.equal(await cities.getByRole("button", { name: "حماة", exact: true }).count(), 0);
      await page.getByRole("radio", { name: "المدينة غير موجودة", exact: true }).check(); await page.getByLabel("اسم المدينة", { exact: true }).fill("مدينة غير مدرجة");
      await page.getByRole("radio", { name: "مدينة من الدليل", exact: true }).check(); await cities.getByRole("button", { name: "حلب", exact: true, pressed: true }).waitFor();
      await page.getByRole("radio", { name: "محافظة خارج سوريا", exact: true }).check(); await page.getByLabel("اسم المحافظة خارج سوريا", { exact: true }).fill("محافظة خارجية اصطناعية");
      assert.equal(await page.getByLabel("اسم المدينة", { exact: true }).inputValue(), "مدينة غير مدرجة");
      await page.getByRole("radio", { name: options.blood_components[index].name_ar, exact: true }).check();
      await chooseResponsibility(page);
      await page.getByRole("button", { name: "إضافة فحص", exact: true }).click(); await page.getByLabel("نوع الفحص الجديد", { exact: true }).selectOption("HCV");
      await page.getByLabel("حالة HCV", { exact: true }).selectOption("complete");
      await page.getByRole("button", { name: "إضافة فحص", exact: true }).click(); assert.equal(await page.getByLabel("نوع الفحص الجديد", { exact: true }).locator('option[value="HCV"]').count(), 0);
      await page.getByLabel("نوع الفحص الجديد", { exact: true }).selectOption("HIV"); await page.getByRole("button", { name: "إزالة فحص HIV", exact: true }).click();
      assert.equal(await page.getByLabel("نتيجة HCV", { exact: true }).count(), 0); assert.equal(await page.getByLabel("نوع HCV", { exact: true }).count(), 0);
      const saving = page.waitForResponse(r => r.request().method() === "POST" && new URL(r.url()).pathname === "/hospital-api/blood-bank");
      await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); const response = await saving; assert.equal(response.status(), 201);
      const sent = response.request().postDataJSON(); assert.equal(sent.governorate_id, null); assert.equal(sent.city_id, null); assert.deepEqual(sent.screenings, [{ analyte: "HCV", status: "complete" }]);
      const row = (await response.json()).data; await page.getByRole("heading", { name: `اختبار العنوان ${label}`, exact: true }).waitFor();
      const persisted = (await api("GET", `blood-bank/${kind}/${row.id}`)).data;
      assert.equal(persisted.person.governorate_text, "محافظة خارجية اصطناعية"); assert.equal(persisted.person.city_text, "مدينة غير مدرجة"); assert.equal(persisted.blood_component_id, options.blood_components[index].id);
      assert.equal(persisted.screenings.length, 1); assert.equal(persisted.screenings[0].result, null);
      await page.getByRole("button", { name: "تعديل الملف", exact: true }).click(); assert.equal(await page.getByLabel("اسم المدينة", { exact: true }).inputValue(), "مدينة غير مدرجة");
      await page.getByRole("radio", { name: "محافظة سورية", exact: true }).check(); await governors.getByRole("button", { name: "حلب", exact: true }).click();
      await page.getByRole("radio", { name: "المدينة غير موجودة", exact: true }).check();
      const updated = page.waitForResponse(r => r.request().method() === "PUT" && new URL(r.url()).pathname.endsWith(`/${row.id}`)); await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); assert.equal((await updated).status(), 200); await page.getByRole("dialog").waitFor({ state: "hidden" });
      const final = (await api("GET", `blood-bank/${kind}/${row.id}`)).data; assert.equal(final.person.governorate_id, f.governorate); assert.equal(final.person.governorate_text, null); assert.equal(final.person.city_text, "مدينة غير مدرجة");
      assert.deepEqual(errors, []);
    } finally { await context.close(); }
  }
});

test("real city responses with injected delay cannot replace a newer governorate", async () => {
  const { context, page } = await setup(); let release; let held;
  const captured = new Promise(resolve => { held = resolve; });
  try {
    await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click(); await page.getByLabel("الاسم الأول", { exact: true }).fill("مسودة محفوظة");
    await page.route("**/hospital-api/blood-bank/cities?*", async route => {
      const response = await route.fetch();
      if (new URL(route.request().url()).searchParams.get("governorate_id") === String(f.governorate)) { await new Promise(resolve => { release = resolve; held(); }); }
      await route.fulfill({ response }).catch(() => {});
    });
    const governors = page.getByRole("group", { name: "المحافظة السورية", exact: true });
    await governors.getByRole("button", { name: "حلب", exact: true }).click(); await captured;
    await governors.getByRole("button", { name: "حماة", exact: true }).click();
    const cities = page.getByRole("group", { name: "المدينة التابعة للمحافظة", exact: true });
    await cities.getByRole("button", { name: "حماة", exact: true }).waitFor(); release();
    await cities.getByRole("button", { name: "حماة", exact: true }).click(); assert.equal(await cities.getByRole("button", { name: "حلب", exact: true }).count(), 0);
    assert.equal(await page.getByLabel("الاسم الأول", { exact: true }).inputValue(), "مسودة محفوظة");
  } finally { release?.(); await context.close(); }
});

test("legacy results, methods and component survive editing; linked patient address stays read-only", async () => {
  const input = { ...f.profile, request_id: randomUUID(), first_name: "قديم", family_name: "اصطناعي", blood_component_id: f.component,
    screenings: [{ analyte: "HCV", status: "complete", result: "indeterminate", screening_test_id: f.test }] };
  const row = (await api("POST", "blood-bank", 201, input)).data;
  const { context, page } = await setup(`/blood-bank/donor/${row.id}`);
  try {
    await page.getByRole("button", { name: "تعديل الملف", exact: true }).click();
    assert.equal(await page.getByRole("radio", { name: /مكون اختبار.*قيمة سابقة/ }).isChecked(), true);
    await page.getByLabel("حالة HCV", { exact: true }).selectOption("pending");
    const saving = page.waitForResponse(r => r.request().method() === "PUT" && new URL(r.url()).pathname.endsWith(`/${row.id}`));
    await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); assert.equal((await saving).status(), 200); await page.getByRole("dialog").waitFor({ state: "hidden" });
    const saved = (await api("GET", `blood-bank/donor/${row.id}`)).data;
    assert.equal(saved.blood_component_id, f.component); assert.deepEqual(saved.screenings, [{ analyte: "HCV", status: "pending", result: "indeterminate", screening_test_id: f.test }]);
    const linked = (await api("POST", "blood-bank", 201, { kind: "recipient", person_mode: "patient", patient_id: f.patients[3], clinic_id: f.clinic, responsible_staff_id: f.staff, request_id: randomUUID(), screenings: [] })).data;
    // Use the fixture patient with a directory address for the read-only preview.
    await page.goto(`${base}/blood-bank`); await page.getByRole("button", { name: "إضافة مستفيد", exact: true }).click();
    await page.getByLabel("هل المستفيد مريض مسجل في المشفى؟", { exact: false }).selectOption("yes");
    await page.getByRole("searchbox", { name: "البحث: المريض المسجل", exact: true }).fill(`${f.tag}-P2`); await page.getByRole("button", { name: new RegExp(`${f.tag}-P2`) }).click();
    await page.getByText("عنوان المريض المرجعي", { exact: true }).waitFor(); assert.equal(await page.getByLabel("عنوان السكن", { exact: true }).count(), 0);
    assert.equal(await page.getByRole("radio", { name: "محافظة خارج سوريا", exact: true }).count(), 0);
    await page.getByLabel("هل المستفيد مريض مسجل في المشفى؟", { exact: false }).selectOption("no"); assert.equal(await page.getByLabel("عنوان السكن", { exact: true }).inputValue(), "");
    await api("PUT", `blood-bank/recipient/${linked.id}`, 422, { kind: undefined, person_mode: "patient", patient_id: linked.patient_id, clinic_id: f.clinic, responsible_staff_id: f.staff, lock_version: linked.lock_version, request_id: randomUUID(), governorate_text: "نسخة ممنوعة" });
  } finally { await context.close(); }
});

test("actual grouped forms at 390 and 1440 pixels", async () => {
  const output = fileURLToPath(new URL("../.superdesign/blood-bank-review/", import.meta.url));
  await mkdir(output, { recursive: true });
  for (const width of [390, 1440]) for (const kind of ["donor", "recipient"]) {
    const { context, page, errors } = await setup("/blood-bank", f.token, width);
    try {
      await page.getByRole("button", { name: kind === "donor" ? "إضافة متبرع" : "إضافة مستفيد", exact: true }).click();
      await page.screenshot({ path: `${output}/workflow-person-${kind}-${width}.png` });
      const governors = page.getByRole("group", { name: "المحافظة السورية", exact: true });
      await governors.getByRole("searchbox").fill("حلب"); await governors.getByRole("button", { name: "حلب", exact: true }).click();
      await page.getByRole("radio", { name: "المدينة غير موجودة", exact: true }).check(); await page.getByLabel("اسم المدينة", { exact: true }).fill("مدينة اصطناعية");
      await page.getByRole("heading", { name: "العنوان", exact: true }).scrollIntoViewIfNeeded();
      await page.screenshot({ path: `${output}/workflow-address-${kind}-${width}.png` });
      await chooseResponsibility(page); await page.getByRole("button", { name: "إضافة فحص", exact: true }).click(); await page.getByLabel("نوع الفحص الجديد", { exact: true }).selectOption("HCV"); await page.getByLabel("حالة HCV", { exact: true }).selectOption("complete");
      await page.getByRole("heading", { name: "بيانات الدم", exact: true }).scrollIntoViewIfNeeded(); await page.screenshot({ path: `${output}/workflow-blood-${kind}-${width}.png` });
      await page.getByRole("heading", { name: "الفحوصات", exact: true }).scrollIntoViewIfNeeded(); await page.screenshot({ path: `${output}/workflow-screenings-${kind}-${width}.png` });
      assert.ok(await page.getByRole("dialog").evaluate(el => el.scrollWidth <= el.clientWidth + 1)); assert.deepEqual(errors, []);
    } finally { await context.close(); }
  }
});

test("real Next transport: profile codes, patient linking, literal search, isolation and permissions", async () => {
  donor = (await api("POST", "blood-bank", 201, f.profile)).data;
  assert.match(donor.code, /^BD-\d{8,}$/); assert.equal(donor.blood_group, null); assert.equal(donor.screenings[0].result, null);
  assert.equal((await api("POST", "blood-bank", 201, f.profile)).data.id, donor.id);
  await api("POST", "blood-bank", 409, { ...f.profile, first_name: "different" });
  const input = { ...f.profile, kind: "recipient", request_id: randomUUID() };
  recipient = (await api("POST", "blood-bank", 201, input)).data; assert.match(recipient.code, /^BR-\d{8,}$/);
  const linked = { ...input, person_mode: "patient", patient_id: f.patients[7], request_id: randomUUID() };
  for (const k of Object.keys(recipient.person)) delete linked[k];
  const p = (await api("POST", "blood-bank", 201, linked)).data; assert.equal(p.patient_id, f.patients[7]); assert.equal(p.name, "مستفيد اختبار 7");
  await api("POST", "blood-bank", 409, { ...linked, request_id: randomUUID() });
  await api("POST", "blood-bank", 422, { ...linked, first_name: "نسخة" });
  for (const search of ["أحمد", "محمد", "أحمد محمد", "  أحمد   محمد  "]) assert.equal((await api("GET", "blood-bank", 200, { search })).meta.total, 2);
  for (const search of ["%", "_", "لا يطابق"]) assert.equal((await api("GET", "blood-bank", 200, { search })).meta.total, 0);
  for (const path of ["blood-bank", "blood-bank/options", "blood-bank/cities", "blood-bank/clinics", "blood-bank/doctors", `blood-bank/donor/${donor.id}`, `blood-bank/donor/${donor.id}/donations`, "blood-bank/patients", `blood-bank/patients/${f.patients[7]}`]) {
    await api("GET", path, 401, {}, ""); await api("GET", path, 403, { facility_id: f.other });
  }
  await api("POST", "blood-bank", 403, { ...f.profile, request_id: randomUUID() }, f.viewer_token);
  await api("GET", "blood-bank/patients", 403, { search: "مستفيد" }, f.viewer_token);
  assert.equal((await api("GET", "blood-bank", 200, { facility_id: f.second })).meta.total, 0);
  for (const path of ["blood-bank/donor/not-a-number", "blood-bank/unknown/1", "blood-bank/donor/1/accept"]) { const r = await fetch(`${base}/hospital-api/${path}`); assert.equal(r.status, 404); assert.equal(r.headers.get("x-test-laravel"), null); }
});

test("real UI donor and recipient creation, patient/direct drafts and URL back/forward", async () => {
  const { context, page, errors } = await setup();
  try {
    await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click();
    await page.getByRole("textbox", { name: "الاسم الأول", exact: true }).fill("ليلى"); await page.getByRole("textbox", { name: "اسم العائلة", exact: true }).fill("اختبار");
    await chooseResponsibility(page); const saved = page.waitForResponse(r => r.request().method() === "POST" && new URL(r.url()).pathname === "/hospital-api/blood-bank"); await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); assert.equal((await saved).status(), 201);
    await page.getByRole("heading", { name: "ليلى اختبار", exact: true }).waitFor(); await page.getByRole("link", { name: "العودة إلى ملفات بنك الدم", exact: true }).click();
    await page.getByRole("button", { name: "إضافة مستفيد", exact: true }).click();
    await page.getByRole("textbox", { name: "الاسم الأول", exact: true }).fill("مسودة"); await page.getByRole("textbox", { name: "اسم العائلة", exact: true }).fill("محفوظة");
    const mode = page.getByRole("combobox", { name: /هل المستفيد مريض/ }); await mode.selectOption("yes"); await page.getByRole("searchbox", { name: "البحث: المريض المسجل", exact: true }).fill(`${f.tag}-P2`);
    await page.getByRole("button", { name: /مستفيد اختبار 2/ }).click(); await page.getByRole("definition").filter({ hasText: /^مستفيد$/ }).waitFor();
    await mode.selectOption("no"); assert.equal(await page.getByRole("textbox", { name: "الاسم الأول", exact: true }).inputValue(), "مسودة"); await mode.selectOption("yes"); await chooseResponsibility(page);
    const pending = page.waitForRequest(r => r.method() === "POST" && new URL(r.url()).pathname === "/hospital-api/blood-bank"); await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); const body = (await pending).postDataJSON(); assert.equal(body.patient_id, f.patients[2]); assert.equal(body.first_name, undefined);
    await page.getByRole("heading", { name: "مستفيد اختبار 2", exact: true }).waitFor();
    await page.goto(`${base}/blood-bank?search=${encodeURIComponent("أحمد محمد")}&kind=donor&per_page=10`); await page.getByRole("link", { name: donor.code, exact: true }).click(); await page.getByRole("link", { name: "العودة إلى ملفات بنك الدم", exact: true }).click();
    assert.equal(await page.getByRole("searchbox", { name: "البحث في بنك الدم", exact: true }).inputValue(), "أحمد محمد");
    await page.goto(`${base}/blood-bank?search=${encodeURIComponent("ليلى")}&kind=donor`); await page.getByRole("link", { name: /^BD-/ }).first().waitFor(); await page.goBack(); assert.equal(await page.getByRole("searchbox", { name: "البحث في بنك الدم", exact: true }).inputValue(), "أحمد محمد"); await page.goForward(); assert.equal(await page.getByRole("searchbox", { name: "البحث في بنك الدم", exact: true }).inputValue(), "ليلى"); assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test("two real edits: draft conflict, latest review, second conflict and reopening fresh data", async () => {
  const { context, page } = await setup(`/blood-bank/donor/${donor.id}`);
  try {
    await page.getByRole("button", { name: "تعديل الملف", exact: true }).click(); await page.getByRole("textbox", { name: "الهاتف", exact: true }).fill("0999000001");
    let current = (await api("PUT", `blood-bank/donor/${donor.id}`, 200, updateProfile(donor, { family_name: "تعديل آخر" }))).data;
    await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); await page.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).waitFor(); assert.equal(await page.getByRole("textbox", { name: "الهاتف", exact: true }).inputValue(), "0999000001");
    await page.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click(); await page.getByRole("checkbox", { name: "تطبيق مسودتي: الهاتف", exact: true }).check(); await page.getByRole("button", { name: "اعتماد الاختيارات للمراجعة", exact: true }).click(); assert.equal(await page.getByRole("textbox", { name: "اسم العائلة", exact: true }).inputValue(), "تعديل آخر");
    await api("PUT", `blood-bank/donor/${donor.id}`, 200, updateProfile(current, { father_name: "أب أحدث" }));
    await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); await page.getByRole("button", { name: "جلب أحدث نسخة", exact: true }).click(); await page.getByRole("checkbox", { name: "تطبيق مسودتي: الهاتف", exact: true }).check(); await page.getByRole("button", { name: "اعتماد الاختيارات للمراجعة", exact: true }).click(); await page.getByRole("button", { name: "حفظ الملف", exact: true }).click(); await page.getByRole("dialog").waitFor({ state: "hidden" });
    donor = (await api("GET", `blood-bank/donor/${donor.id}`)).data; assert.equal(donor.person.family_name, "تعديل آخر"); assert.equal(donor.person.father_name, "أب أحدث"); assert.equal(donor.person.phone, "0999000001");
    await page.getByRole("button", { name: "تعديل الملف", exact: true }).click(); assert.equal(await page.getByRole("textbox", { name: "اسم الأب", exact: true }).inputValue(), "أب أحدث");
  } finally { await context.close(); }
});

test("real donation codes: same day, backdate, future rejection, alias correction, retries and competing versions", async () => {
  const path = `blood-bank/donor/${donor.id}/donations`; const yesterday = new Date(`${f.today}T12:00:00Z`); yesterday.setUTCDate(yesterday.getUTCDate() - 1); const past = yesterday.toISOString().slice(0,10);
  const input = { request_id: randomUUID(), donated_on: past, blood_group: "O", rh: "positive", units: "1.2500" };
  donation = (await api("POST", path, 201, input)).data; assert.match(donation.donation_code, new RegExp(`^DON-${past.replaceAll("-", "")}-\\d{6,}$`));
  assert.equal((await api("POST", path, 201, input)).data.id, donation.id);
  const other = (await api("POST", path, 201, { ...input, request_id: randomUUID() })).data; assert.notEqual(other.donation_code, donation.donation_code);
  await api("POST", path, 422, { ...input, request_id: randomUUID(), donated_on: "2999-01-01" }); await api("POST", path, 403, { ...input, request_id: randomUUID() }, f.viewer_token);
  const oldCode = donation.donation_code; const corrected = { ...input, request_id: randomUUID(), lock_version: donation.lock_version, donated_on: f.today };
  const saved = (await api("PUT", `${path}/${donation.id}`, 200, corrected)).data; await api("PUT", `${path}/${donation.id}`, 409, { ...corrected, request_id: randomUUID() });
  assert.equal((await api("PUT", `${path}/${donation.id}`, 200, corrected)).data.id, donation.id); assert.equal((await api("GET", path, 200, { search: oldCode })).data[0].donation_code, saved.donation_code);
  const { context, page } = await setup(`/blood-bank/donor/${donor.id}`);
  try {
    await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).click();
    assert.equal(await page.getByRole('combobox', { name: 'زمرة ABO', exact: true }).inputValue(), '');
    await page.getByLabel('تاريخ التبرع الفعلي', { exact: true }).fill(past); await page.getByRole('combobox', { name: 'زمرة ABO', exact: true }).selectOption('AB'); await page.getByRole('combobox', { name: 'عامل Rh', exact: true }).selectOption('negative'); await page.getByLabel('عدد الوحدات', { exact: true }).fill('1');
    const creation = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/donations')); await page.getByRole('button', { name: 'حفظ التبرع', exact: true }).click(); assert.equal((await creation).status(), 201); await page.getByRole('dialog').waitFor({ state: 'hidden' });
    const aliasSearch = page.waitForResponse(r => new URL(r.url()).pathname.endsWith("/donations") && new URL(r.url()).searchParams.get("search") === oldCode); await page.getByRole("searchbox", { name: "البحث بكود التبرع الحالي أو السابق", exact: true }).fill(oldCode); assert.equal((await aliasSearch).status(), 200); await page.getByRole("link", { name: `استعراض ${saved.donation_code}`, exact: true }).click(); await page.getByRole("heading", { name: saved.donation_code, exact: true }).waitFor(); await page.getByRole("button", { name: "تعديل التبرع", exact: true }).click(); await page.getByLabel("تاريخ التبرع الفعلي", { exact: true }).fill(past); await page.getByRole("button", { name: "حفظ التبرع", exact: true }).click(); await page.getByRole("heading", { name: oldCode, exact: true }).waitFor(); donation = (await api("GET", `${path}/${donation.id}`)).data; assert.equal(donation.donated_on, past); assert.equal((await api("GET", path)).meta.total, 3); await page.getByRole("link", { name: "العودة إلى ملف المتبرع", exact: true }).click(); assert.equal(await page.getByRole("searchbox", { name: "البحث بكود التبرع الحالي أو السابق", exact: true }).inputValue(), oldCode); }
  finally { await context.close(); }
});

test("real inline doctor survives an injected option-network failure and completes a saved doctor's link without duplication", async () => {
  const { context, page } = await setup();
  let savedDoctor, writes = 0, failOptions = false;
  await page.route('**/hospital-api/doctors', async route => {
    if (route.request().method() !== 'POST') return route.continue();
    writes++; const response = await route.fetch(); savedDoctor = (await response.json()).data; failOptions = true; await route.fulfill({ response });
  });
  await page.route('**/hospital-api/blood-bank/doctors?*', route => {
    if (failOptions) { failOptions = false; return route.abort('failed'); } return route.continue();
  });
  try {
    await page.getByRole("button", { name: "إضافة مستفيد", exact: true }).click(); await page.getByRole("textbox", { name: "الاسم الأول", exact: true }).fill("مسودة طبيب"); await page.getByRole("button", { name: /عيادة بنك الدم الاختبارية/ }).click(); await page.getByRole("button", { name: "إضافة طبيب", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "إضافة طبيب جديد", exact: true }); const code = `BBUI-${f.tag}`;
    await dialog.getByRole("textbox", { name: "كود الطبيب", exact: false }).fill(code); await dialog.getByRole("textbox", { name: "الاسم الكامل", exact: false }).fill("طبيب بنك دم اصطناعي"); await dialog.getByRole("combobox", { name: "نوع الطبيب", exact: false }).selectOption({ index: 1 }); const specs = dialog.getByRole("checkbox"); if (await specs.count()) await specs.first().check();
    await dialog.getByRole("button", { name: "حفظ الطبيب", exact: true }).click(); await dialog.waitFor({ state: "hidden" });
    const recovery = page.getByRole('dialog', { name: 'استكمال الطبيب المحفوظ', exact: true }); await recovery.getByRole('alert').waitFor(); assert.ok(savedDoctor.id);
    // A real concurrent removal after the successful atomic create/link. Recover that identity, never POST again.
    const current = (await api('GET', `doctors/${savedDoctor.id}`)).data;
    await api('PUT', `doctors/${savedDoctor.id}/clinics`, 200, { lock_version: current.lock_version, clinic_add_ids: [], clinic_remove_ids: [f.clinic] });
    await recovery.getByRole('button', { name: 'تحديث خيارات الطبيب', exact: true }).click(); await recovery.getByText(/ارتباطه بهذه العيادة غير متاح/).waitFor();
    await recovery.getByRole('button', { name: 'استكمال الربط بالعيادة', exact: true }).click(); await recovery.waitFor({ state: 'hidden' });
    await page.getByRole("dialog", { name: "إضافة مستفيد", exact: true }).getByText(/المحدد: طبيب بنك دم اصطناعي/).waitFor(); assert.equal(await page.getByRole("textbox", { name: "الاسم الأول", exact: true }).inputValue(), "مسودة طبيب"); assert.equal(writes, 1);
    const doctors = await api("GET", "blood-bank/doctors", 200, { clinic_id: f.clinic, search: code }); assert.equal(doctors.meta.total, 1); assert.equal(doctors.data[0].code, code);
  } finally { await context.close(); }
});

test("injected reload failure and late real response cannot lose a draft or revive another facility", async () => {
  const { context, page } = await setup(`/blood-bank/donor/${donor.id}`);
  try {
    await page.getByRole('button', { name: 'تعديل الملف', exact: true }).click(); await page.getByRole('textbox', { name: 'الهاتف', exact: true }).fill('0999111111');
    donor = (await api('PUT', `blood-bank/donor/${donor.id}`, 200, updateProfile(donor, { mother_name: 'أم حديثة' }))).data;
    await page.getByRole('button', { name: 'حفظ الملف', exact: true }).click(); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor();
    let fail = true;
    await page.route(`**/hospital-api/blood-bank/donor/${donor.id}?*`, route => { if (fail) { fail = false; return route.abort('failed'); } return route.continue(); });
    await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).click(); await page.getByText('تعذّر جلب أحدث نسخة؛ مسودتك محفوظة. أعد المحاولة.', { exact: true }).waitFor(); assert.equal(await page.getByRole('textbox', { name: 'الهاتف', exact: true }).inputValue(), '0999111111');
    await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).click(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: الهاتف', exact: true }).waitFor();
    await page.getByRole('button', { name: 'إغلاق النافذة', exact: true }).click(); await page.getByRole('button', { name: 'تعديل الملف', exact: true }).click(); assert.equal(await page.getByRole('textbox', { name: 'اسم الأم', exact: true }).inputValue(), 'أم حديثة');
    await page.getByRole('button', { name: 'إغلاق النافذة', exact: true }).click();
    let release; const gate = new Promise(resolve => { release = resolve; }); let started; const waiting = new Promise(resolve => { started = resolve; });
    await page.unroute(`**/hospital-api/blood-bank/donor/${donor.id}?*`);
    await page.route('**/hospital-api/blood-bank?*', async route => { const url = new URL(route.request().url()); if (url.searchParams.get('facility_id') === String(f.facility)) { const response = await route.fetch(); started(); await gate; try { await route.fulfill({ response }); } catch {} } else await route.continue(); });
    await page.getByRole('link', { name: 'العودة إلى ملفات بنك الدم', exact: true }).click(); await waiting;
    await page.evaluate(id => history.pushState(null, '', `/blood-bank?facility_id=${id}`), f.second); await page.getByRole('region', { name: 'جدول بنك الدم', exact: true }).waitFor(); release();
    await page.getByText('لا توجد ملفات مطابقة.', { exact: true }).waitFor(); assert.equal(await page.getByRole('link', { name: donor.code, exact: true }).count(), 0); assert.equal(await page.getByRole('dialog').count(), 0);
  } finally { await context.close(); }
});

test("actual responsive list, profile, donation and forms; permission-only viewer", async () => {
  const output = fileURLToPath(new URL("../.superdesign/blood-bank-review/", import.meta.url)); await mkdir(output, { recursive: true });
  for (const width of [390, 768, 1440]) {
    const { context, page, errors } = await setup("/blood-bank", f.token, width);
    try { await page.getByRole("region", { name: "جدول بنك الدم", exact: true }).waitFor(); await page.screenshot({ path: `${output}/list-${width}.png`, fullPage: true });
      assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)); await page.getByRole("button", { name: "إضافة متبرع", exact: true }).click(); await page.screenshot({ path: `${output}/donor-form-${width}.png`, fullPage: false }); await page.getByRole("button", { name: "إلغاء", exact: true }).click();
      await page.goto(`${base}/blood-bank/donor/${donor.id}`); await page.getByRole("region", { name: "جدول وقائع التبرع", exact: true }).waitFor(); await page.screenshot({ path: `${output}/donor-${width}.png`, fullPage: true });
      await page.getByRole("button", { name: "تسجيل تبرع", exact: true }).click(); await page.screenshot({ path: `${output}/donation-form-${width}.png`, fullPage: false }); await page.getByRole("button", { name: "إلغاء", exact: true }).click();
      await page.goto(`${base}/blood-bank/donor/${donor.id}/donations/${donation.id}`); await page.getByRole("heading", { name: donation.donation_code, exact: true }).waitFor(); await page.screenshot({ path: `${output}/donation-${width}.png`, fullPage: true });
      await page.goto(`${base}/blood-bank/recipient/${recipient.id}`); await page.getByRole('region', { name: 'فحوص ملف بنك الدم', exact: true }).waitFor(); await page.screenshot({ path: `${output}/recipient-${width}.png`, fullPage: true }); await page.getByRole('button', { name: 'تعديل الملف', exact: true }).click(); await page.screenshot({ path: `${output}/recipient-form-${width}.png`, fullPage: false }); await page.getByRole('combobox', { name: 'حالة HIV', exact: true }).scrollIntoViewIfNeeded(); await page.screenshot({ path: `${output}/screenings-form-${width}.png`, fullPage: false }); assert.deepEqual(errors, []);
    } finally { await context.close(); }
  }
  const { context, page } = await setup("/blood-bank", f.viewer_token); try { await page.getByRole("region", { name: "جدول بنك الدم", exact: true }).waitFor(); assert.equal(await page.getByRole("button", { name: "إضافة متبرع", exact: true }).count(), 0); await page.goto(`${base}/blood-bank?facility_id=${f.other}`); await page.getByRole("alert").waitFor(); assert.equal(await page.getByRole("region", { name: "جدول بنك الدم", exact: true }).count(), 0); } finally { await context.close(); }
});
