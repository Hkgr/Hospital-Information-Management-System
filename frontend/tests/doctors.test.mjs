import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';
import { doctors, clinicLinks, options, facility, permissions, user, paginated } from './doctor-fixtures.mjs';

const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3101';
if (!['127.0.0.1','localhost','[::1]'].includes(new URL(base).hostname)) throw new Error('Doctor UI tests require a local target.');
let browser;
before(async () => { browser = await chromium.launch({ channel: process.env.PLAYWRIGHT_CHANNEL || undefined }); });
after(async () => { await browser?.close(); });
async function setup({ width=1440, access=[{facility,permissions,roles:[]}], settings=options, path='/doctors', override=()=>false }={}) {
  const context=await browser.newContext({viewport:{width,height:900},reducedMotion:'reduce'});
  await context.addInitScript(()=>sessionStorage.setItem('hospital.bearer','doctor-ui-fixture'));
  const page=await context.newPage(); const calls=[],errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
    const request=route.request(),url=new URL(request.url());
    if(url.origin!==new URL(base).origin) return route.abort();
    if(!url.pathname.startsWith('/hospital-api/')) return route.continue();
    calls.push({url,method:request.method(),body:request.postDataJSON(),auth:request.headers().authorization});
    if(await override(route,url)) return;
    if(url.pathname.endsWith('/deletion-preview')) return route.fulfill({json:{data:{action:'delete',organizational_links:0,has_other_references:false,lock_version:1,archived:false}}});
    if(url.pathname.endsWith('/user')) return route.fulfill({json:{data:{user,access}}});
    if(url.pathname.endsWith('/options')) return route.fulfill({json:{data:settings}});
    if(url.pathname.endsWith('/options/clinics')) {
      const search=url.searchParams.get('search')||'';
      return route.fulfill({json:paginated(clinicLinks.filter(c=>(c.code+c.name_ar).includes(search)).map(c=>({...c,is_linked:url.searchParams.has('doctor_id')&&c.is_linked})))});
    }
    if(/\/doctors\/\d+\/clinics$/.test(url.pathname)) return route.fulfill({json:paginated(clinicLinks.filter(c=>c.is_linked))});
    const id=Number(url.pathname.match(/\/doctors\/(\d+)/)?.[1]);
    if(id) return route.fulfill({json:{data:doctors.find(d=>d.id===id)||doctors[0]}});
    return route.fulfill({json:paginated(doctors)});
  });
  await page.goto(base+path);
  return {page,context,calls,errors};
}

test('list loads independently of delayed capabilities and relation choices stay lazy until opened',async()=>{
  let release;const gate=new Promise(resolve=>{release=resolve;});
  const {page,context,calls}=await setup({override:async(route,url)=>{
    if(url.pathname==='/hospital-api/doctors/options'){await gate;try{await route.fulfill({json:{data:options}});}catch{}return true;}return false;
  }});
  try{
    await page.getByRole('link',{name:'D001',exact:true}).waitFor({timeout:3000});
    assert.equal(await page.getByRole('button',{name:'إضافة طبيب جديد',exact:true}).count(),0);
    assert.equal(calls.filter(c=>c.url.pathname.endsWith('/options/clinics')).length,0);
    release();await page.getByRole('button',{name:'إضافة طبيب جديد',exact:true}).waitFor();
    await page.locator('summary').filter({hasText:'العيادة: الكل'}).click();
    await page.getByRole('button',{name:/العيادة الداخلية ·/}).click();
    await page.waitForURL(/clinic_id=1/);
    assert.match(await page.locator('summary').filter({hasText:'العيادة:'}).innerText(),/العيادة الداخلية/);
    assert.equal(calls.filter(c=>c.url.pathname.endsWith('/options/clinics')).length,1);
  }finally{release();await context.close();}
});

