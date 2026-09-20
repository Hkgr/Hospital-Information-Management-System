import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
const base='http://127.0.0.1:3194';let f,browser,finished=false;
const gallery=new URL('../.superdesign/tmp/patient-card-regressions/dossiers-phase-three/',import.meta.url);
function fixture(mode){const r=spawnSync('php',['tests/Support/dossier-completion-live.php',mode],{cwd:fileURLToPath(new URL('../../backend/',import.meta.url)),env:process.env,encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);process.stdout.write(r.stdout);}
before(async()=>{fixture('prepare');f=JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-completion-live.json',import.meta.url)));browser=await chromium.launch();await mkdir(gallery,{recursive:true});});
after(async()=>{await browser?.close();try{if(finished)fixture('verify');}finally{fixture('cleanup');}});
async function api(method,path,data={},token=f.token){const fields={facility_id:f.facility,...data};const r=await fetch(`${base}/hospital-api/dossiers${path}${method==='GET'?'?'+new URLSearchParams(fields):''}`,{method,headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${token}`},...(method==='GET'?{}:{body:JSON.stringify(fields)})});assert.equal(r.headers.get('x-test-laravel'),'dossiers');assert.match(r.headers.get('cache-control'),/no-store/);return {status:r.status,body:await r.json()};}
async function open(width,path){const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});await context.addInitScript(token=>sessionStorage.setItem('hospital.bearer',token),f.token);const page=await context.newPage();page.setDefaultTimeout(20000);page.on('dialog',d=>d.accept());await page.goto(`${base}${path}${path.includes('?')?'&':'?'}facility_id=${f.facility}`);return {context,page};}
async function capture(page,name){await page.evaluate(async()=>{await document.fonts.ready;window.scrollTo({top:0,left:0,behavior:'instant'});await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,name);assert.equal(await page.evaluate(()=>{const c=document.querySelector('#main-content > div');return c.scrollWidth>c.clientWidth+1;}),false,name+' inner canvas overflow');if(process.env.DOSSIER_SKIP_GALLERY==='1')return;await page.screenshot({path:fileURLToPath(new URL(name+'.jpg',gallery)),type:'jpeg',quality:85,fullPage:true});await page.screenshot({path:fileURLToPath(new URL(name+'-viewport.jpg',gallery)),type:'jpeg',quality:85});}
async function button(page,name){await page.getByRole('button',{name,exact:true}).click();}
async function choose(page,label,search,text=search){const g=page.getByRole('group',{name:label,exact:true});await g.getByRole('searchbox').fill(search);await g.getByRole('button',{name:new RegExp(text.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'))}).first().click();}
async function save(page,suffix,label='حفظ ومتابعة'){const response=page.waitForResponse(r=>r.url().includes('/hospital-api/dossiers')&&r.url().split('?')[0].endsWith(suffix)&&['POST','PUT'].includes(r.request().method()));await button(page,label);const r=await response;assert.ok(r.ok(),await r.text());return (await r.json()).data;}
async function initial(width){const d=await api('POST','',{request_id:crypto.randomUUID(),person_mode:'new',code:`PH3-${f.tag}-${width}`,opening_date:'2001-01-01',visit_date:'2001-03-02',visit_type_id:f.visit_type,first_name:'ليلى',family_name:'اختبار اصطناعي',birth_date_accuracy:'unknown',gender:'female',displacement_status:'unknown'});assert.equal(d.status,201);const id=d.body.data.id;const m=await api('PUT',`/${id}/medical`,{request_id:crypto.randomUUID(),lock_version:1,is_oncology:false,clinical_history:'قصة اصطناعية للمراجعة فقط'});assert.equal(m.status,200);const v=await api('PUT',`/${id}/visits/${d.body.data.visit.id}`,{lock_version:d.body.data.visit.lock_version,request_id:crypto.randomUUID(),visit_date:'2001-03-02',visit_type_id:f.visit_type,is_referred:true,referring_hospital:'المشفى المحيل الاختباري',referral_date:'2001-03-01',referral_reason:'إحالة واردة اصطناعية',diagnoses:[{diagnosis_id:f.diagnosis,clinic_id:f.clinics[0],diagnosing_staff_id:f.workflow_doctors[0],diagnosed_on:null}]});assert.equal(v.status,200);return v.body.data;}

test('actual Next boundary rejects unauthorized and unknown paths',async()=>{
  assert.equal((await api('GET','/options',{},'')).status,401);assert.equal((await api('GET','/options',{facility_id:f.other})).status,403);
  for(const path of ['/abc/visits/1/clinical','/1/visits/no/medications','/1/visits/1/arbitrary','/1/visits/1/uploads/no']){const r=await fetch(`${base}/hospital-api/dossiers${path}`);assert.equal(r.status,404);assert.equal(r.headers.get('x-test-laravel'),null);}
});

test('resume, multiple clinical entries, prescription, outgoing referral, upload retry and explicit finalization at all widths',async()=>{
  for(const width of [390,768,1440]){
    let d=await initial(width);const {page,context}=await open(width,`/patient-cards/${d.id}/edit?section=3`);
    try{
      await page.getByRole('heading',{name:'الخدمات والإجراءات',exact:true}).waitFor();
      for(const [kind,title] of [['procedure','الإجراء'],['service','الخدمة']])for(let n=1;n<=2;n++){
        await button(page,`إضافة ${title} للزيارة`);await choose(page,`${title} من الدليل ${n}`,f[kind+'_name']);await choose(page,`العيادة · ${title} ${n}`,'عيادة التشخيص 1');await choose(page,`الطبيب المسؤول · ${title} ${n}`,'الطبيب المسؤول 1');
      }
      await capture(page,`clinical-${width}`);d=await save(page,'/clinical');assert.equal(d.clinical.services.length,2);assert.equal(d.clinical.procedures.length,2);
      await button(page,'إضافة وصفة للزيارة');await choose(page,'العيادة · الوصفة','عيادة التشخيص 1');await choose(page,'الطبيب المسؤول · الوصفة','الطبيب المسؤول 1');assert.equal(await page.locator('[name="prescription.prescribed_on"]').inputValue(),'2001-03-02');
      for(let n=1;n<=2;n++){await button(page,'إضافة دواء للوصفة');await choose(page,`الدواء من الدليل ${n}`,f.medication_name);await page.locator(`[name="prescription.items.${n-1}.note"]`).fill(`تعليمات اصطناعية ${n}`);}
      await page.getByRole('button',{name:'إضافة دواء إلى الدليل',exact:true}).first().click();await page.getByRole('dialog').waitFor();await button(page,'إلغاء');assert.equal(await page.locator('[name="prescription.items.0.note"]').inputValue(),'تعليمات اصطناعية 1');
      await page.getByRole('button',{name:'إضافة دواء إلى الدليل',exact:true}).first().click();await page.getByRole('textbox',{name:'كود الدواء الجديد',exact:true}).fill(`NEW-${f.tag}-${width}`);await page.getByRole('textbox',{name:'اسم الدواء الجديد',exact:true}).fill(`دواء جديد اصطناعي ${width} ${f.tag}`);await button(page,'حفظ الدواء');await page.getByRole('dialog').waitFor({state:'hidden'});assert.equal(await page.locator('[name="prescription.items.1.note"]').inputValue(),'تعليمات اصطناعية 2');
      await button(page,'تسجيل نتيجة الزيارة');await page.locator('[name="outcome.code"]').selectOption('DOS-REFER');await choose(page,'العيادة · نتيجة الزيارة','عيادة التشخيص 1');await choose(page,'الطبيب المسؤول · نتيجة الزيارة','الطبيب المسؤول 1');
      const invalid=page.waitForResponse(r=>r.url().endsWith('/medications')&&r.request().method()==='PUT');await button(page,'حفظ ومتابعة');assert.equal((await invalid).status(),422);await page.waitForFunction(()=>document.activeElement?.getAttribute('name')==='outcome.referral_target');await capture(page,`outcome-errors-${width}`);
      await page.locator('[name="outcome.referral_target"]').fill('مشفى الوجهة الاصطناعي');await page.locator('[name="outcome.outgoing_referral_date"]').fill('2001-03-02');await page.locator('[name="outcome.outgoing_referral_reason"]').fill('إحالة صادرة اصطناعية');await capture(page,`prescription-${width}`);d=await save(page,'/medications');assert.equal(d.clinical.prescription.items.length,2);assert.equal(d.visit.referring_hospital,'المشفى المحيل الاختباري');
      await page.getByRole('heading',{name:'المرفقات والمراجعة',exact:true}).waitFor();
      const fileInput=page.locator('input[type=file]');await fileInput.setInputFiles({name:'invalid.png',mimeType:'image/png',buffer:Buffer.from('<html>invalid</html>')});await button(page,'رفع الملف');await page.getByText('محتوى الملف لا يطابق امتداده.',{exact:true}).waitFor();await button(page,'إعادة رفع هذا الملف');await page.getByText('محتوى الملف لا يطابق امتداده.',{exact:true}).waitFor();await button(page,'إلغاء هذا الملف');
      const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aV1sAAAAASUVORK5CYII=','base64');await fileInput.setInputFiles({name:'sample.png',mimeType:'image/png',buffer:png});await button(page,'رفع الملف');await page.getByText('تم حفظ الملف',{exact:true}).waitFor();await page.getByRole('button',{name:'تنزيل',exact:true}).waitFor();const download=page.waitForEvent('download');await button(page,'تنزيل');assert.equal((await download).suggestedFilename(),'sample.png');
      await capture(page,`review-${width}`);await page.locator('[name="confirmed"]').check();d=await save(page,'/review','حفظ المراجعة كمسودة');
      if(width===390){await button(page,'حفظ كمسودة والخروج');await page.getByRole('heading',{name:'بطاقات المرضى',exact:true,level:2}).waitFor();const current=await api('GET',`/${d.id}`);assert.equal(current.body.data.status,'draft');}
      else{await choose(page,'العيادة · المسؤول عن إكمال الزيارة','عيادة التشخيص 1');await choose(page,'الطبيب المسؤول · المسؤول عن إكمال الزيارة','الطبيب المسؤول 1');await button(page,'تفعيل بطاقة المريض وإكمال الزيارة');await page.getByRole('dialog').waitFor();await capture(page,`confirm-${width}`);await button(page,'أؤكد الإكمال');await page.getByRole('heading',{name:'بيانات المريض الحالية',exact:true}).waitFor();const current=await api('GET',`/${d.id}`);assert.equal(current.body.data.status,'active');assert.equal(current.body.data.latest_visit.status,'complete');await capture(page,`detail-${width}`);}
    }catch(error){console.error(await page.evaluate(()=>Array.from(document.querySelectorAll('#main-content *')).map(el=>({tag:el.tagName,cls:el.className,w:el.getBoundingClientRect().width,x:el.getBoundingClientRect().x,scroll:el.scrollWidth,client:el.clientWidth})).filter(e=>e.w>innerWidth||e.scroll>e.client+2).slice(0,35)));throw error;}finally{await context.close();}
  }
  finished=true;
});

test('dossier list and individual reports download through actual Next with selected columns',async()=>{
  const result=await api('GET','',{search:`PH3-${f.tag}`,per_page:10});assert.equal(result.status,200);assert.equal(result.body.data.length,3);
  const d=result.body.data[0];
  for(const [name,path] of [['list','/export'],['dossier',`/${d.id}/report`],['visit',`/${d.id}/visits/${d.latest_visit_id}/report`]])for(const format of ['pdf','xlsx']){
    const r=await fetch(`${base}/hospital-api/dossiers${path}/${format}`,{method:'POST',headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${f.token}`},body:JSON.stringify({facility_id:f.facility,search:`PH3-${f.tag}`})});assert.equal(r.status,200,await(r.ok?Promise.resolve(''):r.text()));assert.equal(r.headers.get('x-test-laravel'),'dossiers');const bytes=Buffer.from(await r.arrayBuffer());assert.ok(bytes.length>500);await writeFile(new URL(`${name}.${format}`,gallery),bytes);
  }
  const {page,context}=await open(1440,`/patient-cards?search=PH3-${f.tag}`);try{await page.getByRole('region',{name:'جدول بطاقات المرضى',exact:true}).waitFor();await page.getByRole('button',{name:'تصدير Excel',exact:true}).waitFor();const download=page.waitForEvent('download');await button(page,'تصدير Excel');assert.match((await download).suggestedFilename(),/\.xlsx$/);await capture(page,'list-1440');}finally{await context.close();}
});

