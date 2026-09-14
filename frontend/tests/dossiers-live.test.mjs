import assert from 'node:assert/strict';
import { before, after, test } from 'node:test';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { mkdir, writeFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
const base='http://127.0.0.1:3194'; let browser,f;
const gallery=new URL('../docs/reviews/dossiers-phase-one/',import.meta.url);
function fixture(mode) { const r=spawnSync('php',['tests/Support/dossier-live.php',mode],{cwd:fileURLToPath(new URL('../../backend/',import.meta.url)),env:{...process.env,APP_ENV:'testing'},encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);process.stdout.write(r.stdout); }
before(async()=>{fixture('prepare');f=JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/dossier-live.json',import.meta.url)));browser=await chromium.launch();await mkdir(gallery,{recursive:true});});
after(async()=>{await browser?.close();try{fixture('verify');}finally{fixture('cleanup');}});
async function pageAt(path='/dossiers',width=1440,token=f.token){const context=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});await context.addInitScript(t=>sessionStorage.setItem('hospital.bearer',t),token);const page=await context.newPage();page.setDefaultTimeout(20000);await page.goto(`${base}${path}`);return{page,context};}
async function capture(page,name){await page.evaluate(async()=>{await document.fonts.ready;window.scrollTo(0,0);await new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r)));});assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);await page.screenshot({path:fileURLToPath(new URL(name+'.jpg',gallery)),type:'jpeg',quality:78,fullPage:true});}

test('real rewrites enforce read-only scope, status codes and shapes',async()=>{
  for(const suffix of ['',`/${f.dossiers[0]}`,`/${f.dossiers[0]}/visits`,`/${f.dossiers[0]}/visits/${f.latest_visit}`]){
    const r=await fetch(`${base}/hospital-api/dossiers${suffix}?facility_id=${f.facility}`,{headers:{Authorization:`Bearer ${f.token}`,Accept:'application/json'}});assert.equal(r.status,200);assert.equal(r.headers.get('x-test-laravel'),'dossiers');assert.match(r.headers.get('cache-control'),/no-store/);const d=await r.json();assert.ok(d.data);
    for(const [token,facility,status] of [['',f.facility,401],[f.denied_token,f.facility,403],[f.token,f.other,403]]) {const denied=await fetch(`${base}/hospital-api/dossiers${suffix}?facility_id=${facility}`,{headers:{Authorization:token?`Bearer ${token}`:'',Accept:'application/json'}});assert.equal(denied.status,status);assert.ok((await denied.json()).error);}
  }
  for(const path of ['/abc','/1/export/pdf','/1/uploads']){const r=await fetch(`${base}/hospital-api/dossiers${path}`);assert.equal(r.status,404);assert.equal(r.headers.get('x-test-laravel'),null);}
});

test('list reuses server pagination and add cannot write; complete visual states at three widths',async()=>{
  for(const width of [390,768,1440]){const{page,context}=await pageAt(`/dossiers?facility_id=${f.facility}&per_page=10&sort=code&direction=asc`,width);try{
    await page.getByRole('region',{name:'جدول الإضبارات',exact:true}).waitFor();assert.equal(await page.locator('tbody tr').count(),10);
    if(width===390) await page.getByRole('button',{name:'فتح قائمة التنقل',exact:true}).click();
    await page.getByRole('link',{name:'الإضبارات',exact:true}).waitFor();
    if(width===390) await page.keyboard.press('Escape');
    const writes=[];page.on('request',r=>{if(['POST','PUT','PATCH','DELETE'].includes(r.method())&&r.url().includes('/hospital-api/'))writes.push(r.url());});
    const add=page.getByRole('button',{name:'إضافة إضبارة',exact:true});assert.equal(await add.isDisabled(),true);await page.getByText('ستتاح إضافة الإضبارة في المرحلة التالية.',{exact:true}).waitFor();await add.evaluate(el=>el.click());assert.equal(writes.length,0);assert.equal(await page.getByRole('dialog').count(),0);
    await capture(page,`list-${width}`);
    await page.getByRole('region',{name:'جدول الإضبارات',exact:true}).evaluate(el=>el.scrollLeft=-el.scrollWidth);
    await capture(page,`list-actions-${width}`);
    await page.getByRole('button',{name:'التالي',exact:true}).click();await page.getByText('صفحة 2 من 2',{exact:true}).waitFor();assert.equal(await page.locator('tbody tr').count(),1);assert.equal(await page.locator('tbody tr td').first().innerText(),'11');
    await page.getByRole('searchbox',{name:'البحث في الإضبارات',exact:true}).fill('  أحمد    محمد  ');await page.getByText('1 إضبارة مطابقة',{exact:true}).waitFor();await page.getByRole('link',{name:`استعراض DOS-${f.tag}-001`,exact:true}).click();await page.getByRole('heading',{name:'الملف الورمي الحالي',exact:true}).waitFor();await page.getByRole('region',{name:'تشخيصات الزيارة',exact:true}).waitFor();await capture(page,`oncology-detail-${width}`);
    await page.getByRole('region',{name:'زيارات الإضبارة',exact:true}).waitFor();
    await capture(page,`oncology-detail-${width}`);
    assert.equal(await page.getByRole('button',{name:/تعديل|إضافة زيارة/}).count(),0);
    await page.getByRole('button',{name:'استعراض الزيارة',exact:true}).last().click();await page.getByRole('heading',{name:'الزيارة المختارة',exact:true}).waitFor();await page.getByText('لا توجد تشخيصات مسجلة.',{exact:true}).waitFor();await capture(page,`previous-visit-${width}`);
    await page.getByRole('link',{name:'العودة إلى الإضبارات',exact:true}).click();assert.equal(await page.getByRole('searchbox').inputValue(),'  أحمد    محمد  ');
    await page.getByRole('searchbox').fill('لا توجد مطابقة');await page.getByText('0 إضبارة مطابقة',{exact:true}).waitFor();await capture(page,`empty-${width}`);
    await page.goto(`${base}/dossiers/${f.dossiers[2]}?facility_id=${f.facility}`);await page.getByText('لا توجد زيارات فعلية مرتبطة بهذه الإضبارة.',{exact:true}).waitFor();await capture(page,`no-visits-${width}`);
  }finally{await context.close();}}
});