test('refresh keeps current rows, ignores obsolete search responses and clears rows on facility change',async()=>{
  let release,started;const gate=new Promise(r=>{release=r;});const requested=new Promise(r=>{started=r;});
  const access=[1,2].map(id=>({facility:{...facility,id},permissions,roles:[]}));
  const {page,context}=await setup({access,override:async(route,url)=>{
    if(url.pathname!=='/hospital-api/doctors')return false;
    if(url.searchParams.get('search')==='قديم'){started();await gate;try{await route.fulfill({json:paginated([{...doctors[0],code:'STALE',name:'استجابة قديمة'}])});}catch{}return true;}
    if(url.searchParams.get('facility_id')==='2'){await route.fulfill({json:paginated([])});return true;}
    if(url.searchParams.get('search')==='أحدث'){await route.fulfill({json:paginated([{...doctors[0],code:'LATEST'}])});return true;}return false;
  }});
  try{
    await page.getByRole('link',{name:'D001',exact:true}).waitFor();
    await page.getByLabel('البحث في الأطباء').fill('قديم');await requested;
    assert.equal(await page.getByRole('link',{name:'D001',exact:true}).isVisible(),true);
    await page.getByText('جارٍ تحديث النتائج…',{exact:true}).waitFor();
    await page.getByLabel('البحث في الأطباء').fill('أحدث');await page.getByRole('link',{name:'LATEST',exact:true}).waitFor();
    release();await page.waitForTimeout(150);assert.equal(await page.getByRole('link',{name:'STALE',exact:true}).count(),0);
    await page.getByRole('combobox',{name:'المنشأة',exact:true}).selectOption('2');
    await page.getByRole('link',{name:'LATEST',exact:true}).waitFor({state:'detached'});
  }finally{release();await context.close();}
});

test('permissions gate navigation and global actions while link-only editing sends no directory fields',async()=>{
  const limited={...options,capabilities:{...options.capabilities,create:false,update:false,delete:false}};
  const {page,context,calls}=await setup({settings:limited});
  try{
    await page.getByRole('link',{name:'D001',exact:true}).waitFor();
    assert.equal(await page.locator('#desktop-navigation').getByRole('link',{name:'الأطباء',exact:true}).count(),1);
    assert.equal(await page.getByRole('button',{name:'إضافة طبيب جديد',exact:true}).count(),0);
    assert.equal(await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).count(),0);
    await page.getByRole('button',{name:'إدارة عيادات أحمد الاختباري',exact:true}).click();
    const dialog=page.getByRole('dialog');
    assert.equal(await dialog.getByLabel('الاسم الكامل *').count(),0);
    await dialog.getByRole('checkbox',{name:/العيادة الداخلية/}).uncheck();
    await dialog.getByRole('button',{name:'حفظ الارتباطات',exact:true}).click();
    await dialog.waitFor({state:'detached'});
    const write=calls.find(c=>c.method==='PUT');
    assert.equal(write.url.pathname,'/hospital-api/doctors/1/clinics');
    assert.deepEqual(write.body,{facility_id:1,clinic_add_ids:[],clinic_remove_ids:[1],lock_version:1});
  }finally{await context.close();}
  const denied=await setup({access:[]});
  try{await denied.page.locator('main').getByRole('alert').waitFor();assert.equal(denied.calls.filter(c=>c.url.pathname.startsWith('/hospital-api/doctors')).length,0);}finally{await denied.context.close();}
});

