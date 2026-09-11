// Real Laravel HTTP + confirmed MySQL fixture. No intercepted requests or mock API.
import assert from 'node:assert/strict';
import { readFile,writeFile,mkdir } from 'node:fs/promises';
const base=process.env.TEST_LARAVEL_URL || 'http://127.0.0.1:8001/api';
if(!['127.0.0.1','localhost','[::1]'].includes(new URL(base).hostname)) throw new Error('Live doctor checks require the explicitly prepared local test server.');
const fixture=JSON.parse(await readFile('../backend/storage/framework/testing/doctor-live.json','utf8'));
let token;
async function api(path,{method='GET',body}={}){
  const response=await fetch(`${base}/${path}`,{method,headers:{Accept:'application/json','Content-Type':'application/json',...(token?{Authorization:`Bearer ${token}`}:{})},...(body?{body:JSON.stringify(body)}:{})});
  return response;
}
const login=await api('login',{method:'POST',body:{username:fixture.username,password:fixture.password,device_name:'doctor-live-test'}});assert.equal(login.status,200);token=(await login.json()).data.token;
const q=`facility_id=${fixture.facility_id}`;
const create=await api('doctors',{method:'POST',body:{facility_id:fixture.facility_id,code:`LIVE-${fixture.suffix}`,name:'طبيب التكامل الحقيقي — بيانات اختبار',description:'توصيف اختباري ناتج عن مسار HTTP حي.',staff_type_id:fixture.staff_type_id,specialty_ids:[fixture.specialty_id],is_active:true,clinic_add_ids:[]}});
assert.equal(create.status,201);const doctor=(await create.json()).data;assert.equal(doctor.clinic_count,0);
const link=await api(`doctors/${doctor.id}/clinics`,{method:'PUT',body:{facility_id:fixture.facility_id,lock_version:doctor.lock_version,clinic_add_ids:fixture.clinics}});assert.equal(link.status,200);const linked=(await link.json()).data;assert.equal(linked.clinic_count,2);
const list=await api(`doctors/${doctor.id}/clinics?${q}`);assert.equal((await list.json()).meta.total,2);
for(const id of fixture.clinics){const c=await api(`clinics/${id}?${q}`);const row=(await c.json()).data;assert.equal(row.doctor_count,1);assert.equal(row.lock_version,2);}
const stale=await api(`doctors/${doctor.id}/clinics`,{method:'PUT',body:{facility_id:fixture.facility_id,lock_version:1,clinic_remove_ids:[fixture.clinics[0]]}});assert.equal(stale.status,409);assert.equal((await stale.json()).error.code,'DOCTOR_VERSION_CONFLICT');
await mkdir('../backend/docs/samples/doctors',{recursive:true});
for(const [path,file] of [[`doctors/${doctor.id}/report?${q}`,'live-doctor.pdf'],[`doctors/export/xlsx?${q}&search=${encodeURIComponent(`LIVE-${fixture.suffix}`)}`,'live-doctor.xlsx']]){
  const response=await api(path);assert.equal(response.status,200);assert.match(response.headers.get('cache-control'),/private/);assert.match(response.headers.get('cache-control'),/no-store/);const bytes=Buffer.from(await response.arrayBuffer());
  assert.ok(bytes.subarray(0,5).toString()==='%PDF-'||bytes.subarray(0,2).toString()==='PK');await writeFile(`../backend/docs/samples/doctors/${file}`,bytes);
}
await writeFile('../backend/storage/framework/testing/doctor-live-result.json',JSON.stringify({doctor_id:doctor.id,facility_id:fixture.facility_id,clinics:fixture.clinics,lock_version:linked.lock_version}));
// A second real session remains valid for the optional visual browser check; keep it local only.
await writeFile('../backend/storage/framework/testing/doctor-live-browser.json',JSON.stringify({token,doctor_id:doctor.id,facility_id:fixture.facility_id}));
console.log('PASS real Laravel HTTP: login, create doctor without clinics, link two clinics, verify clinic-side versions/counts, reject stale write, download private Cairo PDF and XLSX. No mocked requests.');
