// Real Next standalone -> Laravel -> guarded MariaDB. No fabricated API responses.
import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { mkdir } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import { base, output, fixture, fixtureData as f, api, payload, pageAt, eventFields } from './blood-events-live-support.mjs';
let browser, donor, issue, transfusion, component;
before(async () => { fixture('prepare-unified'); browser = await chromium.launch(); component = (await api('/options')).data.blood_components[0].id; await mkdir(output, { recursive: true }); });
after(async () => { await browser?.close(); if (f) { try { fixture('verify-unified'); } finally { fixture('cleanup'); } } });

test('new person and donation are saved together; decimal kg has no default; personal and nested drafts survive', async () => {
  const { page, context, errors } = await pageAt(browser);
  try {
    await page.getByRole('button', { name: 'تسجيل تبرع', exact: true }).click();
    await page.getByRole('combobox', { name: 'الشخص', exact: true }).selectOption('new');
    assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '');
    await page.getByLabel('الاسم الأول', { exact: true }).fill('اختبار بصري'); await page.getByLabel('اسم العائلة', { exact: true }).fill('شخص موحد');
    await page.getByRole('combobox', { name: 'مصدر بيانات الشخص' }).selectOption('patient');
    await page.getByRole('combobox', { name: 'مصدر بيانات الشخص' }).selectOption('direct');
    assert.equal(await page.getByLabel('الاسم الأول', { exact: true }).inputValue(), 'اختبار بصري');
    await page.getByRole('radio', { name: 'محافظة خارج سوريا', exact: true }).check();
    await page.getByLabel('اسم المحافظة خارج سوريا', { exact: true }).fill('محافظة اختبار'); await page.getByLabel('اسم المدينة', { exact: true }).fill('مدينة اختبار');
    await page.getByLabel('عنوان السكن', { exact: true }).fill('عنوان سكن اصطناعي');
    await eventFields(page, component);
    await page.getByRole('button', { name: 'إضافة فحص', exact: true }).click(); await page.getByLabel('نوع الفحص الجديد').selectOption('HCV'); await page.getByLabel('حالة HCV', { exact: true }).selectOption('complete');
    await page.getByRole('button', { name: 'إضافة طبيب', exact: true }).click();
    await page.getByRole('dialog', { name: 'إضافة طبيب جديد', exact: true }).getByRole('button', { name: 'إلغاء', exact: true }).click();
    assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.4500');
    for (const width of [390, 768, 1440]) { await page.setViewportSize({ width, height: 1000 }); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false); await page.screenshot({ path: `${output}/donation-form-${width}.png`, fullPage: true }); }
    const saved = page.waitForResponse(r => r.url().includes('/hospital-api/blood-bank/events') && r.request().method() === 'POST');
    await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); const response = await saved; assert.equal(response.status(), 201, await response.text()); donor = (await response.json()).data;
    await page.waitForURL(/\/blood-bank\/events\/\d+/); assert.equal(donor.quantity_unit, 'kg'); assert.equal(donor.screenings[0].result, null); assert.deepEqual(errors, []);
  } finally { await context.close(); }
});

test('same person can donate repeatedly, receive an issue, then its linked actual transfusion without double benefit count', async () => {
  assert.ok(donor);
  await api('/events', 'POST', payload('donation', donor.person_id), 201);
  const { page, context } = await pageAt(browser, `/blood-bank/people/${donor.person_id}`);
  try {
    await page.getByRole('button', { name: 'تسجيل استفادة', exact: true }).click(); await page.getByRole('dialog').getByRole('combobox', { name: 'نوع الاستفادة' }).selectOption('issue'); await eventFields(page, component);
    await page.getByLabel('جهة المستفيد', { exact: true }).fill('جهة اختبار'); await page.getByLabel('عنوان الجهة', { exact: true }).fill('عنوان جهة مستقل');
    const saved = page.waitForResponse(r => r.url().includes('/hospital-api/blood-bank/events') && r.request().method() === 'POST'); await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); const response = await saved; assert.equal(response.status(), 201, await response.text()); issue = (await response.json()).data; assert.equal(issue.blood_transfusion_id, null);
    await page.goto(`${base}/blood-bank/people/${donor.person_id}`); await page.getByRole('button', { name: 'تسجيل استفادة', exact: true }).click();
    await page.getByRole('dialog').getByRole('combobox', { name: 'نوع الاستفادة' }).selectOption('transfusion'); await page.getByRole('combobox', { name: 'ارتباط عملية النقل' }).selectOption('linked');
    await page.getByRole('group', { name: 'واقعة الصرف السابقة', exact: true }).getByRole('button', { name: new RegExp(issue.code) }).click(); await eventFields(page, component);
    await page.screenshot({ path: `${output}/linked-transfusion-form.png`, fullPage: true });
    const completed = page.waitForResponse(r => r.url().includes('/hospital-api/blood-bank/events') && r.request().method() === 'POST'); await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); const complete = await completed; assert.equal(complete.status(), 201, await complete.text()); transfusion = (await complete.json()).data;
    assert.equal(transfusion.issue_event_id, issue.id); assert.ok(transfusion.blood_transfusion_id);
    const list = await api(`/events?person_id=${donor.person_id}`); assert.equal(list.meta.total, 4); assert.deepEqual(list.totals, { donations: 2, benefits: 1, unique_people: 1 });
  } finally { await context.close(); }
});

