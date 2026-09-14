import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { chromium } from 'playwright';
import { fixture, fixtureData as f, api, payload, pageAt, eventFields } from './blood-events-live-support.mjs';
let browser, event;
before(async () => { fixture('prepare-unified'); browser = await chromium.launch(); event = (await api('/events', 'POST', payload(), 201)).data; });
after(async () => { await browser?.close(); if (f) { try { fixture('verify-unified'); } finally { fixture('cleanup'); } } });

test('server validation focuses the first invalid control and keeps the draft visible above sticky actions', async () => {
  for (const width of [390, 768, 1440]) {
    const { page, context } = await pageAt(browser, `/blood-bank/events/${event.id}`, width);
    try {
      await page.getByRole('button', { name: 'تعديل الواقعة', exact: true }).click();
      await page.getByLabel('التاريخ الفعلي', { exact: true }).fill('2099-01-01');
      await page.getByLabel('الكمية (كغ)', { exact: true }).fill('0.7350');
      const rejected = page.waitForResponse(r => r.url().includes(`/blood-bank/events/${event.id}`) && r.request().method() === 'PUT');
      await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); assert.equal((await rejected).status(), 422);
      await page.getByText('التاريخ الفعلي لا يمكن أن يكون في المستقبل.', { exact: true }).waitFor();
      assert.equal(await page.getByLabel('التاريخ الفعلي', { exact: true }).evaluate(el => el === document.activeElement), true);
      assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.7350');
      const field = await page.getByLabel('التاريخ الفعلي', { exact: true }).boundingBox();
      const save = await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).boundingBox();
      assert.ok(field.y > 70 && field.y + field.height < save.y);
      assert.equal(await page.getByLabel('التاريخ الفعلي', { exact: true }).getAttribute('aria-invalid'), 'true');
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    } finally { await context.close(); }
  }
});

test('new person errors follow form order and native validation keeps the rest of the draft', async () => {
  const { page, context } = await pageAt(browser, '/blood-bank', 390);
  try {
    await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).click();
    await page.getByRole('combobox', { name: 'الشخص', exact: true }).selectOption('new');
    await eventFields(page, (await api('/options')).data.blood_components[0].id);
    await page.getByLabel('الهاتف', { exact: true }).fill('0900000000');
    // Native required validation: no request is sent; focus is brought above the sticky footer.
    const posts = []; page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/blood-bank/events')) posts.push(r); });
    await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click();
    await page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'first_name'); assert.equal(posts.length, 0);
    // Whitespace passes native required but Laravel rejects both names after trimming.
    await page.getByLabel('الاسم الأول', { exact: true }).fill('   '); await page.getByLabel('اسم العائلة', { exact: true }).fill('   ');
    const rejected = page.waitForResponse(r => r.url().includes('/blood-bank/events') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); assert.equal((await rejected).status(), 422);
    await page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'first_name' && document.activeElement?.getAttribute('aria-invalid') === 'true');
    assert.equal(await page.getByLabel('اسم العائلة', { exact: true }).getAttribute('aria-invalid'), 'true');
    assert.equal(await page.getByLabel('الهاتف', { exact: true }).inputValue(), '0900000000');
    assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.4500');
    const description = await page.getByLabel('الاسم الأول', { exact: true }).getAttribute('aria-describedby');
    assert.ok(description); assert.ok(await page.evaluate(id => document.getElementById(id)?.textContent, description));
    await page.getByRole('dialog').getByRole('button', { name: 'إلغاء', exact: true }).click();
    assert.equal(await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).evaluate(el => el === document.activeElement), true);
  } finally { await context.close(); }
});

test('person editor focuses its server date error without overwriting the personal draft', async () => {
  const { page, context } = await pageAt(browser, `/blood-bank/people/${event.person_id}`, 768);
  try {
    await page.getByRole('button', { name: 'تعديل بيانات الشخص', exact: true }).click();
    await page.getByLabel('تاريخ الميلاد', { exact: true }).fill('2099-01-01');
    await page.getByLabel('اسم الأب', { exact: true }).fill('مسودة محفوظة');
    const rejected = page.waitForResponse(r => r.url().includes(`/blood-bank/people/${event.person_id}`) && r.request().method() === 'PUT');
    await page.getByRole('button', { name: 'حفظ بيانات الشخص', exact: true }).click(); assert.equal((await rejected).status(), 422);
    await page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'birth_date');
    assert.equal(await page.getByLabel('اسم الأب', { exact: true }).inputValue(), 'مسودة محفوظة');
  } finally { await context.close(); }
});

test('choice rows scroll inside the dialog and keyboard selection and nested cancellation preserve data', async () => {
  for (const width of [390, 1440]) {
    const { page, context } = await pageAt(browser, '/blood-bank', width);
    try {
      await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).click();
      await page.getByRole('combobox', { name: 'الشخص', exact: true }).selectOption('new');
      await page.getByLabel('الاسم الأول', { exact: true }).fill('أحمد');
      const group = page.getByRole('group', { name: 'المحافظة السورية', exact: true });
      const last = group.getByRole('button').last(); await last.focus();
      assert.equal(await last.evaluate(el => { const list = el.parentElement, r = el.getBoundingClientRect(), p = list.getBoundingClientRect(); return list.scrollHeight > list.clientHeight && r.top >= p.top && r.bottom <= p.bottom + 1; }), true);
      await page.keyboard.press('Enter'); assert.equal(await last.getAttribute('aria-pressed'), 'true');
      await eventFields(page, (await api('/options')).data.blood_components[0].id);
      await page.getByRole('button', { name: 'إضافة طبيب', exact: true }).click();
      await page.getByRole('dialog', { name: 'إضافة طبيب جديد', exact: true }).getByRole('button', { name: 'إلغاء', exact: true }).click();
      assert.equal(await page.getByLabel('الاسم الأول', { exact: true }).inputValue(), 'أحمد');
      assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.4500');
      assert.equal(await page.getByRole('button', { name: 'إضافة طبيب', exact: true }).evaluate(el => el === document.activeElement), true);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    } finally { await context.close(); }
  }
});