test('creation without clinics validates in place and blocks duplicate save, escape while saving and restores focus',async()=>{
  let release,writes=0;const gate=new Promise(resolve=>{release=resolve;});
  const {page,context,calls}=await setup({override:async(route,url)=>{
    if(route.request().method()!=='POST'||url.pathname!=='/hospital-api/doctors') return false;
    writes++;await gate;await route.fulfill({status:422,json:{errors:{code:['كود الطبيب مستخدم.']}}});return true;
  }});
  try{
    const opener=page.getByRole('button',{name:'إضافة طبيب جديد',exact:true});await opener.click();
    const dialog=page.getByRole('dialog');await dialog.getByLabel('كود الطبيب *').fill('NEW');await dialog.getByLabel('الاسم الكامل *').fill('طبيب جديد');
    await dialog.getByLabel('نوع الطبيب *').selectOption('1');await dialog.getByRole('checkbox',{name:'الطب الداخلي',exact:true}).check();
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await page.keyboard.press('Escape');assert.equal(await dialog.count(),1);
    release();await dialog.getByText('كود الطبيب مستخدم.',{exact:true}).waitFor();assert.equal(writes,1);
    assert.equal(await dialog.getByLabel('كود الطبيب *').inputValue(),'NEW');assert.deepEqual(calls.find(c=>c.method==='POST').body.clinic_add_ids,[]);
    await page.keyboard.press('Escape');assert.equal(await opener.evaluate(el=>el===document.activeElement),true);
  }finally{release();await context.close();}
});

test('two-user conflict keeps draft, loads current hidden clinics, requires explicit review, preserves others and refreshes reopen',async()=>{
  let writes=0;let current=structuredClone(doctors[0]);
  const hidden={...clinicLinks[3],id:40,code:'HIDDEN',name_ar:'عيادة لم تظهر في البحث',is_linked:true};
  const {page,context,calls}=await setup({override:async(route,url)=>{
    if(route.request().method()==='PUT'){
      writes++; if(writes===1){current={...current,name:'اسم عدله مستخدم آخر',phone:'009639999',lock_version:2};await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});}
      else{const body=route.request().postDataJSON();current={...current,...body,lock_version:body.lock_version+1};await route.fulfill({json:{data:current}});}return true;
    }
    if(url.pathname==='/hospital-api/doctors/1'){await route.fulfill({json:{data:current}});return true;}
    if(url.pathname==='/hospital-api/doctors'){await route.fulfill({json:paginated([current])});return true;}
    if(url.pathname==='/hospital-api/doctors/1/clinics'){await route.fulfill({json:paginated([clinicLinks[0],hidden])});return true;}
    if(url.pathname.endsWith('/options/clinics')&&writes){const search=url.searchParams.get('search')||'';await route.fulfill({json:paginated([...clinicLinks.map(c=>({...c,is_linked:c.id===1})),hidden].filter(c=>(c.code+c.name_ar).includes(search)))});return true;}
    return false;
  }});
  try{
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');
    await dialog.getByLabel('التوصيف المهني',{exact:true}).fill('مسودتي الخاصة');
    await dialog.getByRole('checkbox',{name:/العيادة الداخلية/}).uncheck();await dialog.getByRole('checkbox',{name:/العيادة القلبية/}).check();
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    const review=dialog.getByRole('region',{name:'مراجعة تعارض التعديل'});await review.waitFor();
    assert.match(await review.innerText(),/اسم عدله مستخدم آخر/);assert.match(await review.innerText(),/عيادة لم تظهر في البحث/);
    assert.equal(writes,1);await review.getByRole('checkbox',{name:'تطبيق مسودتي: التوصيف المهني',exact:true}).check();
    await review.getByRole('checkbox',{name:'تطبيق اختياري للعيادة: العيادة القلبية',exact:true}).check();
    await review.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();
    assert.equal(await dialog.getByLabel('الاسم الكامل *').inputValue(),'اسم عدله مستخدم آخر');assert.equal(await dialog.getByLabel('الهاتف',{exact:true}).inputValue(),'009639999');
    assert.equal(await dialog.getByLabel('التوصيف المهني',{exact:true}).inputValue(),'مسودتي الخاصة');assert.equal(writes,1);
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.waitFor({state:'detached'});
    const saved=calls.filter(c=>c.method==='PUT')[1].body;assert.deepEqual(saved.clinic_add_ids,[3]);assert.deepEqual(saved.clinic_remove_ids,[]);assert.equal(saved.lock_version,2);assert.equal(saved.name,'اسم عدله مستخدم آخر');
    await page.getByRole('button',{name:'تعديل اسم عدله مستخدم آخر',exact:true}).click();assert.equal(await page.getByRole('dialog').getByLabel('التوصيف المهني',{exact:true}).inputValue(),'مسودتي الخاصة');
  }finally{await context.close();}
});