test('later visit reuses visit-only steps, resumes independently and appears above the earlier complete visit',async()=>{
  const list=await api('GET','',{search:`PH3-${f.tag}-768`});const d=list.body.data[0];assert.ok(d);
  const {page,context}=await open(768,`/patient-cards/${d.id}`);
  try{
    await page.getByRole('link',{name:'إضافة زيارة للمريض',exact:true}).click();await page.getByRole('heading',{name:'الزيارة والتشخيصات',exact:true}).waitFor();
    const nav=page.getByRole('list',{name:'مراحل بطاقة المريض'});assert.equal(await nav.getByRole('button',{name:/البيانات الشخصية/}).isDisabled(),true);
    await page.locator('[name="visit_date"]').fill('2002-02-01');await page.locator('[name="visit_type_id"]').selectOption(String(f.visit_type));await page.locator('[name="is_referred"]').selectOption('no');
    await button(page,'إضافة تشخيص للزيارة');await choose(page,'التشخيص من الدليل 1','خباثات الاذن');await choose(page,'العيادة للتشخيص 1','عيادة التشخيص 1');await choose(page,'الطبيب المسؤول عن التشخيص 1','الطبيب المسؤول 1');
    let saved=await save(page,'/subsequent');const next=saved.visit.id;assert.notEqual(next,d.latest_visit_id);assert.equal(saved.visit.dossier_visit_kind,'subsequent');
    await capture(page,'subsequent-768');await button(page,'حفظ كمسودة والخروج');await page.getByRole('heading',{name:'بطاقات المرضى',exact:true,level:2}).waitFor();
    const detail=await api('GET',`/${d.id}`);assert.equal(detail.body.data.latest_visit.id,next);assert.equal(detail.body.data.visit_count,2);assert.equal(detail.body.data.status,'active');
    await page.goto(`${base}/patient-cards/${d.id}?facility_id=${f.facility}`);await page.getByRole('region',{name:'زيارات بطاقة المريض',exact:true}).waitFor();await button(page,'استعراض الزيارة');await page.getByRole('heading',{name:'الزيارة المختارة',exact:true}).waitFor();assert.equal(new URL(page.url()).searchParams.get('visit'),String(d.latest_visit_id));
    await button(page,'العودة إلى آخر زيارة');await page.getByRole('link',{name:'استكمال هذه الزيارة المسودة',exact:true}).click();await page.getByRole('heading',{name:'الزيارة والتشخيصات',exact:true}).waitFor();assert.equal(await page.locator('[name="visit_date"]').inputValue(),'2002-02-01');
  }finally{await context.close();}
});

