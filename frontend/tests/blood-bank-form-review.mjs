// Capture real scroll positions: no expanded dialog, DOM restyling or fabricated API.
import { mkdir, writeFile, readdir, unlink } from 'node:fs/promises';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import { fixture, fixtureData as f, api, payload, pageAt, eventFields } from './blood-events-live-support.mjs';
const phase = process.argv[2];
if (!['before', 'after'].includes(phase)) throw new Error('Specify before or after');
const root = new URL(`../docs/reviews/pr-19/${phase}/`, import.meta.url);
await mkdir(root, { recursive: true });
fixture('prepare-unified');
const browser = await chromium.launch();
const manifest = [];
try {
  const input = payload('benefit'); input.person.first_name = 'أحمد'; input.person.family_name = 'اختبار النموذج';
  input.person.phone = '0900000000'; input.person.address_line = 'عنوان سكن اصطناعي للمراجعة';
  const issue = (await api('/events', 'POST', input, 201)).data;
  const person = (await api(`/people/${issue.person_id}`)).data;
  const component = (await api('/options')).data.blood_components[0].id;
  for (const width of [390, 768, 1440]) for (const scenario of ['new-donation', 'existing-donation', 'new-issue', 'linked-transfusion', 'patient-issue', 'person-edit']) {
    const { page, context } = await pageAt(browser, scenario === 'person-edit' ? `/blood-bank/people/${person.id}` : '/blood-bank', width);
    try {
      if (scenario === 'person-edit') await page.getByRole('button', { name: 'تعديل بيانات الشخص', exact: true }).click();
      else {
        await page.getByRole('button', { name: scenario.includes('donation') ? 'تسجيل تبرع' : 'تسجيل استفادة', exact: true }).click();
        if (scenario.startsWith('new') || scenario === 'patient-issue') {
          await page.getByRole('combobox', { name: 'الشخص', exact: true }).selectOption('new');
          if (scenario === 'patient-issue') {
            await page.getByRole('combobox', { name: 'مصدر بيانات الشخص' }).selectOption('patient');
            const picker = page.getByRole('group', { name: 'المريض المسجل', exact: true });
            await picker.getByRole('searchbox').fill(`${f.tag}-P2`); await picker.getByRole('button', { name: new RegExp(`${f.tag}-P2`) }).click();
            await page.getByText('تُقرأ البيانات الحالية من ملف المريض دون نسخها أو تعديلها هنا.', { exact: true }).waitFor();
          } else {
            await page.getByLabel('الاسم الأول', { exact: true }).fill('أحمد'); await page.getByLabel('اسم العائلة', { exact: true }).fill('اختبار النموذج');
            await page.getByLabel('اسم الأب', { exact: true }).fill('محمد'); await page.getByLabel('اسم الأم', { exact: true }).fill('فاطمة');
            await page.getByLabel('الهاتف', { exact: true }).fill('0900000000'); await page.getByLabel('عنوان السكن', { exact: true }).fill('عنوان سكن اصطناعي للمراجعة');
          }
        } else {
          const picker = page.getByRole('group', { name: 'الشخص الموجود', exact: true });
          await picker.getByRole('searchbox').fill(person.code); await picker.getByRole('button', { name: new RegExp(person.code) }).click();
          await page.getByText(`${person.name} · ${person.code}`, { exact: true }).waitFor();
        }
        if (!scenario.includes('donation')) await page.getByRole('dialog').getByRole('combobox', { name: 'نوع الاستفادة' }).selectOption(scenario === 'linked-transfusion' ? 'transfusion' : 'issue');
        if (scenario === 'linked-transfusion') {
          await page.getByRole('combobox', { name: 'ارتباط عملية النقل' }).selectOption('linked');
          await page.getByRole('group', { name: 'واقعة الصرف السابقة' }).getByRole('button', { name: new RegExp(issue.code) }).click();
        }
        await eventFields(page, component);
      }
      await page.evaluate(() => document.fonts.ready);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
      const dialog = page.getByRole('dialog').last();
      const scroll = dialog.locator(':scope > div').nth(1);
      const files = [];
      let top = 0;
      for (let index = 1; index <= 12; index++) {
        const state = await scroll.evaluate((el, y) => { el.scrollTop = y; return { top: el.scrollTop, height: el.clientHeight, total: el.scrollHeight }; }, top);
        await page.waitForTimeout(60);
        const file = `${scenario}-${width}-${index}.jpg`; await page.screenshot({ path: fileURLToPath(new URL(file, root)), type: 'jpeg', quality: 78 }); files.push(file);
        if (state.top + state.height >= state.total - 2) break;
        top = state.top + state.height - 150;
      }
      if (scenario !== 'person-edit') {
        await page.getByLabel('التاريخ الفعلي', { exact: true }).fill('2099-01-01');
        const rejected = page.waitForResponse(r => r.url().includes('/blood-bank/events') && r.request().method() === 'POST');
        await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click();
        if ((await rejected).status() !== 422) throw new Error('Expected actual future-date validation');
        await page.getByText('التاريخ الفعلي لا يمكن أن يكون في المستقبل.', { exact: true }).waitFor();
        if (phase === 'after') await page.waitForFunction(() => document.activeElement?.getAttribute('name') === 'occurred_on');
        await page.waitForTimeout(150);
        const file = `${scenario}-${width}-error.jpg`; await page.screenshot({ path: fileURLToPath(new URL(file, root)), type: 'jpeg', quality: 78 }); files.push(file);
      }
      manifest.push({ scenario, width, files });
    } finally { await context.close(); }
  }
  await writeFile(new URL('manifest.json', root), JSON.stringify(manifest, null, 2));
  const captured = new Set(manifest.flatMap(item => item.files));
  for (const name of await readdir(root)) {
    if (/^(new-donation|existing-donation|new-issue|linked-transfusion|patient-issue|person-edit)-(390|768|1440)-(\d+|error)\.jpg$/.test(name) && !captured.has(name)) await unlink(new URL(name, root));
  }
} finally { await browser.close(); fixture('verify-unified'); fixture('cleanup'); }