test('patient link is selected explicitly; failed relationship rolls back person and repeat UUID does not duplicate events', async () => {
  const p = { ...payload('benefit'), person: { person_mode: 'patient', patient_id: f.patients[2] } };
  const row = (await api('/events', 'POST', p, 201)).data; await api('/events', 'POST', p, 201);
  assert.equal((await api(`/events?person_id=${row.person_id}`)).meta.total, 1);
  await api('/events', 'POST', { ...p, quantity: '0.6' }, 409);
  const before = (await api('/people?search=يتيم')).meta.total;
  await api('/events', 'POST', { ...payload(), person: { ...payload().person, first_name: 'يتيم' }, responsible_staff_id: 999999 }, 422);
  assert.equal((await api('/people?search=يتيم')).meta.total, before);
  await api('/events', 'POST', payload('benefit'), 403, f.viewer_token);
  await api(`/events/${row.id}`, 'GET', { facility_id: f.other }, 403);
  await api('/events', 'GET', undefined, 401, '');
});

test('two editors preserve drafts, explicitly review latest version and correct aliases without overwriting another field', async () => {
  const { page, context } = await pageAt(browser, `/blood-bank/events/${donor.id}`);
  try {
    await page.getByRole('button', { name: 'تعديل الواقعة', exact: true }).click(); await page.getByLabel('الكمية (كغ)', { exact: true }).fill('0.6');
    const current = (await api(`/events/${donor.id}`)).data;
    const yesterday = new Date(`${f.today}T00:00:00Z`); yesterday.setUTCDate(yesterday.getUTCDate() - 1);
    const update = { ...payload('donation', donor.person_id), blood_component_id: component, lock_version: current.lock_version, occurred_on: yesterday.toISOString().slice(0, 10) };
    const changed = (await api(`/events/${donor.id}`, 'PUT', update)).data;
    await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).waitFor(); assert.equal(await page.getByLabel('الكمية (كغ)', { exact: true }).inputValue(), '0.6');
    await page.getByRole('button', { name: 'جلب أحدث نسخة', exact: true }).click(); await page.getByRole('checkbox', { name: 'تطبيق مسودتي: الكمية', exact: true }).check(); await page.getByRole('button', { name: 'اعتماد الاختيارات للمراجعة' }).click();
    assert.equal(await page.getByLabel('التاريخ الفعلي', { exact: true }).inputValue(), changed.occurred_on);
    await page.getByRole('button', { name: 'حفظ الواقعة', exact: true }).click(); await page.getByRole('dialog').waitFor({ state: 'hidden' });
    const saved = (await api(`/events/${donor.id}`)).data; assert.equal(saved.quantity, '0.6000'); assert.equal(saved.occurred_on, changed.occurred_on); assert.ok(saved.aliases.includes(donor.code));
    assert.equal((await api(`/events?search=${donor.code}`)).meta.total, 1);
  } finally { await context.close(); }
});

