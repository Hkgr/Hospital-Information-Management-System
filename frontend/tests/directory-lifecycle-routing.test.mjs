import assert from "node:assert/strict";
import { after, before, test } from "node:test";
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { spawnSync } from "node:child_process";

// Real HTTP only: a freshly built Next standalone server -> guarded Laravel -> MySQL.
const base = new URL(process.env.TEST_BASE_URL || "http://127.0.0.1:3105");
assert.equal(base.protocol, "http:");
assert.ok(["127.0.0.1", "localhost", "[::1]"].includes(base.hostname));
const backend = fileURLToPath(new URL("../../backend/", import.meta.url));
const fixturePath = new URL("../../backend/storage/framework/testing/directory-routing.json", import.meta.url);
const resultPath = new URL("../../backend/storage/framework/testing/directory-routing-result.json", import.meta.url);
const actions = ["deletion-preview", "link-history", "archive", "restore", "reactivate"];
const results = [];
let fixture;

function php(mode) {
  const result = spawnSync("php", ["tests/Support/directory-routing-fixture.php", mode], {
    cwd: backend, env: { ...process.env, APP_ENV: "testing" }, encoding: "utf8", timeout: 30_000,
  });
  assert.equal(result.status, 0, `Guarded fixture ${mode} failed: ${result.stdout}\n${result.stderr}`);
  process.stdout.write(result.stdout);
}

before(() => {
  php("prepare");
  fixture = JSON.parse(readFileSync(fixturePath, "utf8"));
});

after(() => {
  if (!fixture) return;
  try {
    if (results.length === 2) {
      writeFileSync(resultPath, JSON.stringify(results));
      php("verify");
    }
  } finally {
    php("cleanup");
  }
});

async function request(method, path, status, { body, query, identity = "writer", forwarded = true } = {}) {
  const url = new URL(`/hospital-api/${path}`, base);
  if (query) url.search = new URLSearchParams(query).toString();
  const headers = { Accept: "application/json" };
  if (identity !== "anonymous") headers.Authorization = `Bearer ${identity === "denied" ? fixture.denied_token : fixture.token}`;
  if (body) headers["Content-Type"] = "application/json";
  const response = await fetch(url, {
    method, headers, body: body && JSON.stringify(body), redirect: "error", signal: AbortSignal.timeout(15_000),
  });
  assert.equal(response.status, status, `${method} ${path}`);
  assert.equal(response.headers.get("x-test-laravel"), forwarded ? "directory-routing" : null, `${path}: origin`);
  if (!forwarded) {
    assert.match(response.headers.get("content-type"), /text\/html/);
    await response.text();
    return;
  }
  if (status === 204) {
    assert.equal(await response.text(), "");
    return;
  }
  assert.match(response.headers.get("content-type"), /application\/json/);
  return response.json();
}

function facility(extra = {}) { return { facility_id: fixture.facility_id, ...extra }; }
function actionMethod(action) { return action === "deletion-preview" || action === "link-history" ? "GET" : "POST"; }
function actionOptions(action, extra = {}) {
  return { ...(actionMethod(action) === "GET" ? { query: facility() } : { body: facility({ lock_version: 1 }) }), ...extra };
}
async function read(kind, id) {
  return (await request("GET", `${kind}/${id}`, 200, { query: facility() })).data;
}
async function create(kind, label, extra = {}) {
  const fields = kind === "doctors"
    ? { name: `طبيب ${label}`, staff_type_id: fixture.staff_type_id, specialty_ids: [fixture.specialty_id] }
    : { name_ar: `عيادة ${label}` };
  return (await request("POST", kind, 201, { body: facility({ code: `${label}-${fixture.suffix}`, is_active: true, ...fields, ...extra }) })).data;
}

