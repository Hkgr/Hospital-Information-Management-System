import assert from 'node:assert/strict';
import {before, after, test} from 'node:test';
import {spawnSync} from 'node:child_process';
import {readFileSync, mkdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {randomUUID} from 'node:crypto';
import {chromium} from 'playwright';

// Real Next standalone -> Laravel -> safety-checked MariaDB; no intercepted requests.
const base='http://127.0.0.1:3194', gallery=new URL('../.superdesign/tmp/simplified-treatment/',import.meta.url);
let f,browser;
function fixture(mode){const r=spawnSync('php',['tests/Support/oncology-live.php',mode],{cwd:fileURLToPath(new URL('../../backend/',import.meta.url)),env:{...process.env,APP_ENV:'testing'},encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);assert.doesNotMatch(r.stdout+r.stderr,/Exception|In .+ line/);}
before(async()=>{fixture('prepare');f=JSON.parse(readFileSync(new URL('../../backend/storage/framework/testing/oncology-live.json',import.meta.url)));browser=await chromium.launch(process.env.PLAYWRIGHT_CHANNEL?{channel:process.env.PLAYWRIGHT_CHANNEL}:{});mkdirSync(gallery,{recursive:true});});
after(async()=>{await browser?.close();fixture('cleanup');});
async function api(method,path,data={}){const r=await fetch(`${base}/hospital-api/dossiers${path}${method==='GET'?`?facility_id=${f.facility}`:''}`,{method,headers:{Accept:'application/json','Content-Type':'application/json',Authorization:`Bearer ${f.token}`},...method==='GET'?{}:{body:JSON.stringify({facility_id:f.facility,request_id:randomUUID(),...data})}});assert.equal(r.headers.get('x-test-laravel'),'dossiers');assert.ok(r.ok,await r.clone().text());return(await r.json()).data;}
async function choose(dialog,label){await dialog.getByRole('button',{name:`اختيار: ${label}`,exact:true}).click();await dialog.getByRole('group',{name:label,exact:true}).locator('button[aria-pressed]').first().click();}
async function shot(page,name){await page.evaluate(()=>document.fonts.ready);assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await page.screenshot({path:fileURLToPath(new URL(`${name}.png`,gallery)),fullPage:true});}
test('functional report, independent appointment, single protocol, medication panes and separate review',async()=>{
 const width=1440;
 const d=await api('POST','',{person_mode:'new',code:'SIMPLE-'+randomUUID().slice(0,16),opening_date:'2001-01-01',visit_date:'2001-03-02',first_name:'مريض',family_name:'تجريبي',birth_date_accuracy:'unknown',gender:'unknown',displacement_status:'unknown'});
 const ctx=await browser.newContext({viewport:{width,height:1000},reducedMotion:'reduce'});await ctx.addInitScript(t=>sessionStorage.setItem('hospital.bearer',t),f.token);const page=await ctx.newPage();page.setDefaultTimeout(15000);
 const url=section=>`${base}/patient-cards/new?card=${d.id}&visit=${d.visit.id}&facility_id=${f.facility}&section=${section}`;
 try{
  await page.goto(url(6));await page.getByRole('button',{name:'إضافة تقرير تشريح مرضي',exact:true}).click();const report=page.getByRole('dialog');
  assert.equal(await report.locator('[name="status"]').count(),0);assert.equal(await report.getByText('المرفقات الداعمة',{exact:true}).count(),0);
  await report.locator('[name="source"]').selectOption('external');await report.locator('[name="report_number"]').fill('00045');
  const reportInvalid=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/pathology'));await report.getByRole('button',{name:'حفظ',exact:true}).click();assert.equal((await reportInvalid).status(),422);assert.equal(await report.locator('[name="report_number"]').inputValue(),'00045');
  await report.locator('[name="external_organization"]').fill('مختبر اصطناعي');await report.locator('[name="result_on"]').fill('2001-02-02');await report.locator('[name="conclusion"]').fill('تقرير موجود فعليًا');
  await shot(page,'pathology');const reportSaved=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/pathology'));await report.getByRole('button',{name:'حفظ',exact:true}).click();const reportResponse=await reportSaved;assert.equal(reportResponse.status(),201);const reportId=(await reportResponse.json()).data.id;assert.equal((await api('GET',`/${d.id}/visits/${d.visit.id}/pathology/${reportId}`)).status,'completed');await report.waitFor({state:'hidden'});
  await page.goto(url(7));await page.getByRole('button',{name:'موعد بدون خطة',exact:true}).click();const dialog=page.getByRole('dialog');
  const invalid=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/treatment-sessions'));
  await dialog.getByRole('button',{name:'حفظ',exact:true}).click();assert.equal((await invalid).status(),422);await page.waitForFunction(()=>document.activeElement?.getAttribute('name')==='planned_on');await shot(page,`appointment-errors-${width}`);
  await dialog.locator('[name="planned_on"]').fill('2030-01-02');await choose(dialog,'العيادة · الطبيب المعالج');await choose(dialog,'الطبيب المعالج');await shot(page,`appointment-${width}`);
  const saved=page.waitForResponse(r=>r.request().method()==='POST'&&r.url().endsWith('/treatment-sessions'));await dialog.getByRole('button',{name:'حفظ',exact:true}).click();assert.equal((await saved).status(),201);await dialog.waitFor({state:'hidden'});await page.getByRole('cell',{name:'موعد مستقل',exact:false}).waitFor();
  assert.equal((await api('GET',`/${d.id}/treatment-plans`)).length,0);const sessions=await api('GET',`/${d.id}/treatment-sessions`);assert.equal(sessions.length,1);assert.equal(sessions[0].plan_id,null);assert.equal((await api('GET',`/${d.id}`)).visit_count,1);assert.equal((await api('GET',`/${d.id}/visits/${d.visit.id}/doses`)).doses.length,0);
  await page.getByRole('button',{name:'إضافة خطة علاجية',exact:true}).click();assert.equal(await dialog.locator('[name="protocol_name"]').count(),0);assert.equal(await dialog.locator('[name="starts_on"]').count(),0);assert.equal(await dialog.getByRole('button',{name:/إضافة دواء/}).count(),0);await dialog.locator('[name="protocol_text"]').fill('نص البروتوكول العلاجي');await shot(page,`plan-${width}`);await dialog.getByRole('button',{name:'إلغاء',exact:true}).click();
  await page.goto(url(4));await page.getByRole('tab',{name:'صرف غير مرتبط بالجرعة',exact:true}).click();await page.getByRole('region',{name:'صرف أدوية الزيارة',exact:true}).getByRole('button',{name:'تسجيل صرف غير مرتبط بالجرعة',exact:true}).waitFor();await shot(page,`medications-${width}`);await page.getByRole('tab',{name:'صرف خارج المشفى',exact:true}).click();await page.getByRole('button',{name:'تسجيل صرف خارج المشفى',exact:true}).waitFor();await page.getByText('الأدوية المرتبطة بالجرعة تُفتح فقط عند تسجيل الجرعة.',{exact:true}).waitFor();await page.getByRole('tab',{name:'الوصفة ونتيجة الزيارة',exact:true}).click();
  await page.goto(url(5));await page.getByRole('button',{name:'الانتقال إلى المراجعة',exact:true}).waitFor();assert.equal(await page.locator('[name="confirmed"]').isVisible(),false);await shot(page,`attachments-${width}`);await page.getByRole('button',{name:'الانتقال إلى المراجعة',exact:true}).click();await page.locator('[name="confirmed"]').waitFor();assert.equal(await page.locator('input[type=file]').isVisible(),false);await shot(page,`review-${width}`);await page.getByRole('tab',{name:'1. المرفقات الاختيارية',exact:true}).click();assert.equal(await page.locator('[name="confirmed"]').isVisible(),false);
 }finally{await ctx.close();}
});