test('overlapping corrections have one winner and durable UUID returns the same event', async () => {
  const p = payload('benefit', donor.person_id); const one = (await api('/events', 'POST', p, 201)).data;
  const common = { ...payload('benefit', donor.person_id), lock_version: 1 };
  const all = await Promise.all([0.7,0.8].map(async quantity => { const r = await fetch(`${base}/hospital-api/blood-bank/events/${one.id}`, { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${f.token}` }, body: JSON.stringify({ ...common, facility_id: f.facility, request_id: randomUUID(), quantity: String(quantity) }) }); return r.status; }));
  assert.deepEqual(all.sort(), [200,409]); assert.equal((await api('/events', 'POST', p, 201)).data.id, one.id);
});

test('responsive person/event/ledger pages retain scoped links and deny unsupported rewrites', async () => {
  for (const width of [390,768,1440]) for (const [name,path] of [['ledger','/blood-bank'],['person',`/blood-bank/people/${donor.person_id}`],['event',`/blood-bank/events/${transfusion.id}`]]) {
    const { page,context,errors } = await pageAt(browser,path,width); try {
      await page.getByRole('button',{name:'PDF',exact:true}).waitFor(); await page.waitForFunction(() => ![...document.querySelectorAll('button')].find(b => b.textContent === 'PDF')?.disabled);
      assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),false); await page.screenshot({path:`${output}/${name}-${width}.png`,fullPage:true}); assert.deepEqual(errors,[]);
    } finally { await context.close(); }
  }
  for (const path of ['events/abc','people/abc','events/1/unknown']) { const r=await fetch(`${base}/hospital-api/blood-bank/${path}`); assert.equal(r.status,404); assert.equal(r.headers.get('x-test-laravel'),null); }
});

test('saved inline doctor survives option failure and relinks without a second creation; new person benefit stays atomic', async () => {
  const {page,context}=await pageAt(browser);let writes=0;
  page.on('request',r=>{if(new URL(r.url()).pathname==='/hospital-api/doctors'&&r.method()==='POST')writes++;});
  try{
    await page.evaluate(()=>{const original=window.fetch.bind(window);let fail=false;window.fetch=async(...args)=>{const url=new URL(String(args[0]),location.origin);if(fail&&url.pathname==='/hospital-api/blood-bank/doctors'){fail=false;throw new TypeError('Synthetic transport interruption');}const response=await original(...args);if(url.pathname==='/hospital-api/doctors'&&args[1]?.method==='POST'&&response.ok){window.savedTestDoctor=(await response.clone().json()).data;fail=true;}return response;};});
    await page.getByRole('button',{name:'تسجيل استفادة',exact:true}).click();await page.getByRole('combobox',{name:'الشخص',exact:true}).selectOption('new');
    await page.getByLabel('الاسم الأول',{exact:true}).fill('مسودة طبيب');await page.getByLabel('اسم العائلة',{exact:true}).fill('استفادة جديدة');
    await page.getByRole('dialog').getByRole('combobox',{name:'نوع الاستفادة'}).selectOption('issue');await eventFields(page,component);
    await page.getByRole('button',{name:'إضافة طبيب',exact:true}).click();const dialog=page.getByRole('dialog',{name:'إضافة طبيب جديد',exact:true});
    await dialog.getByRole('textbox',{name:'كود الطبيب',exact:false}).fill(`BBUI-${f.tag}`);await dialog.getByRole('textbox',{name:'الاسم الكامل',exact:false}).fill('طبيب بنك دم اصطناعي');await dialog.getByRole('combobox',{name:'نوع الطبيب',exact:false}).selectOption({index:1});const specs=dialog.getByRole('checkbox');if(await specs.count())await specs.first().check();
    await dialog.getByRole('button',{name:'حفظ الطبيب',exact:true}).click();await dialog.waitFor({state:'hidden'});const recovery=page.getByRole('dialog',{name:'استكمال الطبيب المحفوظ',exact:true});await recovery.getByRole('alert').waitFor();const saved=await page.evaluate(()=>window.savedTestDoctor);assert.ok(saved.id);
    async function doctorRequest(path,method='GET',body){const r=await fetch(`${base}/hospital-api/doctors/${saved.id}${path}?facility_id=${f.facility}`,{method,headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${f.token}`},body:body?JSON.stringify({facility_id:f.facility,...body}):undefined});const data=await r.json();assert.equal(r.status,200,JSON.stringify(data.error));return data.data;}
    const current=await doctorRequest('');await doctorRequest('/clinics','PUT',{lock_version:current.lock_version,clinic_add_ids:[],clinic_remove_ids:[f.clinic]});
    await recovery.getByRole('button',{name:'تحديث خيارات الطبيب',exact:true}).click();await recovery.getByText(/ارتباطه بهذه العيادة غير متاح/).waitFor();await recovery.getByRole('button',{name:'استكمال الربط بالعيادة',exact:true}).click();await recovery.waitFor({state:'hidden'});
    assert.equal(await page.getByLabel('الاسم الأول',{exact:true}).inputValue(),'مسودة طبيب');assert.equal(await page.getByLabel('الكمية (كغ)',{exact:true}).inputValue(),'0.4500');assert.equal(writes,1);
    const response=page.waitForResponse(r=>r.url().includes('/hospital-api/blood-bank/events')&&r.request().method()==='POST');await page.getByRole('button',{name:'حفظ الواقعة',exact:true}).click();const savedEvent=await response;assert.equal(savedEvent.status(),201,await savedEvent.text());assert.equal((await savedEvent.json()).data.responsible_staff_id,saved.id);
  }finally{await context.close();}
});