test('draft dossier filters and visit badges work through real saved records without writes',async()=>{
  for(const width of [390,768,1440]) {
    const {page,context}=await pageAt(`/dossiers?facility_id=${f.facility}&sort=code&direction=asc`,width);
    try {
      await page.getByText('11 إضبارة مطابقة',{exact:true}).waitFor();
      assert.equal(await page.getByRole("combobox",{name:'حالة الإضبارة',exact:true}).inputValue(),'all');
      const row=page.getByRole('row').filter({has:page.getByText(`DOS-${f.tag}-002`,{exact:true})});
      assert.equal(await row.getByLabel('حالة الإضبارة: مسودة',{exact:true}).innerText(),'مسودة');
      assert.equal(await row.getByLabel('حالة الزيارة: مسودة',{exact:true}).innerText(),'مسودة');
      await page.getByRole("combobox",{name:'حالة الإضبارة',exact:true}).selectOption('draft');
      await page.getByText('1 إضبارة مطابقة',{exact:true}).waitFor();
      assert.equal(await page.locator('tbody tr').count(),1);
      await page.getByRole('link',{name:`استعراض DOS-${f.tag}-002`,exact:true}).click();
      await page.getByLabel('حالة الإضبارة: مسودة',{exact:true}).waitFor();
      await page.getByText('لم تُسجّل تشخيصات لهذه المسودة بعد.',{exact:true}).waitFor();
      assert.equal(await page.getByText('لم تُسجّل بيانات هذا القسم بعد.',{exact:true}).count(),5);
      await page.getByRole('region',{name:'زيارات الإضبارة',exact:true}).waitFor();
      assert.equal(await page.getByLabel('حالة الزيارة: مسودة',{exact:true}).count(),2);
      assert.equal(await page.getByRole('button',{name:/استكمال|تعديل|إضافة زيارة/}).count(),0);
      await capture(page,`draft-detail-${width}`);
      await page.getByRole('link',{name:'العودة إلى الإضبارات',exact:true}).click();
      await page.getByText('1 إضبارة مطابقة',{exact:true}).waitFor();
      assert.equal(await page.getByRole("combobox",{name:'حالة الإضبارة',exact:true}).inputValue(),'draft');
      await page.getByRole("combobox",{name:'حالة الإضبارة',exact:true}).selectOption('active');
      await page.getByText('10 إضبارة مطابقة',{exact:true}).waitFor();
      assert.equal(await page.getByLabel('حالة الإضبارة: مسودة',{exact:true}).count(),0);
      await page.getByRole('link',{name:`استعراض DOS-${f.tag}-001`,exact:true}).click();
      await page.getByRole('heading',{name:'الملف الورمي الحالي',exact:true}).waitFor();
      await page.getByLabel('حالة الإضبارة: فعالة',{exact:true}).waitFor();
      await page.getByRole('region',{name:'تشخيصات الزيارة',exact:true}).waitFor();
      await page.getByRole('region',{name:'زيارات الإضبارة',exact:true}).waitFor();
      assert.equal(await page.getByLabel('حالة الزيارة: مكتملة',{exact:true}).count(),2);
      await page.getByRole('button',{name:'استعراض الزيارة',exact:true}).last().click();
      await page.getByRole('heading',{name:'الزيارة المختارة',exact:true}).waitFor();
      await page.getByText('لا توجد تشخيصات مسجلة.',{exact:true}).waitFor();
      assert.equal(await page.getByLabel('حالة الزيارة: مكتملة',{exact:true}).count(),3);
    } finally { await context.close(); }
  }
});

