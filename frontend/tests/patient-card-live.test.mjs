import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawn, spawnSync } from 'node:child_process';
import { readFileSync, writeFileSync, unlinkSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

// Real standalone Next -> Laravel -> guarded populated MariaDB. No interception.
const base = 'http://127.0.0.1:3194';
let browser, f, completed = false;
function fixture(mode) {
  const r = spawnSync('php', ['tests/Support/dossier-workflow-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8' });
  assert.equal(r.status, 0, r.stdout + r.stderr); process.stdout.write(r.stdout);
}
before(async () => { fixture('prepare'); f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-workflow-live.json', import.meta.url))); browser = await chromium.launch(); });
after(async () => { await browser?.close(); try { if (completed) fixture('verify'); } finally { fixture('cleanup'); } });
async function request(method, path, body = {}, token = f.token) {
  const r = await fetch(`${base}/hospital-api/dossiers${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: token ? `Bearer ${token}` : '' }, ...(method === 'GET' ? {} : { body: JSON.stringify({ facility_id: f.facility, ...body }) }) });
  assert.equal(r.headers.get('x-test-laravel'), 'dossiers'); assert.match(r.headers.get('cache-control'), /no-store/);
  return { status: r.status, data: await r.json() };
}
async function save(page, suffix = '') {
  const response = page.waitForResponse(r => r.url().endsWith(`/hospital-api/dossiers${suffix}`) && ['POST', 'PUT'].includes(r.request().method()));
  await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
  const r = await response; assert.equal(r.ok(), true, await r.text()); return (await r.json()).data;
}

test('opening unified Patient Cards creates nothing; no duplicate patient navigation or future-step writes', async () => {
  const c = await browser.newContext(); await c.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token);
  const p = await c.newPage(); const writes = []; p.on('request', r => { if (['POST', 'PUT'].includes(r.method()) && r.url().includes('/dossiers')) writes.push(r.url()); });
  try {
    await p.goto(`${base}/dossiers/new?facility_id=${f.facility}`);
    await p.getByRole('heading', { name: 'إضافة بطاقة مريض', exact: true }).waitFor();
    assert.equal(await p.getByRole('link', { name: 'المرضى', exact: true }).count(), 0);
    assert.equal(await p.locator('[name="visit_date"]').inputValue(), '');
    assert.equal(await p.locator('[name="visit_type_id"]').inputValue(), '');
    assert.equal(writes.length, 0); fixture('verify-empty');
  } finally { await c.close(); }
  for (const [token, facility, expected] of [['', f.facility, 401], [f.denied_token, f.facility, 403], [f.token, f.other, 403]]) {
    assert.equal((await request('GET', `/options?facility_id=${facility}`, {}, token)).status, expected);
  }
});

test('atomic first save, explicit real date, same draft visit on section saves and resume at 390/768/1440', async () => {
  for (const width of [390, 768, 1440]) {
    const c = await browser.newContext({ viewport: { width, height: 1000 } });
    await c.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token);
    const p = await c.newPage(); p.on('dialog', d => d.accept());
    try {
      await p.goto(`${base}/dossiers/new?facility_id=${f.facility}`);
      await p.locator('[name="first_name"]').fill('ليلى'); await p.locator('[name="family_name"]').fill(`بطاقة اختبار ${width}`);
      const code = `WIZ-${f.tag}-${width}`;
      await p.locator('[name="code"]').fill(code); await p.locator('[name="opening_date"]').fill('1999-02-03');
      const rejected = p.waitForResponse(r => r.url().endsWith('/hospital-api/dossiers') && r.status() === 422);
      await p.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click(); await rejected;
      await p.waitForFunction(() => document.activeElement?.getAttribute('name') === 'visit_date');
      assert.equal(await p.locator('[name="first_name"]').inputValue(), 'ليلى');
      await p.locator('[name="visit_date"]').fill('2000-03-04'); await p.locator('[name="visit_type_id"]').selectOption(String(f.visit_type));
      let s = await save(p); const visit = s.visit.id;
      assert.equal(s.code, code); assert.equal(s.patient.patient_code, code); assert.equal(s.card_id, s.patient.id);
      assert.equal(s.visit.visit_date, '2000-03-04'); assert.equal(s.visit.status, 'draft'); assert.equal(s.visit.diagnoses.length, 0);
      await p.locator('[name="is_oncology"]').selectOption('no'); await p.locator('[name="clinical_history"]').fill('قصة اختبار محفوظة في المشفى الحالي');
      s = await save(p, `/${s.id}/medical`); assert.equal(s.visit.id, visit);
      assert.equal(await p.locator('[name="visit_date"]').inputValue(), '2000-03-04');
      s = await save(p, `/${s.id}/visits/${visit}`); assert.equal(s.visit.id, visit);
      await p.goto(`${base}/dossiers/${s.id}/edit?facility_id=${f.facility}&section=2`);
      await p.locator('[name="visit_date"]').waitFor(); assert.equal(await p.locator('[name="visit_date"]').inputValue(), '2000-03-04');
      assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      assert.equal((await request('GET', `/${s.id}/visits?facility_id=${f.facility}`)).data.meta.total, 1);
      await p.goto(`${base}/dossiers?facility_id=${f.facility}&search=${code}`);
      await p.getByRole('region', { name: 'جدول بطاقات المرضى' }).waitFor();
      assert.equal(await p.getByRole('columnheader', { name: 'كود المريض', exact: true }).count(), 1);
      assert.equal(await p.getByRole('columnheader', { name: 'كود الإضبارة' }).count(), 0);
      assert.equal(await p.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    } finally { await c.close(); }
  }
  completed = true;
});

test('legacy code lookup requires explicit person selection and opens existing card instead of creating another', async () => {
  const legacy = `DOS-${f.tag}-001`;
  const found = await request('GET', `/options/patients?facility_id=${f.facility}&search=${encodeURIComponent(legacy)}`);
  assert.equal(found.status, 200); assert.ok(found.data.data.some(p => p.code === f.search_patient_code));
  const c = await browser.newContext(); await c.addInitScript(t => sessionStorage.setItem('hospital.bearer', t), f.token);
  const p = await c.newPage(); p.on('dialog', d => d.accept());
  try {
    await p.goto(`${base}/dossiers/new?facility_id=${f.facility}`);
    await p.getByRole('radio', { name: 'إضافة زيارة لمريض موجود', exact: true }).check();
    const picker = p.getByRole('group', { name: 'المريض الموجود', exact: true });
    await picker.getByRole('searchbox').fill(legacy);
    await picker.getByRole('button', { name: new RegExp(f.search_patient_code) }).click();
    const open = p.getByRole('link', { name: 'فتح البطاقة / إضافة زيارة', exact: true }); await open.waitFor();
    assert.equal(await p.locator('[name="code"]').count(), 0);
    assert.equal(await p.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).isDisabled(), true);
  } finally { await c.close(); }
});

test('independent Laravel workers serialize simultaneous canonical-code and UUID registrations', async () => {
  for (const replay of [true, false]) {
    const gate = crypto.randomUUID(); const path = new URL(`../../backend/storage/framework/testing/card-concurrent-${gate}`, import.meta.url);
    const body = { person_mode: 'new', code: `WIZ-${f.tag}-${replay ? 'REPLAY' : 'RACE'}`, opening_date: '2000-01-01', visit_date: '2000-03-04', first_name: 'متزامن', family_name: 'اختبار', gender: 'unknown', birth_date_accuracy: 'unknown', displacement_status: 'unknown', request_id: crypto.randomUUID() };
    const workers = [0, 1].map(n => {
      const child = spawn('php', ['tests/Support/patient-card-concurrent.php'], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: process.env, windowsHide: true });
      let stdout = '', stderr = ''; let ready;
      const readyPromise = new Promise(resolve => { ready = resolve; });
      child.stdout.on('data', b => { stdout += b; if (stdout.includes('READY\n')) ready(); }); child.stderr.on('data', b => { stderr += b; });
      const done = new Promise((resolve, reject) => { child.on('error', reject); child.on('close', code => { try { assert.equal(code, 0, stdout + stderr); resolve(JSON.parse(stdout.trim().split('\n').at(-1))); } catch (e) { reject(e); } }); });
      child.stdin.end(JSON.stringify({ gate, body: { ...body, request_id: !replay && n ? crypto.randomUUID() : body.request_id } }));
      return { child, ready: readyPromise, done };
    });
    try {
      await Promise.race([Promise.all(workers.map(w => w.ready)), new Promise((_, reject) => { const t = setTimeout(() => reject(new Error('workers did not become ready')), 15000); t.unref(); })]);
      writeFileSync(path, 'go');
      const results = await Promise.all(workers.map(w => w.done));
      assert.deepEqual(results.map(r => r.status).sort(), replay ? [201, 201] : [201, 422]);
      if (replay) assert.equal(results[0].id, results[1].id);
      const list = await request('GET', `?facility_id=${f.facility}&search=${body.code}`); assert.equal(list.data.meta.total, 1); assert.equal(list.data.data[0].visit_count, 1);
    } finally { for (const w of workers) if (w.child.exitCode === null) w.child.kill(); try { unlinkSync(path); } catch {} }
  }
});
