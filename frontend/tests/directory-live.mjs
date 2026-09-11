// Opt-in, real production frontend proxy + guarded testing Laravel; no API mocks.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
const base = process.env.TEST_BASE_URL || 'http://127.0.0.1:3103';
if (!['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname)) throw new Error('Local testing target only.');
const fixture = JSON.parse(await readFile('../backend/storage/framework/testing/directory-performance.json', 'utf8'));
const facility_id = fixture.facilities[0], results = [];
async function api(name, method, path, data, status = 200) {
  const started = performance.now();
  const response = await fetch(`${base}/hospital-api/${path}${path.includes('?') ? '&' : '?'}facility_id=${facility_id}`, {
    method, headers: {Authorization: `Bearer ${fixture.users[0].token}`, Accept: 'application/json', 'Content-Type': 'application/json'},
    ...(data ? {body: JSON.stringify({facility_id, ...data})} : {}), signal: AbortSignal.timeout(30000),
  });
  const bytes = await response.arrayBuffer();
  const json = bytes.byteLength ? JSON.parse(Buffer.from(bytes).toString()) : {};
  assert.equal(response.status, status, `${name}: ${JSON.stringify(json)}`);
  results.push({name, status, ms: Math.round(performance.now()-started), bytes: bytes.byteLength, sql_count: Number(response.headers.get('x-test-sql-count')), sql_ms: Number(response.headers.get('x-test-sql-ms'))});
  return json;
}
const suffix = Date.now().toString(36);
const doctorInput = {code: `LIVE-D-${suffix}`, name: 'طبيب اصطناعي للتحقق التشغيلي', staff_type_id: fixture.type, specialty_ids: [fixture.specialty], is_active: true};
const clinicInput = {code: `LIVE-C-${suffix}`, name_ar: 'عيادة اصطناعية للتحقق التشغيلي', is_active: true};
let doctor = (await api('create doctor', 'POST', 'doctors', doctorInput, 201)).data;
let clinic = (await api('create clinic', 'POST', 'clinics', clinicInput, 201)).data;
await api('paginated clinic picker', 'GET', `doctors/options/clinics?doctor_id=${doctor.id}&search=&page=1`);
await api('paginated doctor picker', 'GET', `clinics/options/doctors?clinic_id=${clinic.id}&search=&page=1`);
doctor = (await api('link from doctor', 'PUT', `doctors/${doctor.id}/clinics`, {lock_version: doctor.lock_version, clinic_add_ids:[clinic.id], clinic_remove_ids:[]})).data;
assert.equal(doctor.clinic_count, 1);
clinic = (await api('read counterpart after doctor save', 'GET', `clinics/${clinic.id}`)).data;
assert.equal(clinic.doctor_count, 1);
assert.equal((await api('search doctor by clinic', 'GET', `doctors?search=${clinicInput.code}`)).data[0].id, doctor.id);
assert.equal((await api('search clinic by doctor', 'GET', `clinics?search=${doctorInput.code}`)).data[0].id, clinic.id);
await api('reject stale doctor version', 'PUT', `doctors/${doctor.id}/clinics`, {lock_version: doctor.lock_version-1, clinic_remove_ids:[clinic.id]}, 409);
clinic = (await api('unlink from clinic', 'PUT', `clinics/${clinic.id}`, {...clinicInput, lock_version:clinic.lock_version, doctor_remove_ids:[doctor.id]})).data;
assert.equal(clinic.doctor_count, 0);
doctor = (await api('read counterpart after clinic removal', 'GET', `doctors/${doctor.id}`)).data;
assert.equal(doctor.clinic_count, 0);
clinic = (await api('link from clinic', 'PUT', `clinics/${clinic.id}`, {...clinicInput, lock_version:clinic.lock_version, doctor_add_ids:[doctor.id]})).data;
assert.equal(clinic.doctor_count, 1);
doctor = (await api('read doctor after clinic link', 'GET', `doctors/${doctor.id}`)).data;
assert.equal(doctor.clinic_count, 1);
doctor = (await api('unlink from doctor', 'PUT', `doctors/${doctor.id}/clinics`, {lock_version:doctor.lock_version, clinic_remove_ids:[clinic.id]})).data;
assert.equal(doctor.clinic_count, 0);
clinic = (await api('read clinic after doctor removal', 'GET', `clinics/${clinic.id}`)).data;
assert.equal(clinic.doctor_count, 0);
assert.equal((await api('cross search excludes closed link', 'GET', `doctors?search=${clinicInput.code}`)).meta.total, 0);
await writeFile('../backend/storage/framework/testing/directory-live.json', JSON.stringify(results, null, 2));
console.log(JSON.stringify(results));