test('failed conflict reload and second conflict preserve draft and can retry without saving automatically',async()=>{
  let writes=0,reloads=0;let current=structuredClone(doctors[0]);
  const {page,context}=await setup({override:async(route,url)=>{
    if(route.request().method()==='PUT'){writes++;current={...current,lock_version:current.lock_version+1};await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});return true;}
    if(url.pathname==='/hospital-api/doctors/1'&&writes){reloads++;if(reloads===1)await route.abort();else await route.fulfill({json:{data:current}});return true;}return false;
  }});
  try{
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByLabel('التوصيف المهني',{exact:true}).fill('مسودة محفوظة');await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();
    await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();await dialog.getByText(/تعذّر الاتصال بالخادم/).waitFor();assert.equal(await dialog.getByLabel('التوصيف المهني',{exact:true}).inputValue(),'مسودة محفوظة');
    await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();await dialog.getByRole('checkbox',{name:'تطبيق مسودتي: التوصيف المهني',exact:true}).check();await dialog.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();
    await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).waitFor();assert.equal(writes,2);assert.equal(await dialog.getByLabel('التوصيف المهني',{exact:true}).inputValue(),'مسودة محفوظة');
  }finally{await context.close();}
});

test('conflict review recovers a renamed clinic by stable id and shows its current identity',async()=>{
  let writes=0,failBatch=true;
  const renamed={...clinicLinks[2],code:'RENAMED-03',name_ar:'الاسم الحالي للعيادة',is_linked:false};
  const {page,context,calls}=await setup({override:async(route,url)=>{
    if(route.request().method()==='PUT'){writes++;await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});return true;}
    if(url.pathname.endsWith('/options/clinics')&&writes){
      const ids=url.searchParams.getAll('ids[]').map(Number);
      if(ids.length&&failBatch){failBatch=false;await route.abort();return true;}
      const rows=ids.includes(renamed.id)?[renamed]:clinicLinks.filter(c=>c.id!==renamed.id&&(c.code+c.name_ar).includes(url.searchParams.get('search')||''));
      await route.fulfill({json:{...paginated(rows),unavailable:[]}});return true;
    }return false;
  }});
  try{
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');
    await dialog.getByRole('checkbox',{name:/العيادة القلبية/}).check();
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    await dialog.getByText(/تعذّر الاتصال بالخادم/).waitFor();
    assert.equal(await dialog.getByRole('checkbox',{name:/العيادة القلبية/}).isChecked(),true);
    await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    const review=dialog.getByRole('region',{name:'مراجعة تعارض التعديل'});await review.waitFor();
    assert.match(await review.innerText(),/RENAMED-03/);
    await review.getByRole('checkbox',{name:'تطبيق اختياري للعيادة: الاسم الحالي للعيادة',exact:true}).check();
    await review.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();assert.equal(writes,1);
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();
    await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).waitFor();
    assert.deepEqual(calls.filter(c=>c.method==='PUT')[1].body.clinic_add_ids,[renamed.id]);
  }finally{await context.close();}
});