test('actual patient picker retains both drafts and sends only linked identity with an independent actual transfusion',async()=>{
  const {page,context}=await pageAt(browser);try{
    await page.getByRole('button',{name:'تسجيل استفادة',exact:true}).click();await page.getByRole('combobox',{name:'الشخص',exact:true}).selectOption('new');await page.getByLabel('الاسم الأول',{exact:true}).fill('مسودة شخصية');
    const mode=page.getByRole('combobox',{name:'مصدر بيانات الشخص'});await mode.selectOption('patient');await page.getByRole('searchbox',{name:'البحث: المريض المسجل',exact:true}).fill(`${f.tag}-P2`);await page.getByRole('button',{name:/مستفيد اختبار 2/}).click();await page.getByText('عرض بيانات المريض الحالية',{exact:true}).click();await page.getByRole('definition').filter({hasText:/عنوان المريض المرجعي/}).waitFor();await mode.selectOption('direct');assert.equal(await page.getByLabel('الاسم الأول',{exact:true}).inputValue(),'مسودة شخصية');await mode.selectOption('patient');
    await page.getByRole('dialog').getByRole('combobox',{name:'نوع الاستفادة'}).selectOption('transfusion');await page.getByRole('combobox',{name:'ارتباط عملية النقل'}).selectOption('independent');await eventFields(page,component);
    const response=page.waitForResponse(r=>r.url().includes('/hospital-api/blood-bank/events')&&r.request().method()==='POST');await page.getByRole('button',{name:'حفظ الواقعة',exact:true}).click();const saved=await response;assert.equal(saved.status(),201,await saved.text());const body=saved.request().postDataJSON();assert.equal(body.person.patient_id,f.patients[2]);assert.equal(body.person.first_name,undefined);assert.ok((await saved.json()).data.blood_transfusion_id);
  }finally{await context.close();}
});

test('late real list delivery cannot repopulate a different facility',async()=>{
  const{page,context}=await pageAt(browser);try{
    await page.getByRole('region',{name:'جدول وقائع بنك الدم',exact:true}).waitFor();await page.evaluate(()=>{const original=window.fetch.bind(window);window.fetch=async(...args)=>{const response=await original(...args);const u=new URL(String(args[0]),location.origin);if(u.pathname==='/hospital-api/blood-bank/events'&&u.searchParams.get('search')==='تأخير')await new Promise(r=>setTimeout(r,1600));return response;};});
    await page.getByRole('searchbox',{name:'البحث في بنك الدم',exact:true}).fill('تأخير');await page.getByRole('status').filter({hasText:'جارٍ تحديث النتائج'}).waitFor();await page.evaluate(id=>history.pushState(null,'',`/blood-bank?facility_id=${id}`),f.second);await page.getByText('لا توجد وقائع مطابقة. الملفات القديمة دون واقعة لا تدخل في هذا السجل.',{exact:true}).waitFor();await page.waitForTimeout(1700);assert.equal(await page.getByRole('link',{name:donor.code,exact:true}).count(),0);assert.equal(await page.getByRole('dialog').count(),0);
  }finally{await context.close();}
});

test('independent PHP connections overlap inside the real unified writer and cannot overwrite one version',async()=>{
  const {spawn}=await import('node:child_process');const {writeFile,unlink}=await import('node:fs/promises');const {fileURLToPath}=await import('node:url');
  const e=(await api('/events','POST',payload('benefit'),201)).data;
  const backend=fileURLToPath(new URL('../../backend/',import.meta.url));const prefix=fileURLToPath(new URL('../../backend/storage/framework/testing/',import.meta.url));
  const files=[`${prefix}event-first-${randomUUID()}.json`,`${prefix}event-second-${randomUUID()}.json`];const processes=[];
  function worker(file){const child=spawn('php',['tests/Support/blood-event-correction-worker.php',file],{cwd:backend,env:{...process.env,APP_ENV:'testing'}});processes.push(child);let output='',error='';let unlock;const locked=new Promise(resolve=>unlock=resolve);child.stdout.on('data',b=>{output+=b;if(output.includes('LOCKED'))unlock();});child.stderr.on('data',b=>error+=b);const done=new Promise((resolve,reject)=>{child.on('error',reject);child.on('close',code=>code===0?resolve(output):reject(new Error(error||output)));});return{done,locked};}
  try{
    for(let i=0;i<2;i++)await writeFile(files[i],JSON.stringify({user_id:f.user_id,event:e.id,pause:i===0,payload:{...payload('benefit',e.person_id),facility_id:f.facility,lock_version:1,quantity:i===0?'0.55':'0.65'}}));
    const first=worker(files[0]);let timer;await Promise.race([first.locked,new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Writer did not acquire its actual lock')),30000);})]).finally(()=>clearTimeout(timer));
    const second=worker(files[1]);const values=await Promise.all([first.done,second.done]);assert.match(values[0],/SAVED/);assert.match(values[1],/CONFLICT/);const saved=(await api(`/events/${e.id}`)).data;assert.equal(saved.lock_version,2);assert.equal(saved.quantity,'0.5500');
  }finally{for(const p of processes)if(p.exitCode===null)p.kill();for(const file of files)await unlink(file).catch(()=>{});}
});
