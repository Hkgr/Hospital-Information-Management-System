// Report verification uses real Next -> Laravel -> guarded MariaDB, including browser downloads.
import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { mkdir, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
import { base, output, fixture, fixtureData as f, api, request, payload, pageAt } from './blood-events-live-support.mjs';
let browser, person, event, issue, linked;
before(async () => {
  fixture('prepare-unified'); browser = await chromium.launch(); await mkdir(output, { recursive: true });
  for (let i=0; i<24; i++) {
    const input=payload(i%2 ? 'benefit' : 'donation', person);
    if(!person) input.person={...input.person,first_name:'تقرير اصطناعي',family_name:'اسم عربي طويل للمراجعة',address_line:'عنوان سكن اصطناعي '.repeat(8)};
    input.quantity=(0.45+i/100).toFixed(4); input.beneficiary_entity=i%2?'جهة اصطناعية':undefined; input.entity_address=i%2?'عنوان جهة منفصل عن السكن':undefined;
    const e=(await api('/events','POST',input,201)).data; person=e.person_id; if(i===0)event=e; if(i===1)issue=e;
  }
  linked=(await api('/events','POST',{...payload('benefit',person),benefit_kind:'transfusion',benefit_link_mode:'linked',issue_event_id:issue.id},201)).data;
});
after(async()=>{await browser?.close(); if(f){try{fixture('verify-unified');}finally{fixture('cleanup');}}});

test('all event/person/list PDF and XLSX reports use full filtered totals, stage types, original units and scoped permission',async()=>{
  const paths={ledger:'/events/export/',person:`/people/${person}/report/`,donation:`/events/${event.id}/report/`,issue:`/events/${issue.id}/report/`,transfusion:`/events/${linked.id}/report/`};
  for(const [name,path] of Object.entries(paths))for(const format of ['pdf','xlsx']){
    const r=await request(`${path}${format}?per_page=10&page=2`);assert.equal(r.status,200);assert.match(r.headers.get('content-disposition'),new RegExp(`BB-.*\\.${format}`));
    const bytes=Buffer.from(await r.arrayBuffer());assert.equal(bytes.subarray(0,format==='pdf'?5:2).toString(),format==='pdf'?'%PDF-':'PK');await writeFile(`${output}/${name}.${format}`,bytes);
    const denied=await request(`${path}${format}`,'GET',undefined,f.viewer_token);assert.equal(denied.status,403);
  }
  const list=await api(`/events?person_id=${person}&per_page=10&page=2`);assert.equal(list.meta.total,25);assert.deepEqual(list.totals,{donations:12,benefits:12,unique_people:1});
});

test('real delayed list responses, pending search, failures and history never enable mismatched export',async()=>{
  const {page,context}=await pageAt(browser);
  try{
    const excel=page.getByRole('button',{name:'Excel',exact:true});await excel.waitFor();
    const ready=()=>page.waitForFunction(()=>![...document.querySelectorAll('button')].find(b=>b.textContent==='Excel')?.disabled);await ready();
    await page.evaluate(()=>{const original=window.fetch.bind(window);window.fetch=async(...args)=>{const response=await original(...args);const url=new URL(String(args[0]),location.origin);if(url.pathname==='/hospital-api/blood-bank/events'&&url.searchParams.get('search')==='تقرير')await new Promise(r=>setTimeout(r,1600));return response;};});
    const search=page.getByRole('searchbox',{name:'البحث في بنك الدم',exact:true});await search.fill('تقرير');assert.equal(await excel.isDisabled(),true);
    await page.getByRole('status').filter({hasText:'جارٍ تحديث النتائج'}).waitFor();assert.equal(await excel.isDisabled(),true);assert.ok(await page.getByRole('region',{name:'جدول وقائع بنك الدم',exact:true}).getByRole('row').count()>1);
    await search.fill('تقرير اصطناعي');await ready();await page.waitForTimeout(1700);assert.equal(await search.inputValue(),'تقرير اصطناعي');
    await page.evaluate(()=>history.pushState(null,'','/blood-bank?search=تقرير%20اصطناعي&sort=invalid'));
    await page.getByRole('alert').filter({hasText:'التصدير يتطلب نجاح إعادة التحميل'}).waitFor();assert.equal(await excel.isDisabled(),true);
    await page.getByRole('button',{name:'إعادة المحاولة',exact:true}).click();assert.equal(await excel.isDisabled(),true);
    await page.getByRole('combobox',{name:'الترتيب',exact:true}).selectOption('name');await ready();
    const download=page.waitForEvent('download');await excel.click();assert.match((await download).suggestedFilename(),/\.xlsx$/);
    await page.goto(`${base}/blood-bank?search=تقرير&kind=donation&per_page=10`);await ready();
    await page.goto(`${base}/blood-bank?search=لايوجد&kind=benefit`);await ready();await page.goBack();await ready();assert.equal(await search.inputValue(),'تقرير');assert.equal(await page.getByRole('combobox',{name:'نوع الواقعة',exact:true}).inputValue(),'donation');
    await search.fill('مسودة لم تعتمد');await page.goForward();await ready();await page.waitForTimeout(600);assert.equal(await search.inputValue(),'لايوجد');assert.equal(new URL(page.url()).searchParams.get('search'),'لايوجد');
    await page.evaluate(id=>history.pushState(null,'',`/blood-bank?facility_id=${id}`),f.second);await ready();assert.equal(await page.getByRole('link',{name:event.code,exact:true}).count(),0);
  }finally{await context.close();}
});

test('individual browser downloads are independent of list state and pages are responsive',async()=>{
  for(const width of [390,768,1440])for(const [name,path]of [['person',`/blood-bank/people/${person}`],['transfusion',`/blood-bank/events/${linked.id}`]]){
    const{page,context,errors}=await pageAt(browser,path,width);try{
      const button=page.getByRole('button',{name:'PDF',exact:true});await button.waitFor();await page.waitForFunction(()=>![...document.querySelectorAll('button')].find(b=>b.textContent==='PDF')?.disabled);
      assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await page.screenshot({path:`${output}/report-${name}-${width}.png`,fullPage:true});
      if(width===1440){const download=page.waitForEvent('download',{timeout:65000});await button.click();assert.match((await download).suggestedFilename(),/\.pdf$/);}assert.deepEqual(errors,[]);
    }finally{await context.close();}
  }
});