test('400 explicit link changes recover in four ID batches with a final version check and no automatic save',async()=>{
  let writes=0;
  const rows=Array.from({length:400},(_,i)=>({...clinicLinks[0],id:i+1,code:`C${i+1}`,name_ar:`عيادة اختبار ${i+1}`,is_linked:i<200}));
  const {page,context,calls}=await setup({settings:{...options,capabilities:{...options.capabilities,update:false}},override:async(route,url)=>{
    if(route.request().method()==='PUT'){writes++;await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});return true;}
    if(url.pathname.endsWith('/options/clinics')||url.pathname==='/hospital-api/doctors/1/clinics'){
      const ids=url.searchParams.getAll('ids[]').map(Number);
      if(ids.length){await route.fulfill({json:{...paginated(rows.filter(r=>ids.includes(r.id))),unavailable:[]}});return true;}
      const source=url.pathname.endsWith('/1/clinics')?rows.filter(r=>r.is_linked):rows;
      const currentPage=Number(url.searchParams.get('page')||1);
      await route.fulfill({json:{data:source.slice((currentPage-1)*100,currentPage*100),meta:{page:currentPage,per_page:100,total:source.length,last_page:Math.ceil(source.length/100)}}});return true;
    }return false;
  }});
  try{
    await page.getByRole('button',{name:'إدارة عيادات أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');
    const picker=dialog.getByRole('group',{name:'عيادات المنشأة',exact:true});
    for(let p=1;p<=4;p++){
      await picker.getByText(`صفحة ${p} من 4`,{exact:true}).waitFor();
      await picker.locator('input[type=checkbox]').evaluateAll(inputs=>inputs.forEach(input=>input.click()));
      if(p<4)await picker.getByRole('button',{name:'التالي',exact:true}).click();
    }
    await dialog.getByRole('button',{name:'حفظ الارتباطات',exact:true}).click();
    const first=calls.find(c=>c.method==='PUT').body;assert.equal(first.clinic_add_ids.length,200);assert.equal(first.clinic_remove_ids.length,200);
    const start=calls.length;await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();
    const review=dialog.getByRole('region',{name:'مراجعة تعارض التعديل'});await review.waitFor();
    const lookup=calls.slice(start).filter(c=>c.url.pathname.endsWith('/options/clinics'));
    assert.equal(lookup.length,4);assert.ok(lookup.every(c=>c.url.searchParams.getAll('ids[]').length===100&&!c.url.searchParams.has('search')));
    assert.equal(new Set(lookup.flatMap(c=>c.url.searchParams.getAll('ids[]'))).size,400);
    assert.equal(calls.at(-1).url.pathname,'/hospital-api/doctors/1');
    assert.equal(await review.getByRole('checkbox').count(),400);assert.equal(writes,1);
    await review.getByRole('checkbox',{name:'تطبيق اختياري للعيادة: عيادة اختبار 201',exact:true}).check();
    await review.getByRole('button',{name:'اعتماد الاختيارات للمراجعة',exact:true}).click();assert.equal(writes,1);
    await dialog.getByRole('button',{name:'حفظ الارتباطات',exact:true}).click();await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).waitFor();
    const second=calls.filter(c=>c.method==='PUT')[1].body;assert.deepEqual(second.clinic_add_ids,[201]);assert.deepEqual(second.clinic_remove_ids,[]);
  }finally{await context.close();}
});

for(const destination of ['close','facility','session'])test(`pending ID batch is cancelled on ${destination} without updating another context`,async()=>{
  let release,started;const gate=new Promise(r=>{release=r;});const requested=new Promise(r=>{started=r;});let writes=0;
  const access=[1,2].map(id=>({facility:{...facility,id},permissions,roles:[]}));
  const {page,context,calls}=await setup({access,override:async(route,url)=>{
    if(route.request().method()==='PUT'){writes++;await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});return true;}
    if(url.pathname.endsWith('/options/clinics')&&url.searchParams.has('ids[]')){started();await gate;try{await route.fulfill({json:{...paginated([{...clinicLinks[2],name_ar:'نتيجة متأخرة'}]),unavailable:[]}});}catch{}return true;}return false;
  }});
  try{
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByRole('checkbox',{name:/العيادة القلبية/}).check();
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();await requested;
    if(destination==='facility')await page.evaluate(()=>history.pushState(null,'','/doctors?facility_id=2'));
    else if(destination==='session')await page.goto(base+'/login');
    else await page.keyboard.press('Escape');
    await dialog.waitFor({state:'detached'});const count=calls.filter(c=>c.url.pathname==='/hospital-api/doctors/1').length;
    release();await page.waitForTimeout(300);assert.equal(await page.getByText('نتيجة متأخرة',{exact:true}).count(),0);assert.equal(writes,1);
    assert.equal(calls.filter(c=>c.url.pathname==='/hospital-api/doctors/1').length,count);
  }finally{release();await context.close();}
});

