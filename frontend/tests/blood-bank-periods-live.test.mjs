// Regression checks against the real Next standalone -> Laravel -> MariaDB stack.
import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import { randomUUID } from 'node:crypto';
import { output, fixture, fixtureData as f, api, payload, pageAt, eventFields } from './blood-events-live-support.mjs';
let browser;
before(async()=>{fixture('prepare-unified');browser=await chromium.launch();await mkdir(output,{recursive:true});});
after(async()=>{await browser?.close();if(f){try{fixture('verify-unified');}finally{fixture('cleanup');}}});

test('real Next transport registers and corrects all event kinds without periods, with a locked period and with overlaps',async()=>{
  for(const state of ['absent','locked','overlapping']){
    fixture(`periods-${state}`);
    for(const kind of ['donation','issue','transfusion']){
      const input={...payload(kind==='donation'?'donation':'benefit'),facility_id:f.second,clinic_id:f.second_clinic};
      if(kind==='transfusion'){input.benefit_kind='transfusion';input.benefit_link_mode='independent';}
      const event=(await api('/events','POST',input,201)).data;assert.equal(event.reporting_period_id,null);
      const updated=(await api(`/events/${event.id}`,'PUT',{...input,person:undefined,person_id:event.person_id,occurred_on:'1999-01-01',lock_version:event.lock_version,request_id:randomUUID()})).data;
      assert.equal(updated.reporting_period_id,null);assert.equal(updated.occurred_on,'1999-01-01');
      const listed=await api(`/events?facility_id=${f.second}&from=1999-01-01&to=1999-01-01`,'GET',{facility_id:f.second});assert.ok(listed.data.some(e=>e.id===event.id));
    }
    fixture('periods-verify');
  }
});

test('one event blood-group section initializes a new person and preserves the draft through nested dialogs and save failure',async()=>{
  const {page,context}=await pageAt(browser);try{
    await page.getByRole('button',{name:'تسجيل تبرع',exact:true}).click();await page.getByRole('combobox',{name:'الشخص',exact:true}).selectOption('new');
    assert.equal(await page.getByRole('combobox',{name:'زمرة ABO',exact:true}).count(),1);
    assert.equal(await page.getByRole('combobox',{name:'عامل Rh',exact:true}).count(),1);
    await page.getByText('زمرة الدم لهذه الواقعة',{exact:true}).waitFor();assert.equal(await page.getByRole('button',{name:'استخدام الزمرة الحالية لهذه الواقعة'}).count(),0);
    await page.getByLabel('الاسم الأول',{exact:true}).fill('زمرة موحدة');await page.getByLabel('اسم العائلة',{exact:true}).fill('اختبار');
    const component=(await api('/options')).data.blood_components[0].id;await eventFields(page,component);
    await page.getByLabel('زمرة ABO',{exact:true}).selectOption('AB');await page.getByLabel('عامل Rh',{exact:true}).selectOption('negative');
    await page.getByRole('button',{name:'إضافة طبيب',exact:true}).click();await page.getByRole('dialog').last().getByRole('button',{name:'إلغاء',exact:true}).click();
    assert.equal(await page.getByLabel('زمرة ABO',{exact:true}).inputValue(),'AB');
    for(const width of [390,768,1440]){await page.setViewportSize({width,height:1000});await page.getByText('زمرة الدم لهذه الواقعة',{exact:true}).scrollIntoViewIfNeeded();await page.screenshot({path:`${output}/single-blood-group-${width}.png`});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);}
    await page.getByLabel('التاريخ الفعلي',{exact:true}).fill('2099-01-01');const rejected=page.waitForResponse(r=>r.url().includes('/hospital-api/blood-bank/events')&&r.request().method()==='POST');await page.getByRole('button',{name:'حفظ الواقعة',exact:true}).click();assert.equal((await rejected).status(),422);await page.getByText('التاريخ الفعلي لا يمكن أن يكون في المستقبل.',{exact:true}).waitFor();
    assert.equal(await page.getByLabel('عامل Rh',{exact:true}).inputValue(),'negative');await page.getByLabel('التاريخ الفعلي',{exact:true}).fill('1999-01-01');
    const done=page.waitForResponse(r=>r.url().includes('/hospital-api/blood-bank/events')&&r.request().method()==='POST');await page.getByRole('button',{name:'حفظ الواقعة',exact:true}).click();const r=await done;assert.equal(r.status(),201,await r.text());const e=(await r.json()).data;assert.equal(e.reporting_period_id,null);const p=(await api(`/people/${e.person_id}`)).data;assert.equal(p.blood_group,'AB');assert.equal(p.rh,'negative');assert.equal(e.blood_group,p.blood_group);
  }finally{await context.close();}
});

