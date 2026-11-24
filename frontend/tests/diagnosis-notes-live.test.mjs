import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3199';
assert.ok(['127.0.0.1', 'localhost'].includes(new URL(base).hostname), 'Isolated local test only');
function control(mode) {
  const r = spawnSync('php', ['tests/Support/diagnosis-notes-live.php', mode], { cwd: fileURLToPath(new URL('../../backend/', import.meta.url)), env: { ...process.env, APP_ENV: 'testing' }, encoding: 'utf8', timeout: 60000 });
  assert.equal(r.status, 0, r.stdout + r.stderr);
}

test('real diagnosis options and visit note survive save, reload, edit and clearing', async () => {
  control('prepare');
  const f = JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/diagnosis-notes-live.json', import.meta.url)));
  let browser;
  async function api(path, method = 'GET', body) {
    const r = await fetch(`${base}/hospital-api/${path}`, { method, headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${f.token}` }, ...(body ? { body: JSON.stringify({ facility_id: f.facility, request_id: crypto.randomUUID(), ...body }) } : {}) });
    assert.equal(r.headers.get('x-test-laravel'), 'dossiers');
    assert.equal(r.status, method === 'POST' ? 201 : 200, await r.clone().text());
    return (await r.json()).data;
  }
  try {
    for (const name of f.diagnosis_names) {
      const choices = await api(`dossiers/options/diagnoses?${new URLSearchParams({ facility_id: f.facility, search: name })}`);
      assert.ok(choices.some(row => row.name_ar.trim() === name), `Missing diagnosis choice: ${name}`);
    }
    const card = await api('dossiers', 'POST', { person_mode: 'new', opening_date: '2000-01-01', visit_date: '2001-03-02', first_name: 'مريض اصطناعي', family_name: 'اختبار ملاحظة التشخيص', birth_date_accuracy: 'unknown', gender: 'unknown', displacement_status: 'unknown' });
    const editUrl = `${base}/patient-cards/new?facility_id=${f.facility}&card=${card.id}&visit=${card.visit.id}&section=2`;
    browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome' });
    const context = await browser.newContext({ viewport: { width: 390, height: 980 }, reducedMotion: 'reduce' });
    await context.addInitScript(token => sessionStorage.setItem('hospital.bearer', token), f.token);
    const page = await context.newPage(); page.setDefaultTimeout(20000);
    page.on('dialog', dialog => dialog.accept());
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    async function choose(label, name) {
      await page.getByRole('button', { name: `اختيار: ${label}`, exact: true }).click();
      const group = page.getByRole('group', { name: label, exact: true });
      await group.getByRole('searchbox').fill(name);
      await group.getByRole('button', { name: new RegExp(name) }).first().click();
    }
    async function save(expected) {
      const response = page.waitForResponse(r => r.request().method() === 'PUT' && r.url().endsWith(`/visits/${card.visit.id}`));
      await page.getByRole('button', { name: 'حفظ ومتابعة', exact: true }).click();
      const r = await response; assert.equal(r.status(), 200, await r.text());
      const saved = (await r.json()).data.visit.diagnoses[0];
      assert.equal(saved.note, expected);
      return saved.id;
    }
    await page.goto(editUrl);
    await page.getByRole('button', { name: 'إضافة تشخيص للزيارة', exact: true }).click();
    assert.equal(await page.locator('[name="diagnoses.0.note"]').count(), 0);
    await choose('التشخيص من الدليل 1', 'ورم رأس البنكرياس');
    const note = page.getByRole('textbox', { name: 'ملاحظة التشخيص', exact: true });
    assert.equal(await note.getAttribute('placeholder'), 'مثل: ناكس، نقائلي، أو تفاصيل إضافية');
    await note.fill('ناكس، تفاصيل إضافية للمراجعة');
    await choose('العيادة للتشخيص 1', f.clinic_name);
    await choose('الطبيب المسؤول عن التشخيص 1', f.doctor_name);
    const diagnosisId = await save('ناكس، تفاصيل إضافية للمراجعة');
    await page.goto(editUrl);
    assert.equal(await note.inputValue(), 'ناكس، تفاصيل إضافية للمراجعة');
    await note.fill('نقائلي');
    assert.equal(await save('نقائلي'), diagnosisId);
    await page.goto(`${base}/patient-cards/${card.id}?facility_id=${f.facility}&visit=${card.visit.id}`);
    const table = page.getByRole('region', { name: 'تشخيصات الزيارة', exact: true });
    await table.getByText('نقائلي', { exact: true }).first().waitFor();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.goto(editUrl);
    assert.equal(await note.inputValue(), 'نقائلي');
    await note.fill('');
    assert.equal(await save(null), diagnosisId);
    await page.goto(editUrl);
    assert.equal(await note.inputValue(), '');
    const read = await api(`dossiers/${card.id}/visits/${card.visit.id}?facility_id=${f.facility}`);
    assert.equal(read.diagnoses[0].note, null);
    assert.deepEqual(errors, []);
  } finally {
    await browser?.close();
    control('cleanup');
  }
});