test('real history entries restore search filters pagination and cancel obsolete debounce, detail return preserves context',async()=>{
  const first='/doctors?facility_id=1&search=أحمد&status=active&page=2';const second='/doctors?facility_id=1&search=ليلى&status=inactive&page=3';
  const {page,context,calls}=await setup({path:first});
  try{
    const input=page.getByLabel('البحث في الأطباء');assert.equal(await input.inputValue(),'أحمد');
    await page.evaluate(url=>window.history.pushState(null,'',url),second);await page.getByRole('combobox',{name:'الحالة',exact:true}).waitFor();
    await page.waitForFunction(()=>document.querySelector('input[type=search]')?.value==='ليلى');
    await input.fill('قيمة قديمة');await page.goBack();await page.waitForTimeout(450);
    assert.equal(await input.inputValue(),'أحمد');assert.equal(new URL(page.url()).searchParams.get('page'),'2');assert.equal(await page.getByRole('combobox',{name:'الحالة',exact:true}).inputValue(),'active');
    assert.ok(!calls.some(c=>c.url.searchParams.get('search')==='قيمة قديمة'));
    await page.goForward();await page.waitForTimeout(400);assert.equal(await input.inputValue(),'ليلى');assert.equal(new URL(page.url()).searchParams.get('page'),'3');
    await page.getByRole('link',{name:'D001',exact:true}).click();await page.getByRole('heading',{name:'أحمد الاختباري',exact:true}).waitFor();
    await page.getByRole('link',{name:'العودة إلى قائمة الأطباء',exact:true}).click();assert.equal(await input.inputValue(),'ليلى');
    await input.fill('مرئي');await page.getByRole('combobox',{name:'الحالة',exact:true}).selectOption('active');await page.waitForTimeout(400);assert.equal(new URL(page.url()).searchParams.get('search'),'مرئي');assert.equal(new URL(page.url()).searchParams.has('page'),false);
  }finally{await context.close();}
});

test('facility switch aborts a pending conflict reload and closes the old draft',async()=>{
  let release;const gate=new Promise(resolve=>{release=resolve;});
  const access=[1,2].map(id=>({facility:{...facility,id,name_ar:`منشأة ${id}`},permissions,roles:[]}));
  const {page,context}=await setup({access,override:async(route,url)=>{
    if(route.request().method()==='PUT'){await route.fulfill({status:409,json:{error:{code:'DOCTOR_VERSION_CONFLICT'}}});return true;}
    if(url.pathname==='/hospital-api/doctors/1'&&url.searchParams.get('facility_id')==='1'){await gate;try{await route.fulfill({json:{data:{...doctors[0],name:'استجابة قديمة',lock_version:2}}});}catch{}return true;}return false;
  }});
  try{
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();let dialog=page.getByRole('dialog');await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.getByRole('button',{name:'جلب أحدث نسخة',exact:true}).click();await page.keyboard.press('Escape');
    await page.getByRole('combobox',{name:'المنشأة',exact:true}).selectOption('2');release();await page.waitForTimeout(400);
    assert.equal(await page.getByRole('dialog').count(),0);assert.equal(await page.getByText('استجابة قديمة',{exact:true}).count(),0);
    await page.getByRole('button',{name:'تعديل أحمد الاختباري',exact:true}).click();dialog=page.getByRole('dialog');assert.equal(await dialog.getByLabel('الاسم الكامل *').inputValue(),'أحمد الاختباري');
  }finally{release();await context.close();}
});