test('two editors review clinical conflicts explicitly and preserve another editor’s changes',async()=>{
  let d=await initial('conflict');const path=`/${d.id}/visits/${d.visit.id}`;const event={catalog_id:f.service,clinic_id:f.clinics[0],doctor_id:f.workflow_doctors[0],note:'الأصل'};
  let r=await api('PUT',path+'/clinical',{request_id:crypto.randomUUID(),lock_version:d.visit.lock_version,services:[event,event],procedures:[]});assert.equal(r.status,200);d=r.body.data;
  const {page,context}=await open(1440,`/patient-cards/${d.id}/edit?section=3`);
  try{
    await page.locator('[name="services.0.note"]').fill('مسودتي الباقية');const second={...d.clinical.services[1],note:'تعديل المستخدم الآخر'};
    r=await api('PUT',path+'/clinical',{request_id:crypto.randomUUID(),lock_version:d.visit.lock_version,services:[second],procedures:[]});assert.equal(r.status,200);
    const conflict=page.waitForResponse(r=>r.url().endsWith('/clinical')&&r.request().method()==='PUT');await button(page,'حفظ ومتابعة');assert.equal((await conflict).status(),409);assert.equal(await page.locator('[name="services.0.note"]').inputValue(),'مسودتي الباقية');
    await button(page,'جلب أحدث نسخة');await page.getByRole('heading',{name:'مراجعة أحدث نسخة ومسودتك',exact:true}).waitFor();await page.getByRole('checkbox',{name:`تطبيق مسودتي: خدمة:${d.clinical.services[0].id} · الملاحظة`,exact:true}).check();await capture(page,'conflict-1440');await button(page,'اعتماد الاختيارات للمراجعة');
    assert.equal(await page.locator('[name="services.1.note"]').inputValue(),'تعديل المستخدم الآخر');const saved=await save(page,'/clinical');assert.equal(saved.clinical.services[0].note,'مسودتي الباقية');assert.equal(saved.clinical.services[1].note,'تعديل المستخدم الآخر');
    await button(page,'إضافة وصفة للزيارة');await choose(page,'العيادة · الوصفة','عيادة التشخيص 1');await choose(page,'الطبيب المسؤول · الوصفة','الطبيب المسؤول 1');await button(page,'إضافة دواء للوصفة');await choose(page,'الدواء من الدليل 1',f.medication_name);await page.locator('[name="prescription.items.0.note"]').fill('بند جديد من مسودتي');
    const rx={prescribing_clinic_id:f.clinics[0],prescribing_staff_id:f.workflow_doctors[0],prescribed_on:'2001-03-02',note:'وصفة المحرر الآخر',items:[{medication_id:f.medication,display_order:0,note:'بند الآخر'}]};
    r=await api('PUT',path+'/medications',{request_id:crypto.randomUUID(),lock_version:saved.visit.lock_version,prescription:rx,outcome:null});assert.equal(r.status,200);
    const rxConflict=page.waitForResponse(r=>r.url().endsWith('/medications')&&r.request().method()==='PUT');await button(page,'حفظ ومتابعة');assert.equal((await rxConflict).status(),409);await button(page,'جلب أحدث نسخة');await page.getByRole('checkbox',{name:/تطبيق مسودتي: إضافة دواء:/}).check();await button(page,'اعتماد الاختيارات للمراجعة');
    assert.equal(await page.locator('[name="prescription.note"]').inputValue(),'وصفة المحرر الآخر');const merged=await save(page,'/medications');assert.equal(merged.clinical.prescription.id,r.body.data.clinical.prescription.id);assert.equal(merged.clinical.prescription.items.length,2);assert.equal(merged.clinical.prescription.items[0].note,'بند الآخر');assert.equal(merged.clinical.prescription.items[1].note,'بند جديد من مسودتي');
  }finally{await context.close();}
});

