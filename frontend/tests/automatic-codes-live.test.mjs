import assert from "node:assert/strict";
import { before, after, test } from "node:test";
import { readFileSync } from "node:fs";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";
import { randomUUID } from "node:crypto";
import { chromium } from "playwright";

// Real standalone Next -> Laravel -> guarded MariaDB. No API interception.
const base = process.env.TEST_BASE_URL || "http://127.0.0.1:3194";
assert.ok(["127.0.0.1", "localhost"].includes(new URL(base).hostname));
const backend = fileURLToPath(new URL("../../backend/", import.meta.url));
let f, browser;
function fixture(mode) {
  const r = spawnSync("php", ["tests/Support/automatic-codes-live.php", mode], { cwd: backend, encoding: "utf8", timeout: 30000 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}
before(async () => { fixture("prepare"); f = JSON.parse(readFileSync(new URL("../../backend/storage/framework/testing/automatic-codes-live.json", import.meta.url))); browser = await chromium.launch({ channel: "chrome" }); });
after(async () => { await browser?.close(); if (f) fixture("cleanup"); });
async function api(path, body, expected = 201, token = f.token) {
  const response = await fetch(`${base}/hospital-api/${path}`, { method: "POST", headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` }, body: JSON.stringify({ facility_id: f.facility, ...body }), signal: AbortSignal.timeout(30000) });
  const result = await response.json();
  assert.equal(response.status, expected, `${path}: ${JSON.stringify(result)}`);
  assert.equal(response.headers.get("x-test-laravel"), "dossiers");
  assert.match(response.headers.get("cache-control"), /no-store/);
  return result.data;
}

test("all generated directories, quick entries and receipt replay through Next with current authorization", async () => {
  const cases = [
    ["clinics", { name_ar: `عيادة ${f.tag}`, is_active: true }],
    ["doctors", { name: `طبيب ${f.tag}`, staff_type_id: f.staff_type_id, specialty_ids: f.specialty_ids, is_active: true }],
    ["stock/stores", { name_ar: `مستودع ${f.tag}`, is_active: true }],
    ["stock/suppliers", { name_ar: `مورد ${f.tag}`, is_active: true }],
    ["dossiers/diagnoses", { name_ar: `تشخيص ${f.tag}` }],
    ["dossiers/medications", { name_ar: `دواء سريع ${f.tag}` }],
    ["stock/receipts", { store_id: f.store, received_on: f.today, medication_source: "ministry_of_health", invoice_number: "00017-EXT", items: [] }],
  ];
  for (const kind of ["service", "procedure", "medication"]) {
    cases.push(["service-catalog", { kind, name_ar: `${kind} ${f.tag}`, is_active: true, ...(kind === "service" ? { category_id: f.category } : {}) }]);
    cases.push(["service-catalog/categories", { kind, name_ar: `تصنيف ${kind} ${f.tag}`, is_active: true }]);
  }
  for (const [path, payload] of cases) {
    const body = { ...payload, request_id: randomUUID() };
    const first = await api(path, body), second = await api(path, body);
    assert.equal(first.id, second.id); assert.equal(first.code ?? first.receipt_no, second.code ?? second.receipt_no);
    assert.match(first.code ?? first.receipt_no, /^AUTO-/);
    await api(path, { ...body, ...(path === "stock/receipts" ? { invoice_number: "changed" } : path === "doctors" ? { name: "مختلف" } : { name_ar: "مختلف" }) }, 409);
    await api(path, body, 403, f.viewer_token);
  }
});

test("browser doctor creation submits one UUID and server-issued code with a real clinic link", async () => {
  const clinic = await api("clinics", { request_id: randomUUID(), name_ar: `عيادة ربط ${f.tag}`, is_active: true });
  const context = await browser.newContext();
  await context.addInitScript(token => sessionStorage.setItem("hospital.bearer", token), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(12000);
  try {
    await page.goto(base + `/doctors?facility_id=${f.facility}`);
    await page.getByRole("button", { name: "إضافة طبيب جديد", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await dialog.getByLabel("الاسم الكامل *").fill(`طبيب المتصفح ${f.tag}`);
    await dialog.getByLabel("نوع الطبيب *").selectOption(String(f.staff_type_id));
    const specialties = dialog.getByRole("group", { name: /التخصصات/ }).getByRole("checkbox");
    if (await specialties.count()) await specialties.first().check();
    await dialog.getByRole("checkbox", { name: new RegExp(clinic.name_ar) }).check();
    const saved = page.waitForResponse(r => new URL(r.url()).pathname === "/hospital-api/doctors" && r.request().method() === "POST");
    await dialog.getByRole("button", { name: "حفظ الطبيب", exact: true }).click();
    const response = await saved; assert.equal(response.status(), 201);
    const body = response.request().postDataJSON(), row = (await response.json()).data;
    assert.ok(body.request_id); assert.equal(body.code, undefined); assert.equal(row.clinic_count, 1);
    assert.deepEqual(body.clinic_add_ids, [clinic.id]);
    await dialog.waitFor({ state: "detached" });
    const replay = await api("doctors", body); assert.equal(replay.id, row.id); assert.equal(replay.clinic_count, 1);
  } finally { await context.close(); }
});

test("browser receipt creation needs no manual internal number and retains batch identification", async () => {
  const context = await browser.newContext(); await context.addInitScript(token => sessionStorage.setItem("hospital.bearer", token), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(12000);
  try {
    await page.goto(base + `/stock/receipts?facility_id=${f.facility}`);
    await page.getByRole("button", { name: "إذن جديد", exact: true }).click();
    const dialog = page.getByRole("dialog");
    assert.equal(await dialog.getByLabel("رقم الإذن", { exact: true }).count(), 0);
    await dialog.getByLabel("رقم الدفعة").fill(`000-BATCH-${f.tag}`);
    await dialog.getByLabel("تاريخ الانتهاء").fill("2028-01-01");
    const saved = page.waitForResponse(r => new URL(r.url()).pathname === "/hospital-api/stock/receipts" && r.request().method() === "POST");
    await dialog.getByRole("button", { name: "حفظ المسودة", exact: true }).click();
    const response = await saved; assert.equal(response.status(), 201);
    const row = (await response.json()).data;
    assert.match(row.receipt_no, /^AUTO-RCV-/); assert.equal(row.items[0].batch_number, `000-BATCH-${f.tag}`);
    await page.getByRole("heading", { name: `إذن ${row.receipt_no}` }).waitFor();
  } finally { await context.close(); }
});

test("inline doctor recovers a lost real response by UUID, preserves the parent draft and retries options by saved ID", async () => {
  const clinic = await api('clinics', { request_id: randomUUID(), name_ar: `إضافة سريعة ${f.tag}`, is_active: true });
  const context = await browser.newContext(); await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
  const page = await context.newPage(); page.setDefaultTimeout(15000);
  const posts = [];
  page.on('request', r => { if (new URL(r.url()).pathname === '/hospital-api/doctors' && r.method() === 'POST') posts.push(r.postDataJSON()); });
  try {
    await page.goto(base + `/blood-bank?facility_id=${f.facility}`);
    // Requests really reach Laravel and commit. Only transport delivery is fault-injected.
    await page.evaluate(() => {
      const original = window.fetch.bind(window); let creates = 0, failOptions = false;
      window.fetch = async (...args) => {
        const url = new URL(String(args[0]), location.origin);
        if (failOptions && url.pathname === '/hospital-api/blood-bank/doctors') { failOptions = false; throw new TypeError('Synthetic options transport failure'); }
        const response = await original(...args);
        if (url.pathname === '/hospital-api/doctors' && args[1]?.method === 'POST' && response.ok) {
          creates++; window.savedAutomaticDoctor = (await response.clone().json()).data;
          if (creates === 1) throw new TypeError('Synthetic lost response after real commit');
          failOptions = true;
        }
        return response;
      };
    });
    await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).click();
    await page.getByRole('combobox', { name: 'الشخص', exact: true }).selectOption('new');
    await page.getByLabel('الاسم الأول', { exact: true }).fill('مسودة محفوظة');
    await page.getByLabel('الكمية (كغ)', { exact: true }).fill('0.4500');
    await page.getByRole('group', { name: 'العيادة', exact: true }).getByRole('button', { name: new RegExp(clinic.name_ar) }).click();
    await page.getByRole('button', { name: 'إضافة طبيب', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'إضافة طبيب جديد', exact: true });
    await dialog.getByLabel('الاسم الكامل *').fill(`طبيب سريع ${f.tag}`);
    await dialog.getByLabel('نوع الطبيب *').selectOption(String(f.staff_type_id));
    const specialties = dialog.getByRole('checkbox'); if (await specialties.count()) await specialties.first().check();
    await dialog.getByRole('button', { name: 'حفظ الطبيب', exact: true }).click();
    await dialog.getByRole('button', { name: 'استعادة نتيجة الحفظ', exact: true }).click();
    const recovery = page.getByRole('dialog', { name: 'استكمال الطبيب المحفوظ', exact: true });
    await recovery.getByRole('alert').waitFor();
    await recovery.getByRole('button', { name: 'تحديث خيارات الطبيب', exact: true }).click();
    await recovery.waitFor({ state: 'detached' });
    assert.equal(posts.length, 2); assert.deepEqual(posts[1], posts[0]);
    assert.equal(await page.getByLabel('الاسم الأول', { exact: true }).inputValue(), 'مسودة محفوظة');
    assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.4500');
    const saved = await page.evaluate(() => window.savedAutomaticDoctor);
    assert.ok(saved.id); assert.match(saved.code, /^AUTO-DR-/);
    assert.match(await page.getByRole('group', { name: 'الطبيب المسؤول', exact: true }).innerText(), new RegExp(saved.code));
  } finally { await context.close(); }
});
