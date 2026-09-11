import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { doctors, options, clinicLinks } from './doctor-fixtures.mjs';
import { clinics, facility, user, paginated, doctors as doctorChoices } from './clinic-fixtures.mjs';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3103';
if (!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname)) throw new Error('Local test target only.');
let browser;
before(async () => { browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome'}); });
after(async () => { await browser?.close(); });
function deferred() { let resolve; const promise = new Promise(r => { resolve = r; }); return {promise, resolve}; }

async function setup(kind, {initial = false, detail = false} = {}) {
  const row = structuredClone(kind === 'doctors' ? doctors[0] : clinics[0]);
  const context = await browser.newContext();
  await context.addInitScript(() => sessionStorage.setItem('hospital.bearer', 'export-ui-fixture'));
  const page = await context.newPage(), calls = [], gates = [];
  let nextGate;
  function hold() { const gate = {...deferred(), started: deferred()}; gates.push(gate); nextGate = gate; return gate; }
  const initialGate = initial ? hold() : null;
  await page.route('**/*', async route => {
    const req = route.request(), url = new URL(req.url());
    if (url.origin !== new URL(base).origin) return route.abort();
    if (!url.pathname.startsWith('/hospital-api/')) return route.continue();
    calls.push({url, method: req.method()});
    if (url.pathname.endsWith('/user')) return route.fulfill({json:{data:{user,access:[1,2].map(id=>({facility:{...facility,id},permissions:['doctors.view','doctors.export','clinics.view','clinics.update','clinics.export'],roles:[]}))}}});
    if (url.pathname.endsWith('/options')) return route.fulfill({json:{data:options}});
    if (url.pathname.endsWith('/options/specialties')) return route.fulfill({json:{data:options.specialties}});
    if (url.pathname.endsWith('/options/clinics')) return route.fulfill({json:paginated(clinicLinks)});
    if (url.pathname.endsWith('/options/doctors')) return route.fulfill({json:{...paginated(doctorChoices),doctor_types_configured:true}});
    if (url.pathname.includes('/export/') || url.pathname.endsWith('/report')) return route.fulfill({status:422,json:{error:{code:'EXPORT_LIMIT_EXCEEDED',message:'اختبار التصدير'}}});
    if (url.pathname === `/hospital-api/${kind}`) {
      const gate = nextGate; nextGate = null;
      let outcome = {};
      if (gate) { gate.started.resolve(); outcome = await gate.promise; }
      try {
        if (outcome.fail) return await route.fulfill({status:500,json:{error:{message:'تعذّر تحميل النتائج.'}}});
        return await route.fulfill({json:paginated([{...row,code:outcome.code || url.searchParams.get('search') || row.code}],Number(url.searchParams.get('page')||1),Number(url.searchParams.get('per_page')||20),100)});
      } catch { return; } // Navigation/abort can dispose an obsolete request.
    }
    if (url.pathname === `/hospital-api/${kind}/1`) return route.fulfill({json:{data:row}});
    return route.fulfill({json:paginated(kind === 'doctors' ? clinicLinks : doctorChoices)});
  });
  await page.goto(`${base}/${kind}${detail ? '/1' : ''}?facility_id=1`);
  if (!initial && !detail) await page.getByRole('link',{name:row.code,exact:true}).waitFor();
  const reports = () => calls.filter(c=>c.url.pathname.includes('/export/')||c.url.pathname.endsWith('/report'));
  return {page,row,hold,initialGate,reports,calls,close:async()=>{for(const gate of gates)gate.resolve({});await context.close();}};
}

async function assertBlocked(page, reports) {
  for (const name of ['Excel','PDF']) assert.equal(await page.getByRole('button',{name,exact:true}).isDisabled(),true,`${name} must wait for the current successful table response`);
  const count = reports().length;
  await page.getByRole('button',{name:'Excel',exact:true}).evaluate(button=>button.click());
  await page.getByRole('button',{name:'PDF',exact:true}).evaluate(button=>button.click());
  assert.equal(reports().length,count);
}
async function ready(page) { await page.waitForFunction(()=>[...document.querySelectorAll('button')].some(b=>b.textContent==='Excel'&&!b.disabled)); }

for (const kind of ['doctors','clinics']) {
  const searchLabel = kind === 'doctors' ? 'البحث في الأطباء' : 'البحث في العيادات';
  test(`${kind}: initial loading blocks both list exports`,async()=>{
    const s=await setup(kind,{initial:true});
    try{await s.initialGate.started.promise;await s.page.getByRole('button',{name:'Excel',exact:true}).waitFor();await assertBlocked(s.page,s.reports);s.initialGate.resolve({});await ready(s.page);}finally{await s.close();}
  });
  test(`${kind}: delayed B and failed B retain A without exporting; retry exports B and selected columns`,async()=>{
    const s=await setup(kind);
    try{
      await ready(s.page);const gate=s.hold();await s.page.getByLabel(searchLabel).fill('B');await assertBlocked(s.page,s.reports);await gate.started.promise;
      await s.page.getByText('جارٍ تحديث النتائج…',{exact:true}).waitFor();assert.equal(await s.page.getByRole('link',{name:s.row.code,exact:true}).isVisible(),true);
      await assertBlocked(s.page,s.reports);gate.resolve({fail:true});await s.page.locator('main').getByRole('alert').waitFor();
      await assertBlocked(s.page,s.reports);assert.match(await s.page.locator('main').innerText(),/نتائج سابقة/);
      const retry=s.hold();await s.page.getByRole('button',{name:'إعادة المحاولة',exact:true}).click();await retry.started.promise;await assertBlocked(s.page,s.reports);
      retry.resolve({code:'B'});await s.page.getByRole('link',{name:'B',exact:true}).waitFor();await ready(s.page);
      await s.page.getByText('الأعمدة',{exact:true}).click();await s.page.getByRole('checkbox',{name:kind==='doctors'?'التوصيف المهني':'التوصيف',exact:true}).uncheck();await s.page.getByText('الأعمدة',{exact:true}).click();
      for(const format of ['Excel','PDF']){
        const path=`/hospital-api/${kind}/export/${format==='Excel'?'xlsx':'pdf'}`;
        const response=s.page.waitForResponse(r=>new URL(r.url()).pathname===path);
        await s.page.getByRole('button',{name:format,exact:true}).click();await response;await ready(s.page);
        const report=s.reports().at(-1);assert.equal(report.url.searchParams.get('search'),'B');assert.equal(report.url.searchParams.get('facility_id'),'1');assert.ok(!report.url.searchParams.getAll('columns[]').includes('description'));assert.match(report.url.pathname,new RegExp(`/${kind}/export/`));
      }
    }finally{await s.close();}
  });
  test(`${kind}: obsolete B cannot enable export while C is pending`,async()=>{
    const s=await setup(kind);
    try{
      await ready(s.page);const b=s.hold();await s.page.getByLabel(searchLabel).fill('B');await b.started.promise;
      const c=s.hold();await s.page.getByLabel(searchLabel).fill('C');await c.started.promise;
      b.resolve({code:'B'});await s.page.waitForTimeout(100);await assertBlocked(s.page,s.reports);assert.equal(await s.page.getByRole('link',{name:'B',exact:true}).count(),0);
      c.resolve({code:'C'});await s.page.getByRole('link',{name:'C',exact:true}).waitFor();await ready(s.page);
    }finally{await s.close();}
  });
  test(`${kind}: returning to A still waits for its new request, even with identical parameters`,async()=>{
    const s=await setup(kind);
    try{
      await ready(s.page);const b=s.hold();await s.page.getByLabel(searchLabel).fill('B');await b.started.promise;
      const a=s.hold();await s.page.getByLabel(searchLabel).fill('');await a.started.promise;
      await assertBlocked(s.page,s.reports);b.resolve({code:'B'});a.resolve({code:'REFRESHED'});
      await s.page.getByRole('link',{name:'REFRESHED',exact:true}).waitFor();await ready(s.page);
    }finally{await s.close();}
  });
  test(`${kind}: filter, sort, page, page size and post-save revision each invalidate export`,async()=>{
    const s=await setup(kind);
    try{
      await ready(s.page);
      for(const action of [()=>s.page.getByRole('combobox',{name:'الحالة',exact:true}).selectOption('active'),()=>s.page.getByRole('combobox',{name:'الترتيب',exact:true}).selectOption('patient_count'),()=>s.page.getByRole('button',{name:'التالي',exact:true}).last().click(),()=>s.page.getByRole('combobox',{name:'عدد الصفوف',exact:true}).selectOption('50')]){
        const gate=s.hold();await action();await gate.started.promise;await assertBlocked(s.page,s.reports);gate.resolve({});await ready(s.page);
      }
      await s.page.getByRole('button',{name:`تعديل ${kind==='doctors'?s.row.name:s.row.name_ar}`,exact:true}).click();
      await s.page.getByRole('dialog').getByRole('checkbox').first().waitFor();
      const gate=s.hold();await s.page.getByRole('dialog').getByRole('button',{name:kind==='doctors'?'حفظ الطبيب':'حفظ العيادة',exact:true}).click();
      await gate.started.promise;await s.page.getByRole('dialog').waitFor({state:'detached'});await assertBlocked(s.page,s.reports);gate.resolve({});await ready(s.page);
      const response=s.page.waitForResponse(r=>new URL(r.url()).pathname===`/hospital-api/${kind}/export/xlsx`);
      await s.page.getByRole('button',{name:'Excel',exact:true}).click();await response;
      const query=s.reports().at(-1).url.searchParams;
      assert.equal(query.get('status'),'active');assert.equal(query.get('sort'),'patient_count');assert.equal(query.get('per_page'),'50');assert.equal(query.has('page'),false);
    }finally{await s.close();}
  });
  test(`${kind}: pending facility response cannot retain old export readiness`,async()=>{
    const s=await setup(kind);
    try{await ready(s.page);const gate=s.hold();await s.page.getByRole('combobox',{name:'المنشأة',exact:true}).selectOption('2');await gate.started.promise;await assertBlocked(s.page,s.reports);gate.resolve({});await ready(s.page);}finally{await s.close();}
  });
  test(`${kind}: detail report works without requesting a list`,async()=>{
    const s=await setup(kind,{detail:true});
    try{
      const button=s.page.getByRole('button',{name:kind==='doctors'?'تصدير تقرير الطبيب PDF':'تصدير تقرير العيادة PDF',exact:true});await button.waitFor();assert.equal(await button.isDisabled(),false);await button.click();await s.page.locator('main').getByRole('alert').waitFor();
      assert.equal(s.reports()[0].url.pathname,`/hospital-api/${kind}/1/report`);assert.equal(s.calls.filter(c=>c.url.pathname===`/hospital-api/${kind}`).length,0);
    }finally{await s.close();}
  });
}