test('browser Back cancellation retains the entire unsaved clinical draft',async()=>{
  const d=await initial('navigation');const {page,context}=await open(768,`/patient-cards/${d.id}`);
  try{
    await page.getByRole('link',{name:'استكمال بيانات البطاقة',exact:true}).click();
    await page.getByRole('heading',{name:'الخدمات والإجراءات',exact:true}).waitFor();
    await button(page,'إضافة الخدمة للزيارة');await page.locator('[name="services.0.note"]').fill('مسودة باقية بعد إلغاء الرجوع');
    const current=page.url();page.removeAllListeners('dialog');
    const prompted=page.waitForEvent('dialog',{timeout:10000});
    const attempted=page.evaluate(()=>history.back());const dialog=await prompted;const warning=dialog.message();await dialog.dismiss();await attempted;assert.match(warning,/لم تُحفظ/);
    assert.equal(page.url(),current);assert.equal(await page.locator('[name="services.0.note"]').inputValue(),'مسودة باقية بعد إلغاء الرجوع');
    const saved=await api('GET',`/${d.id}/progress`);assert.equal(saved.body.data.clinical.services.length,0);
  }finally{await context.close();}
});

 test('delayed real clinical doctor responses cannot revive a previous clinic or facility',async()=>{
   const d=await initial('delay');const {page,context}=await open(390,`/patient-cards/${d.id}/edit?section=3`);
   try{
     await context.addInitScript(()=>{const original=window.fetch;window.fetch=async(...args)=>{const r=await original(...args);if(String(args[0]).includes('/options/doctors?'))await new Promise(resolve=>setTimeout(resolve,800));return r;};});
     await page.reload();await button(page,'إضافة الخدمة للزيارة');await choose(page,'الخدمة من الدليل 1',f.service_name);await choose(page,'العيادة · الخدمة 1','عيادة التشخيص 1');await choose(page,'العيادة · الخدمة 1','عيادة التشخيص 2');
     const picker=page.getByRole('group',{name:'الطبيب المسؤول · الخدمة 1',exact:true});await picker.getByRole('button',{name:/الطبيب المسؤول 2/}).waitFor();assert.equal(await picker.getByRole('button',{name:/الطبيب المسؤول 1/}).count(),0);
     await choose(page,'العيادة · الخدمة 1','عيادة التشخيص 1');await page.goto(base+'/patient-cards/'+d.id+'/edit?facility_id='+f.other);await page.getByRole('heading',{name:'تعذّر فتح بطاقة المريض',exact:true}).waitFor();assert.equal(await page.locator('[name="services.0.note"]').count(),0);
   }finally{await context.close();}
 });
