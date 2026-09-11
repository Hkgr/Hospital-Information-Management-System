// Opt-in real HTTP benchmark. Synthetic secrets/results stay in ignored backend storage.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from 'playwright';
const stage = process.argv[2];
if (!['before', 'after'].includes(stage)) throw new Error('Use before or after.');
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3103';
if (!['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname)) throw new Error('Local test target only.');
const fixture = JSON.parse(await readFile('../backend/storage/framework/testing/directory-performance.json', 'utf8'));
const q = `facility_id=${fixture.facilities[0]}`;
const path = `../backend/storage/framework/testing/directory-performance-${stage}.json`;
const uiOnly = process.argv.includes('--ui');
const result = uiOnly ? JSON.parse(await readFile(path, 'utf8')) : {stage, workload: {patients: fixture.patients, visits: fixture.visits, procedures: fixture.procedures, doctors: fixture.doctors.length, clinics: fixture.clinics.length}, api: {}, ui: {}, concurrency: {}};
async function persist() { await writeFile(path, JSON.stringify(result, null, 2)); }
function summary(rows) {
  const quantile = (key, p) => rows.map(r => r[key]).sort((a,b) => a-b)[Math.ceil(rows.length*p)-1];
  return {samples: rows.length, p50_ms: quantile('ms',.5), p95_ms: quantile('ms',.95), sql_count: quantile('sql_count',.5), sql_ms: quantile('sql_ms',.5), server_ms: quantile('server_ms',.5), bytes: quantile('bytes',.5), errors: rows.filter(r => r.status >= 400).length};
}
async function request(endpoint, employee = 0, options = {}) {
  const start = performance.now();
  const response = await fetch(`${base}/hospital-api/${endpoint}`, { ...options, headers: {Accept: 'application/json', 'Content-Type':'application/json', Authorization: `Bearer ${fixture.users[employee].token}`}, signal: AbortSignal.timeout(60000) });
  const bytes = await response.arrayBuffer();
  return {ms: Math.round((performance.now()-start)*100)/100, status: response.status, bytes: bytes.byteLength, sql_count: Number(response.headers.get('x-test-sql-count')), sql_ms: Number(response.headers.get('x-test-sql-ms')), server_ms: Number(response.headers.get('x-test-server-ms')), json: response.headers.get('content-type')?.includes('json') ? JSON.parse(Buffer.from(bytes).toString()) : undefined};
}
const cases = {
  doctors_list: `doctors?${q}`, clinics_list: `clinics?${q}`, doctors_page_2: `doctors?${q}&page=2`,
  doctors_search: `doctors?${q}&search=${fixture.prefix}-D0001`,
  doctors_cross_search: `doctors?${q}&search=${fixture.prefix}-C001`, clinics_cross_search: `clinics?${q}&search=${fixture.prefix}-D0001`,
  doctors_clinic_filter: `doctors?${q}&clinic_id=${fixture.clinics[0]}`, clinics_doctor_filter: `clinics?${q}&doctor_id=${fixture.doctors[0]}`,
  doctors_patient_sort: `doctors?${q}&sort=patient_count&direction=desc`, clinics_patient_sort: `clinics?${q}&sort=patient_count&direction=desc`,
  doctor_detail: `doctors/${fixture.doctors[0]}?${q}`, clinic_detail: `clinics/${fixture.clinics[0]}?${q}`,
  doctor_link_options: `doctors/options/clinics?${q}&doctor_id=${fixture.doctors[0]}`, clinic_link_options: `clinics/options/doctors?${q}&clinic_id=${fixture.clinics[0]}`,
  xlsx_export: `doctors/export/xlsx?${q}&search=${fixture.prefix}-D0001`, pdf_export: `clinics/${fixture.clinics[0]}/report?${q}`,
};
for (const [name, endpoint] of Object.entries(uiOnly ? {} : cases)) {
  const rows = [];
  await request(endpoint); // same warm-up for each stage, measured samples below.
  for (let n=0; n<8; n++) { const row=await request(endpoint); assert.equal(row.status,200,`${name}: ${row.json?.error?.code}`); rows.push(row); }
  result.api[name] = {...summary(rows), total: rows[0].json?.meta?.total};
  console.log(name, JSON.stringify(result.api[name])); await persist();
}
// Five separate staff sessions issue a mixed list/detail/options workload concurrently.
const concurrent = [];
if (!uiOnly) {
await Promise.all(fixture.users.map(async (_, employee) => {
  const endpoints = [cases.doctors_list, cases.clinics_list, cases.doctor_detail, cases.clinic_link_options];
  for(let n=0; n<12; n++) concurrent.push(await request(endpoints[n%endpoints.length], employee));
}));
result.concurrency = {employees: 5, ...summary(concurrent)}; await persist();
console.log('concurrent', JSON.stringify(result.concurrency));
}
const browser = await chromium.launch({channel: process.env.PLAYWRIGHT_CHANNEL || 'chrome'});
try {
  for (const directory of ['doctors','clinics']) {
    const context = await browser.newContext({viewport:{width:1440,height:900}});
    await context.addInitScript(token => sessionStorage.setItem('hospital.bearer',token),fixture.users[0].token);
    const page = await context.newPage();
    let requests = [], metrics = [];
    page.on('request', req => requests.push({path:new URL(req.url()).pathname, type:req.resourceType()}));
    page.on('response', response => { if(new URL(response.url()).pathname.startsWith('/hospital-api/')) metrics.push(response); });
    async function measure(name, action) {
      requests=[];metrics=[]; const started=performance.now(); await action(); await page.waitForLoadState('networkidle');
      const ms=Math.round(performance.now()-started); const responses=await Promise.all(metrics.map(async response=>({status:response.status(),bytes:(await response.body()).length,sql_count:Number(response.headers()['x-test-sql-count']),sql_ms:Number(response.headers()['x-test-sql-ms']),...(response.status()>=400?{error:await response.json(),path:new URL(response.url()).pathname}:{})})));
      const row={ms, network_requests:requests.length, api_requests:metrics.length, sql_count:responses.reduce((sum,r)=>sum+r.sql_count,0),sql_ms:responses.reduce((sum,r)=>sum+r.sql_ms,0),bytes:responses.reduce((sum,r)=>sum+r.bytes,0),errors:responses.filter(r=>r.status>=400).length,paths:requests.map(r=>r.path)};
      result.ui[`${directory}_${name}`]=row;console.log(`${directory}_${name}`,JSON.stringify({...row,paths:undefined})); for(const response of responses.filter(r=>r.error))console.log('UI response error',response.path,response.error);await persist();
    }
    const code=`${fixture.prefix}-${directory==='doctors'?'D0001':'C001'}`;
    await measure('open',async()=>{await page.goto(`${base}/${directory}?${q}`);await page.getByRole('link',{name:code,exact:true}).first().waitFor();});
    await measure('search',async()=>{await page.getByRole('searchbox').first().fill(code);await page.waitForResponse(r=>new URL(r.url()).searchParams.get('search')===code);await page.getByRole('link',{name:code,exact:true}).first().waitFor();});
    await measure('edit',async()=>{await page.getByRole('button',{name:directory==='doctors'?'تعديل طبيب اختباري 1':'تعديل عيادة اختبارية 1',exact:true}).click();await page.getByRole('dialog').getByRole('button',{name:directory==='doctors'?'حفظ الطبيب':'حفظ العيادة',exact:true}).waitFor();await page.waitForTimeout(500);});
    await measure('save',async()=>{await page.getByRole('dialog').getByRole('button',{name:directory==='doctors'?'حفظ الطبيب':'حفظ العيادة',exact:true}).click();await page.getByRole('dialog').waitFor({state:'hidden'});});
    await context.close();
  }
} finally { await browser.close(); }
await persist();