test('delayed actual replies, failed transport, browser history and facility changes cannot restore stale results',async()=>{
  for(const width of [390,768,1440]){const{page,context}=await pageAt(`/dossiers?facility_id=${f.facility}`,width);try{
    await page.getByText('11 إضبارة مطابقة',{exact:true}).waitFor();
    // Every request reaches real Laravel/MySQL. Only delivery is delayed/failed.
    await page.evaluate(()=>{const original=window.fetch.bind(window);window.fetch=async(...args)=>{const u=new URL(String(args[0]),location.origin);const response=await original(...args);if(u.pathname==='/hospital-api/dossiers'&&u.searchParams.get('search')==='أحمد')await new Promise(r=>setTimeout(r,1400));if(u.searchParams.get('search')==='فشل')throw new TypeError('Synthetic transport failure after actual server response');return response;};});
    const search=page.getByRole('searchbox');await search.fill('أحمد');await page.getByText('جارٍ تحديث النتائج؛ تظهر النتائج السابقة مؤقتًا.',{exact:true}).waitFor();await capture(page,`loading-${width}`);
    await search.fill('مفقود');await page.getByText('0 إضبارة مطابقة',{exact:true}).waitFor();await page.waitForTimeout(1500);assert.equal(await page.locator('tbody tr').count(),0);assert.match(await page.locator('main').innerText(),/لا توجد إضبارات/);
    await search.fill('فشل');await page.getByRole('button',{name:'إعادة المحاولة',exact:true}).waitFor();await capture(page,`error-${width}`);
    await page.evaluate(id=>history.pushState(null,'',`/dossiers?facility_id=${id}&search=أحمد&oncology=yes`),f.facility);await page.getByText('1 إضبارة مطابقة',{exact:true}).waitFor();
    await page.evaluate(id=>history.pushState(null,'',`/dossiers?facility_id=${id}&search=مفقود&visits=without`),f.facility);await page.getByText('0 إضبارة مطابقة',{exact:true}).waitFor();await search.fill('قديم معلق');await page.goBack();await page.getByText('1 إضبارة مطابقة',{exact:true}).waitFor();assert.equal(await search.inputValue(),'أحمد');await page.goForward();await page.getByText('0 إضبارة مطابقة',{exact:true}).waitFor();assert.equal(await search.inputValue(),'مفقود');
    await search.fill('أحمد');await page.waitForTimeout(350);await page.evaluate(id=>history.pushState(null,'',`/dossiers?facility_id=${id}`),f.second);await page.getByText('0 إضبارة مطابقة',{exact:true}).waitFor();await page.waitForTimeout(1500);assert.equal(await page.getByText('1 إضبارة مطابقة',{exact:true}).count(),0);
    await page.goto(`${base}/dossiers?facility_id=${f.other}`);await page.getByRole('heading',{name:'الإضبارات غير متاحة',exact:true}).waitFor();assert.equal(await page.getByRole('region',{name:'جدول الإضبارات'}).count(),0);
  }finally{await context.close();}}
  await writeFile(new URL('README.md',gallery),'# مراجعة الإضبارات — المرحلة الأولى\n\nلقطات فعلية ببيانات اصطناعية من Chromium، Next standalone → Laravel → MariaDB 10.11.\nالتحميل/الفشل يؤخّران تسليم استجابة Laravel الفعلية أو يفشلان النقل بعدها؛ لا page.route ولا API محاكٍ.\nيستخدم الجدول التمرير الأفقي الداخلي المشترك على الهاتف؛ صور list-actions تظهر الأعمدة والإجراءات بعد التمرير.\n\n'+[390,768,1440].map(w=>`## ${w}px\n\n`+['list','list-actions','oncology-detail','draft-detail','previous-visit','empty','no-visits','loading','error'].map(s=>`- [${s}](${s}-${w}.jpg)`).join('\n')).join('\n\n')+'\n\n![قائمة الإضبارات على سطح المكتب](list-1440.jpg)\n');
});