test('export uses displayed filters and columns; referenced deletion remains a separate global deactivate action',async()=>{
  const {page,context,calls}=await setup({override:async(route,url)=>{
    if(url.pathname.includes('/export/')){await route.fulfill({status:422,json:{error:{code:'EXPORT_LIMIT_EXCEEDED'}}});return true;}
    if(route.request().method()==='DELETE'){await route.fulfill({status:409,json:{error:{code:'DOCTOR_REFERENCED'}}});return true;}return false;
  }});
  try{
    await page.getByLabel('البحث في الأطباء').fill('أحمد');assert.equal(await page.getByRole('button',{name:'Excel',exact:true}).isDisabled(),true);await page.waitForURL(/search=/);
    await page.getByText('الأعمدة',{exact:true}).click();await page.getByRole('checkbox',{name:'التوصيف المهني',exact:true}).uncheck();await page.getByText('الأعمدة',{exact:true}).click();await page.getByRole('button',{name:'Excel',exact:true}).click();await page.locator('main').getByRole('alert').waitFor();
    const report=calls.find(c=>c.url.pathname.includes('/export/'));assert.equal(report.url.searchParams.get('search'),'أحمد');assert.ok(!report.url.searchParams.getAll('columns[]').includes('description'));
    await page.getByRole('button',{name:'حذف أحمد الاختباري',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByRole('button',{name:'حذف نهائي',exact:true}).click();await dialog.getByRole('alert').waitFor();assert.match(await dialog.innerText(),/التعطيل العالمي إجراء منفصل/);assert.equal(calls.filter(c=>c.url.pathname.endsWith('/deactivate')).length,0);
  }finally{await context.close();}
});

for(const width of [390,768,1440])test(`doctor list/editor/links/detail responsive and keyboard accessible at ${width}px`,async()=>{
  const {page,context,errors}=await setup({width});
  try{
    await page.getByRole('link',{name:'D001',exact:true}).waitFor();await page.evaluate(()=>document.fonts.ready);await mkdir('.superdesign/directory-review/doctors',{recursive:true});
    assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
    await page.screenshot({path:`.superdesign/directory-review/doctors/list-${width}.png`,fullPage:true});
    await page.getByRole('button',{name:'إضافة طبيب جديد',exact:true}).click();const dialog=page.getByRole('dialog');await dialog.getByRole('checkbox',{name:/العيادة الداخلية/}).waitFor();
    await page.screenshot({path:`.superdesign/directory-review/doctors/editor-${width}.png`,fullPage:false});
    const save=dialog.getByRole('button',{name:'حفظ الطبيب',exact:true});const box=await save.boundingBox();assert.ok(box.y>=0&&box.y+box.height<=900);
    const lastChoice=dialog.getByRole('checkbox').last();await lastChoice.scrollIntoViewIfNeeded();await lastChoice.focus();
    const headingBox=await dialog.getByRole('heading',{name:'إضافة طبيب جديد',exact:true}).boundingBox(),saveBox=await save.boundingBox(),choiceBox=await lastChoice.boundingBox();
    assert.ok(headingBox.y>=0&&choiceBox.y>=headingBox.y+headingBox.height&&choiceBox.y+choiceBox.height<=saveBox.y);
    await page.screenshot({path:`.superdesign/directory-review/doctors/editor-bottom-${width}.png`,fullPage:false});
    await dialog.getByRole('button',{name:'إلغاء',exact:true}).focus();await page.keyboard.press('Tab');assert.ok(await dialog.evaluate(el=>el.contains(document.activeElement)));await page.keyboard.press('Escape');
    await page.getByRole('button',{name:'عيادات أحمد الاختباري: 2',exact:true}).click();await page.getByRole('dialog').getByText('2 عيادة مطابقة',{exact:true}).waitFor();await page.screenshot({path:`.superdesign/directory-review/doctors/clinics-${width}.png`,fullPage:false});await page.keyboard.press('Escape');
    await page.getByRole('link',{name:'D001',exact:true}).click();await page.getByRole('heading',{name:'أحمد الاختباري',exact:true}).waitFor();await page.getByText('2 عيادة مطابقة',{exact:true}).waitFor();await page.screenshot({path:`.superdesign/directory-review/doctors/detail-${width}.png`,fullPage:true});assert.deepEqual(errors,[]);
  }finally{await context.close();}
});