test('person changes seed blood once, clear unknowns and ignore a previous person late response; edits use event snapshot',async()=>{
  const people=[];for(const [name,blood,rh]of [['ألف','O','positive'],['باء','A','negative'],['مجهول',null,null]]){
    const input=payload('benefit');input.person={...input.person,first_name:name,family_name:'زمرة للاختيار'};input.blood_group=blood;input.rh=rh;const e=(await api('/events','POST',input,201)).data;people.push((await api(`/people/${e.person_id}`)).data);
  }
  const{page,context}=await pageAt(browser);try{
    await page.getByRole('button',{name:'تسجيل استفادة',exact:true}).click();const group=page.getByRole('group',{name:'الشخص الموجود',exact:true});
    const select=async i=>{await group.getByRole('searchbox').fill(people[i].code);await group.getByRole('button',{name:new RegExp(people[i].code)}).click();};
    const blood=page.getByLabel('زمرة ABO',{exact:true}),rh=page.getByLabel('عامل Rh',{exact:true});
    await select(0);await page.waitForFunction(()=>document.querySelector('[aria-label="زمرة ABO"]')?.value==='O');await blood.selectOption('B');await rh.selectOption('negative');
    await select(0);assert.equal(await blood.inputValue(),'B');
    await page.evaluate(id=>{const original=window.fetch.bind(window);window.fetch=async(...args)=>{const response=await original(...args);if(new URL(String(args[0]),location.origin).pathname===`/hospital-api/blood-bank/people/${id}`)await new Promise(r=>setTimeout(r,1800));return response;};},people[1].id);
    await select(1);await page.getByText('جارٍ جلب بيانات الشخص…',{exact:true}).waitFor();await select(2);
    await page.waitForTimeout(2100);assert.equal(await blood.inputValue(),'');assert.equal(await rh.inputValue(),'');
    await select(0);await page.waitForFunction(()=>document.querySelector('[aria-label="زمرة ABO"]')?.value==='O');await blood.selectOption('B');await rh.selectOption('negative');
    await page.getByRole('dialog').getByRole('combobox',{name:'نوع الاستفادة'}).selectOption('issue');await eventFields(page,(await api('/options')).data.blood_components[0].id);await blood.selectOption('B');await rh.selectOption('negative');
    const done=page.waitForResponse(r=>r.url().includes('/hospital-api/blood-bank/events')&&r.request().method()==='POST');await page.getByRole('button',{name:'حفظ الواقعة',exact:true}).click();const r=await done;assert.equal(r.status(),201,await r.text());const e=(await r.json()).data;
    assert.equal((await api(`/people/${people[0].id}`)).data.blood_group,'O');await page.waitForURL(/\/events\/\d+/);await page.getByRole('button',{name:'تعديل الواقعة',exact:true}).click();assert.equal(await blood.inputValue(),'B');assert.equal(await rh.inputValue(),'negative');assert.equal(e.blood_group,'B');
  }finally{await context.close();}
});

test('retrying the same person request preserves a blood-group draft entered during its failure',async()=>{
  const e=(await api('/events','POST',payload('benefit'),201)).data;
  const p=(await api(`/people/${e.person_id}`)).data;
  const{page,context}=await pageAt(browser);try{
    await page.evaluate(id=>{const original=window.fetch.bind(window);let first=true;window.fetch=async(...args)=>{const response=await original(...args);if(first&&new URL(String(args[0]),location.origin).pathname===`/hospital-api/blood-bank/people/${id}`){first=false;throw new TypeError('Synthetic transport failure after real response');}return response;};},p.id);
    await page.getByRole('button',{name:'تسجيل استفادة',exact:true}).click();const group=page.getByRole('group',{name:'الشخص الموجود',exact:true});await group.getByRole('searchbox').fill(p.code);await group.getByRole('button',{name:new RegExp(p.code)}).click();
    const retry=page.getByRole('dialog').getByRole('button',{name:'إعادة المحاولة',exact:true});await retry.waitFor();
    const blood=page.getByLabel('زمرة ABO',{exact:true}),rh=page.getByLabel('عامل Rh',{exact:true});await blood.selectOption('AB');await rh.selectOption('negative');
    await retry.click();await page.getByText(`${p.name} · ${p.code}`,{exact:true}).waitFor();assert.equal(await blood.inputValue(),'AB');assert.equal(await rh.inputValue(),'negative');
  }finally{await context.close();}
});