// Independent cases: removing any one of the ten rewrites must fail the live suite.
for (const kind of ["doctors", "clinics"]) {
  for (const action of actions) {
    test(`${kind}/${action}: anonymous request reaches Laravel as JSON 401`, async () => {
      const json = await request(actionMethod(action), `${kind}/1/${action}`, 401, actionOptions(action, { identity: "anonymous" }));
      assert.equal(json.error.code, "UNAUTHENTICATED");
    });
  }

  test(`${kind}: delete, archive, history, restore and reactivate through Next, preserving versions/counts/periods`, async () => {
    const bare = await create(kind, `${kind}-bare`);
    const preview = (await request("GET", `${kind}/${bare.id}/deletion-preview`, 200, { query: facility() })).data;
    assert.deepEqual(preview, { action: "delete", organizational_links: 0, has_other_references: false, lock_version: bare.lock_version, archived: false });
    await request("DELETE", `${kind}/${bare.id}`, 204, { body: facility({ lock_version: preview.lock_version }) });
    const deleted = await request("GET", `${kind}/${bare.id}`, 404, { query: facility() });
    assert.equal(deleted.error.code, kind === "doctors" ? "DOCTOR_NOT_FOUND" : "CLINIC_NOT_FOUND");

    const doctor = await create("doctors", `${kind}-linked-d`);
    const clinic = await create("clinics", `${kind}-linked-c`, { doctor_add_ids: [doctor.id] });
    const targetId = kind === "doctors" ? doctor.id : clinic.id;
    const counterpart = kind === "doctors" ? clinic : doctor;
    assert.equal((await read("doctors", doctor.id)).clinic_count, 1);
    assert.equal((await read("clinics", clinic.id)).doctor_count, 1);
    const linked = (await request("GET", `${kind}/${targetId}/deletion-preview`, 200, { query: facility() })).data;
    assert.equal(linked.action, "archive");
    assert.equal(linked.organizational_links, 1);
    assert.equal(linked.has_other_references, false);
    const act = async (action, version, status = 200) => request("POST", `${kind}/${targetId}/${action}`, status, { body: facility({ lock_version: version }) });
    const archived = (await act("archive", linked.lock_version)).data;
    assert.equal(archived.is_active, false);
    assert.equal(typeof archived.archived_at, "string");
    assert.equal(archived.lock_version, linked.lock_version + 1);
    const history = await request("GET", `${kind}/${targetId}/link-history`, 200, { query: facility({ page: 1, per_page: 1 }) });
    assert.deepEqual(history.meta, { page: 1, per_page: 1, total: 1, last_page: 1 });
    assert.equal(history.data.length, 1);
    assert.equal(history.data[0].code, counterpart.code);
    assert.equal(history.data[0].name, counterpart.name_ar ?? counterpart.name);
    assert.equal(history.data[0].starts_on, fixture.today);
    assert.equal(history.data[0].ends_on, fixture.today);
    const stale = await act("archive", linked.lock_version, 409);
    assert.equal(stale.error.code, kind === "doctors" ? "DOCTOR_VERSION_CONFLICT" : "CLINIC_VERSION_CONFLICT");
    const restored = (await act("restore", archived.lock_version)).data;
    assert.equal(restored.is_active, false);
    assert.equal(restored.archived_at, null);
    assert.equal(restored.lock_version, archived.lock_version + 1);
    assert.equal((await read(kind, targetId)).is_active, false);
    const active = (await act("reactivate", restored.lock_version)).data;
    assert.equal(active.is_active, true);
    assert.equal(active.archived_at, null);
    assert.equal(active.lock_version, restored.lock_version + 1);
    const currentDoctor = await read("doctors", doctor.id);
    const currentClinic = await read("clinics", clinic.id);
    for (const record of [currentDoctor, currentClinic]) {
      assert.equal(record.is_active, true);
      assert.equal(record.archived_at, null);
      assert.equal(record.patient_count, 0);
    }
    assert.equal(currentDoctor.clinic_count, 0);
    assert.equal(currentClinic.doctor_count, 0);
    assert.equal(currentDoctor.lock_version, kind === "doctors" ? 5 : 3);
    assert.equal(currentClinic.lock_version, kind === "doctors" ? 2 : 4);
    const finalHistory = await request("GET", `${kind}/${targetId}/link-history`, 200, { query: facility({ page: 1, per_page: 1 }) });
    assert.deepEqual(finalHistory, history, "restore/reactivate must not reopen archived periods");
    results.push({ kind, doctor_id: doctor.id, clinic_id: clinic.id, deleted_id: bare.id });
  });

  test(`${kind}: all five routes preserve authorization failures as JSON 403`, async () => {
    for (const action of actions) {
      const json = await request(actionMethod(action), `${kind}/1/${action}`, 403, actionOptions(action, { identity: "denied" }));
      assert.equal(json.error.code, kind === "doctors" ? "DOCTOR_ACCESS_DENIED" : "CLINIC_ACCESS_DENIED");
    }
  });

  test(`${kind}: required body and query fields preserve JSON 422`, async () => {
    for (const action of ["archive", "restore", "reactivate"]) {
      const json = await request("POST", `${kind}/1/${action}`, 422, { body: facility() });
      assert.ok(json.errors.lock_version.length);
    }
    const json = await request("GET", `${kind}/1/deletion-preview`, 422);
    assert.ok(json.errors.facility_id.length);
  });

  test(`${kind}: nonnumeric IDs and unknown paths stay outside rewrites; missing numeric IDs reach Laravel`, async () => {
    for (const action of actions) {
      for (const id of ["not-a-number", "1abc"]) {
        await request(actionMethod(action), `${kind}/${id}/${action}`, 404, actionOptions(action, { forwarded: false }));
      }
      const missing = await request(actionMethod(action), `${kind}/2147483647/${action}`, 404, actionOptions(action));
      assert.equal(missing.error.code, kind === "doctors" ? "DOCTOR_NOT_FOUND" : "CLINIC_NOT_FOUND");
    }
    await request("GET", `${kind}/123/not-a-lifecycle-route`, 404, { query: facility(), forwarded: false });
  });
}
