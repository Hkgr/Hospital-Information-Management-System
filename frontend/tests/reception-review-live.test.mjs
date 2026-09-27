import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';

const base = 'http://127.0.0.1:3194';
const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
let fixture, browser;
function setup(mode) {
  const result = spawnSync('php', ['tests/Support/reception-review-live.php', mode], { cwd: backend, env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
}
before(async () => {
  setup('prepare');
  fixture = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/reception-review-live.json', import.meta.url)));
  browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
});
after(async () => { await browser?.close(); if (fixture) setup('cleanup'); });
async function api(path, body, admin = false, method = body ? 'POST' : 'GET') {
  const response = await fetch(`${base}/hospital-api/${path}${path.includes('?') ? '&' : '?'}facility_id=${fixture.facility}`, {
    method, headers: { Authorization: `Bearer ${fixture[admin ? 'admin' : 'clerk'].token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
    ...(body ? { body: JSON.stringify({ ...body, facility_id: fixture.facility }) } : {}),
  });
  assert.equal(response.headers.get('x-test-laravel'), 'dossiers', 'Must traverse real Next rewrite and Laravel');
  return { status: response.status, json: await response.json() };
}
async function card() {
  const result = await api('reception/registrations', { request_id: randomUUID(), person_mode: 'new', first_name: 'اختبار', family_name: 'مراجعة', birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown', opening_date: '2020-01-01', visit_date: '2020-01-01' });
  assert.equal(result.status, 201);
  return result.json.data;
}
async function pageFor(admin = false) {
  const context = await browser.newContext();
  await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), fixture[admin ? 'admin' : 'clerk'].token);
  const page = await context.newPage(); page.setDefaultTimeout(20000);
  return { context, page };
}

test('real UI corrects own identity, preserves invalid draft, requests and approves review without clinical access', async () => {
  const record = await card();
  const { context, page } = await pageFor();
  try {
    await page.goto(`${base}/reception?facility_id=${fixture.facility}`);
    await page.getByRole('searchbox').fill(record.code);
    await page.getByRole('button', { name: 'فتح ملخص الاستقبال', exact: true }).click();
    const editor = page.getByRole('region', { name: 'تصحيح الهوية وطلبات المراجعة' });
    await editor.getByRole('checkbox', { name: 'تصحيح الاسم الأول' }).check();
    await editor.getByLabel('الاسم الأول', { exact: true }).fill('');
    await editor.getByLabel('سبب التصحيح').fill('خطأ مطبعي');
    const failed = page.waitForResponse(r => r.url().includes('/correct?') || r.url().endsWith('/correct'));
    await editor.getByRole('button', { name: 'حفظ التصحيح خلال المهلة' }).click();
    assert.equal((await failed).status(), 422);
    assert.equal(await editor.getByLabel('سبب التصحيح').inputValue(), 'خطأ مطبعي');
    await editor.getByLabel('الاسم الأول', { exact: true }).fill('مصَحح');
    const saved = page.waitForResponse(r => r.url().endsWith('/correct') && r.status() === 200);
    await editor.getByRole('button', { name: 'حفظ التصحيح خلال المهلة' }).click(); await saved;
    await editor.getByRole('button', { name: 'اعتماد النسخة المعروضة بعد المراجعة' }).click();
    await editor.getByRole('checkbox', { name: 'تصحيح الاسم الأول' }).check();
    await editor.getByLabel('الاسم الأول', { exact: true }).fill('مراجع');
    const requested = page.waitForResponse(r => r.url().endsWith('/corrections') && r.status() === 201);
    await editor.getByRole('button', { name: 'إرسال طلب تصحيح' }).click();
    const request = (await (await requested).json()).data.id;
    assert.equal((await api(`dossiers/${record.id}`)).status, 403);
    assert.equal((await api(`dossiers/${record.id}/report/xlsx`, {})).status, 403);
    assert.equal((await api(`dossiers/${record.id}`, undefined, false, 'DELETE')).status, 403);
    const admin = await pageFor(true);
    try {
      await admin.page.goto(`${base}/reception-admin?facility_id=${fixture.facility}&tab=corrections&id=${request}`);
      await admin.page.getByLabel('سبب القرار').fill('مراجعة تعريفية مؤكدة');
      await admin.page.getByRole('checkbox', { name: 'راجعت القيم الحالية وأثر القرار' }).check();
      const approved = admin.page.waitForResponse(r => r.url().endsWith('/decision') && r.status() === 200);
      await admin.page.getByRole('button', { name: 'الموافقة والتنفيذ' }).click(); await approved;
      assert.equal((await api(`reception/cards/${record.id}/identity`)).json.data.values.first_name, 'مراجع');
    } finally { await admin.context.close(); }
  } finally { await context.close(); }
});

async function race(record, sameRequest) {
  const gate = randomUUID();
  const path = new URL(`../../backend/storage/framework/testing/review-${gate}`, import.meta.url);
  const input = { request_id: randomUUID(), patient_version: 1, dossier_version: 1, reason: 'تصحيح متزامن', changes: { first_name: 'متزامن' } };
  const workers = [0, 1].map(index => {
    const process = spawn('php', ['tests/Support/reception-review-concurrent.php'], { cwd: backend, env: { ...globalThis.process.env, APP_ENV: 'testing' } });
    let output = '', errors = '', resolveReady;
    const ready = new Promise(resolve => { resolveReady = resolve; });
    process.stdout.on('data', chunk => { output += chunk.toString(); if (output.includes('READY\n')) resolveReady(); });
    process.stderr.on('data', chunk => { errors += chunk.toString(); });
    const done = new Promise((resolve, reject) => { process.on('error', reject); process.on('exit', code => code === 0 ? resolve(JSON.parse(output.trim().split('\n').at(-1)).status) : reject(new Error(errors || output))); });
    process.stdin.end(JSON.stringify({ gate, card: record.id, body: { ...input, request_id: index && !sameRequest ? randomUUID() : input.request_id } }));
    return { ready, done, process };
  });
  let timer;
  try {
    await Promise.race([Promise.all(workers.map(w => w.ready)), new Promise((_, reject) => { timer = setTimeout(() => reject(new Error('Worker readiness timeout')), 15000); })]);
    writeFileSync(path, 'go');
    assert.deepEqual((await Promise.all(workers.map(w => w.done))).sort(), sameRequest ? [200, 200] : [200, 409]);
    assert.equal((await api(`reception/cards/${record.id}/identity`)).json.data.patient_version, 2);
  } finally { clearTimeout(timer); for (const w of workers) w.process.kill(); try { unlinkSync(path); } catch {} }
}
test('independent PHP workers serialize edits and identical UUID replay on real MariaDB', async () => {
  await race(await card(), false);
  await race(await card(), true);
});

test('real duplicate review blocks clinical relinking; account UI freeze revokes the clerk session', async () => {
  const first = await card(), second = await card();
  const preview = await api(`reception/reviews/duplicates/preview&canonical_dossier_id=${first.id}&duplicate_dossier_id=${second.id}`.replace('preview&', 'preview?'), undefined, true);
  assert.equal(preview.status, 200); assert.equal(preview.json.data.can_merge, false);
  const requested = await api('reception/reviews/duplicates', { request_id: randomUUID(), canonical_dossier_id: first.id, duplicate_dossier_id: second.id, preview_hash: preview.json.data.preview_hash, reason: 'مراجعة تكرار' }, true);
  assert.equal(requested.status, 201);
  const decision = await api(`reception/reviews/duplicates/${requested.json.data.id}/decision`, { request_id: randomUUID(), lock_version: 1, decision: 'approved', reason: 'فحص' }, true);
  assert.equal(decision.status, 409);
  const { context, page } = await pageFor(true);
  try {
    await page.goto(`${base}/reception-admin?facility_id=${fixture.facility}&tab=accounts`);
    await page.getByRole('row').filter({ hasText: fixture.clerk.name }).getByRole('button', { name: 'إدارة الحساب' }).click();
    const editor = page.getByRole('region', { name: 'إدارة حساب الاستقبال' });
    await editor.getByLabel('الحساب فعال').uncheck();
    await editor.getByLabel('سبب تغيير الحساب').fill('تجميد اختباري');
    const frozen = page.waitForResponse(r => r.url().endsWith(`/accounts/${fixture.clerk.id}`) && r.status() === 200);
    await editor.getByRole('button', { name: 'تأكيد تغيير الحساب' }).click(); await frozen;
    assert.equal((await api('reception/options')).status, 401);
  } finally { await context.close(); }
});
