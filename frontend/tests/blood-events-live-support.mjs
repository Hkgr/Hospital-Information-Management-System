import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { randomUUID } from 'node:crypto';
export const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3194';
assert.ok(['localhost', '127.0.0.1'].includes(new URL(base).hostname));
export const output = fileURLToPath(new URL('../.superdesign/blood-events/', import.meta.url));
export let fixtureData;
export function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/blood-bank-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 60000 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
  if (mode === 'prepare-unified' || mode.startsWith('periods-')) fixtureData = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/blood-bank-live.json', import.meta.url)));
  process.stdout.write(r.stdout);
}
export function payload(kind = 'donation', personId) {
  const f = fixtureData;
  return { request_id: randomUUID(), kind, ...(kind === 'benefit' ? { benefit_kind: 'issue' } : {}), ...(personId ? { person_id: personId } : { person: { person_mode: 'direct', first_name: 'اصطناعي', family_name: 'شخص موحد', gender: 'unknown', birth_date_accuracy: 'unknown', displacement_status: 'unknown', phone: '0012345678' } }), occurred_on: f.today, quantity: '0.4500', quantity_unit: 'kg', blood_group: 'O', rh: 'positive', clinic_id: f.clinic, responsible_staff_id: f.staff, blood_component_id: f.component, screenings: [] };
}
export async function request(path, method = 'GET', body, token = fixtureData.token) {
  const f = fixtureData;
  const response = await fetch(`${base}/hospital-api/blood-bank${path}${path.includes('?') ? '&' : '?'}facility_id=${body?.facility_id ?? f.facility}`, { method, signal: AbortSignal.timeout(65000), headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: method !== 'GET' ? JSON.stringify({ facility_id: f.facility, ...body }) : undefined });
  assert.equal(response.headers.get('x-test-laravel'), 'blood-bank'); assert.match(response.headers.get('cache-control'), /no-store/);
  return response;
}
export async function api(path, method = 'GET', body, expected = 200, token) {
  const r = await request(path, method, body, token); const data = await r.json(); assert.equal(r.status, expected, `${path}: ${JSON.stringify(data.error ?? data.errors ?? {})}`); return data;
}
export async function pageAt(browser, path = '/blood-bank', width = 1440, token = fixtureData.token) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, reducedMotion: 'reduce' });
  await context.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), token);
  const page = await context.newPage(); page.setDefaultTimeout(20000); const errors = []; page.on('pageerror', e => errors.push(e.message)); await page.goto(base + path); return { page, context, errors };
}
export async function responsibility(page) {
  await page.getByRole('group', { name: 'العيادة', exact: true }).getByRole('button', { name: /عيادة بنك الدم الاختبارية/ }).click();
  await page.getByRole('group', { name: 'الطبيب المسؤول', exact: true }).getByRole('button', { name: new RegExp(`CAT-${fixtureData.tag}`) }).click();
}
export async function eventFields(page, component) {
  await page.getByLabel('التاريخ الفعلي', { exact: true }).fill(fixtureData.today);
  await page.getByLabel('الكمية (كغ)', { exact: true }).fill('0.4500');
  await page.getByLabel('زمرة ABO', { exact: true }).last().selectOption('O'); await page.getByLabel('عامل Rh', { exact: true }).last().selectOption('positive');
  await page.getByRole('combobox', { name: 'نوع المكوّن', exact: false }).selectOption(String(component));
  await responsibility(page);
}
