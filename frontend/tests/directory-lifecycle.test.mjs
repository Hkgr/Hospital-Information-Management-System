import {test, before, after} from 'node:test';
import assert from 'node:assert/strict';
import {chromium} from 'playwright';
import {doctors, options} from './doctor-fixtures.mjs';
import {clinics, facility, user, paginated} from './clinic-fixtures.mjs';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3103';
if (!['127.0.0.1', 'localhost', '[::1]'].includes(new URL(base).hostname)) throw Error('Local tests only');
let browser;
before(async()=>{browser=await chromium.launch({channel:'chrome'});});
after(async()=>{await browser?.close();});
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};}
async function setup(kind, {active=true, archived=false, reference=true, limited=false, detail=false}={}) {
 const row={...structuredClone(kind==='doctors'?doctors[0]:clinics[0]),is_active:active,archived_at:archived?'2026-09-12 10:00:00':null};
 const context=await browser.newContext();await context.addInitScript(()=>sessionStorage.setItem('hospital.bearer','lifecycle-test'));
 const page=await context.newPage();page.setDefaultTimeout(6000);const calls=[],gates=[];let nextList,previewFailure=false,conflict=false;
 const hold=()=>{const g={...deferred(),started:deferred()};gates.push(g);nextList=g;return g;};
 await page.route('**/*',async route=>{
  const req=route.request(),url=new URL(req.url());if(url.origin!==new URL(base).origin)return route.abort();if(!url.pathname.startsWith('/hospital-api/'))return route.continue();
  calls.push({url,method:req.method(),body:req.postDataJSON()});
  const path=url.pathname;
  if(path.endsWith('/user'))return route.fulfill({json:{data:{user,access:[1,2].map(id=>({facility:{...facility,id},roles:[],permissions:['doctors.view','doctors.link','clinics.view','doctors.export','clinics.export',...limited?[]:['clinics.delete','clinics.update']]}))}}});
  if(path.endsWith('/options'))return route.fulfill({json:{data:{...options,capabilities:{...options.capabilities,update:!limited,delete:!limited}}}});
  if(path.endsWith('/deletion-preview'))return route.fulfill(previewFailure?{status:500,json:{error:{message:'تعذّرت المعاينة'}}}:{json:{data:{action:reference?'archive':'delete',organizational_links:reference?1:0,has_other_references:false,lock_version:row.lock_version,archived:!!row.archived_at}}});
  if(/\/(archive|restore|reactivate|deactivate)$/.test(path)&&req.method()==='POST'){
   if(conflict){reference=true;return route.fulfill({status:409,json:{error:{code:kind==='doctors'?'DOCTOR_VERSION_CONFLICT':'CLINIC_VERSION_CONFLICT',message:'تغيّرت البيانات. أعد تحميل السجل.'}}});}
   const action=path.split('/').at(-1);row.lock_version++;row.is_active=action==='reactivate';if(action==='archive')row.archived_at='2026-09-12 12:00:00';if(action==='restore')row.archived_at=null;
   return route.fulfill({json:{data:row}});
  }
  if(req.method()==='DELETE')return route.fulfill({status:204});
  if(path===`/hospital-api/${kind}`){const gate=nextList;nextList=null;if(gate){gate.started.resolve();await gate.promise;}try{return await route.fulfill({json:paginated(row.archived_at&&url.searchParams.get('status')!=='archived'?[]:[row])});}catch{return;}}
  if(path===`/hospital-api/${kind}/1`)return route.fulfill({json:{data:row}});
  return route.fulfill({json:paginated([])});
 });
 await page.goto(`${base}/${kind}${detail?'/1':''}?facility_id=1${archived?'&status=archived':''}`);
 await page.getByRole(detail?'heading':'link',{name:detail?(kind==='doctors'?row.name:row.name_ar):row.code,exact:true}).waitFor();
 return {page,row,calls,hold,previewFail:v=>previewFailure=v,conflict:v=>conflict=v,name:kind==='doctors'?row.name:row.name_ar,close:async()=>{gates.forEach(g=>g.resolve());await context.close();}};
}

for(const kind of ['doctors','clinics']){
 test(`${kind}: late deletion preview is ignored after close and facility change`,async()=>{
  const s=await setup(kind);const gate=deferred();try{
   await s.page.route(`**/hospital-api/${kind}/1/deletion-preview*`,async route=>{if(new URL(route.request().url()).searchParams.get('facility_id')!=='1')return route.fallback();await gate.promise;try{await route.fulfill({json:{data:{action:'delete',organizational_links:0,has_other_references:false,lock_version:1,archived:false}}});}catch{}});
   await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();await s.page.getByRole('dialog').waitFor();await s.page.keyboard.press('Escape');
   await s.page.getByRole('combobox',{name:'المنشأة',exact:true}).selectOption('2');await s.page.waitForURL(/facility_id=2/);
   await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();const modal=s.page.getByRole('dialog');await modal.getByRole('button',{name:'أرشفة وإزالة من الدليل',exact:true}).waitFor();
   gate.resolve();await s.page.waitForTimeout(100);assert.equal(await modal.getByRole('button',{name:'حذف نهائي',exact:true}).count(),0);assert.equal(s.calls.filter(c=>c.method==='POST'||c.method==='DELETE').length,0);
  }finally{gate.resolve();await s.close();}
 });
 test(`${kind}: archived history stays readable and detail hard delete returns to list`,async()=>{
  const s=await setup(kind,{active:false,archived:true,detail:true});try{
   await s.page.route(`**/hospital-api/${kind}/1/link-history*`,route=>route.fulfill({json:paginated([{id:1,code:'HISTORY',name:'ارتباط محفوظ',starts_on:'2020-01-01',ends_on:'2026-09-12'}])}));
   await s.page.getByRole('button',{name:'تاريخ الارتباطات في المنشأة',exact:true}).click();await s.page.getByRole('cell',{name:'ارتباط محفوظ',exact:true}).waitFor();
   assert.equal(await s.page.getByRole('button',{name:`تعديل ${s.name}`,exact:true}).count(),0);await s.page.getByRole('button',{name:`استعادة ${s.name}`,exact:true}).waitFor();
  }finally{await s.close();}
  const d=await setup(kind,{detail:true,reference:false});try{await d.page.getByRole('button',{name:`حذف ${d.name}`,exact:true}).click();await d.page.getByRole('dialog').getByRole('button',{name:'حذف نهائي',exact:true}).click();await d.page.waitForURL(u=>u.pathname===`/${kind}`);assert.equal(new URL(d.page.url()).searchParams.get('facility_id'),'1');}finally{await d.close();}
 });
 test(`${kind}: references offer explicit archive, preserve history, and refresh blocks export`,async()=>{
  const s=await setup(kind);try{
   await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();const modal=s.page.getByRole('dialog');
   const archive=modal.getByRole('button',{name:'أرشفة وإزالة من الدليل',exact:true});await archive.waitFor();
   assert.equal(s.calls.filter(c=>c.method==='DELETE'||c.method==='POST').length,0);
   if(kind==='doctors')assert.match(await modal.innerText(),/جميع المنشآت/);
   const gate=s.hold();await archive.dblclick();await gate.started.promise;await modal.waitFor({state:'detached'});
   assert.equal(s.calls.filter(c=>c.url.pathname.endsWith('/archive')).length,1);
   assert.equal(await s.page.getByRole('button',{name:'Excel',exact:true}).isDisabled(),true);
   gate.resolve();await s.page.getByText('تمت الأرشفة وحفظ التاريخ.',{exact:true}).waitFor();
   await s.page.getByRole('combobox',{name:'الحالة',exact:true}).selectOption('archived');
   await s.page.getByRole('button',{name:`استعادة ${s.name}`,exact:true}).click();
   await s.page.getByRole('dialog').getByRole('button',{name:'استعادة كغير فعال',exact:true}).click();
   await s.page.getByRole('dialog').waitFor({state:'detached'});
   assert.equal(s.calls.filter(c=>c.url.pathname.endsWith('/reactivate')).length,0);
  }finally{await s.close();}
 });
 test(`${kind}: inactive detail has explicit reactivation without editing links`,async()=>{
  const s=await setup(kind,{active:false,detail:true});try{
   await s.page.getByRole('button',{name:`إعادة تفعيل ${s.name}`,exact:true}).click();
   await s.page.getByRole('dialog').getByRole('button',{name:'إعادة تفعيل',exact:true}).click();
   await s.page.getByRole('dialog').waitFor({state:'detached'});await s.page.getByRole('button',{name:`تعطيل ${s.name}`,exact:true}).waitFor();
   assert.deepEqual(s.calls.find(c=>c.url.pathname.endsWith('/reactivate')).body,{facility_id:1,lock_version:1});
  }finally{await s.close();}
 });
 test(`${kind}: preview failure retries and a conflicting archive is not auto-retried`,async()=>{
  const s=await setup(kind);try{
   s.previewFail(true);await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();const modal=s.page.getByRole('dialog');await modal.getByRole('alert').waitFor();
   assert.equal(s.calls.filter(c=>c.method==='POST'||c.method==='DELETE').length,0);s.previewFail(false);await modal.getByRole('button',{name:'إعادة المعاينة',exact:true}).click();
   s.conflict(true);await modal.getByRole('button',{name:'أرشفة وإزالة من الدليل',exact:true}).click();await modal.getByRole('alert').waitFor();
   assert.equal(s.calls.filter(c=>c.method==='POST').length,1);assert.equal(await modal.getByRole('button',{name:'أرشفة وإزالة من الدليل',exact:true}).isDisabled(),true);
   await modal.getByRole('button',{name:'إغلاق وتحديث السجل',exact:true}).click();await modal.waitFor({state:'detached'});
  }finally{await s.close();}
 });
 test(`${kind}: unreferenced delete stays distinct, limited users cannot change lifecycle`,async()=>{
  const s=await setup(kind,{reference:false});try{await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();await s.page.getByRole('dialog').getByRole('button',{name:'حذف نهائي',exact:true}).click();await s.page.getByRole('dialog').waitFor({state:'detached'});assert.equal(s.calls.filter(c=>c.method==='DELETE').length,1);assert.equal(s.calls.filter(c=>c.url.pathname.endsWith('/archive')).length,0);}finally{await s.close();}
  const limited=await setup(kind,{limited:true,active:false});try{assert.equal(await limited.page.getByRole('button',{name:`إعادة تفعيل ${limited.name}`,exact:true}).count(),0);assert.equal(await limited.page.getByRole('button',{name:`حذف ${limited.name}`,exact:true}).count(),0);}finally{await limited.close();}
 });
}

test('doctor activation explains invalid medical type and never changes links',async()=>{
 const s=await setup('doctors',{active:false});try{
  await s.page.route('**/hospital-api/doctors/1/reactivate',route=>route.fulfill({status:422,json:{message:'Validation',errors:{staff_type_id:['يتعذر التفعيل: نوع الطبيب غير فعال أو غير معتمد.']}}}));
  await s.page.getByRole('button',{name:`إعادة تفعيل ${s.name}`,exact:true}).click();await s.page.getByRole('dialog').getByRole('button',{name:'إعادة تفعيل',exact:true}).click();await s.page.getByRole('dialog').getByText('يتعذر التفعيل: نوع الطبيب غير فعال أو غير معتمد.',{exact:true}).waitFor();
  assert.equal(s.calls.filter(c=>c.method==='PUT').length,0);
 }finally{await s.close();}
});

for(const width of [390,768,1440])test(`archive confirmation is readable and keyboard accessible at ${width}px`,async()=>{
 const s=await setup('doctors');try{
  await s.page.setViewportSize({width,height:900});await s.page.getByRole('button',{name:`حذف ${s.name}`,exact:true}).click();const modal=s.page.getByRole('dialog');const confirm=modal.getByRole('button',{name:'أرشفة وإزالة من الدليل',exact:true});await confirm.waitFor();
  await confirm.scrollIntoViewIfNeeded();const box=await confirm.boundingBox();assert.ok(box.x>=0&&box.x+box.width<=width&&box.y>=0&&box.y+box.height<=900);
  await modal.getByRole('button',{name:'إلغاء',exact:true}).focus();await s.page.keyboard.press('Tab');assert.equal(await modal.evaluate(el=>el.contains(document.activeElement)),true);await s.page.keyboard.press('Escape');await modal.waitFor({state:'detached'});
 }finally{await s.close();}
});
