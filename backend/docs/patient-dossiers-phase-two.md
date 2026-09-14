# Patient dossiers — Phase 2

## Schema reassessment (before migrations)

Based on merged PR #20, develop `f217df9` (2026-09-14). Identity is global in `patients`; dossiers are unique per patient/facility, with the existing three-column visit FK. No existing wizard progress table or general patient write/search service exists. Blood-bank request reservations and `ClinicAudit` establish the transaction/audit conventions, but their permissions and reservation tables are blood-bank specific.

Differences requiring explicit handling:

- Both `visits.reporting_period_id` **and `visit_diagnoses.reporting_period_id`** are NOT NULL. Both must become nullable to save this workflow without periods. Existing composite period/facility FKs and values remain intact. No application query joins reporting periods to dossier reads; other writers/eligibility definitions are unchanged.
- `patients.identity_document_type` is required, without a directory or CHECK. New wizard patients use `unknown`, with `identity_check_status=pending`; this does not verify identity. Patient code and search_name are required; the server generates only the patient code, never the manual dossier code. Patients have no manual governorate/city columns; foreign addresses can be recorded in address_line without inventing columns.
- `visits.visit_type_id` is required. The wizard explicitly selects an active existing visit type; it does not guess a type. There are no referral fields. Diagnoses already have nullable dates, actors, optimistic versions and a void triple; `is_primary` remains false for new entries.
- Diagnosis category and ICD code are optional. No established medical-code seed convention exists. The explicit reference seeder uses internal `DOS-DX-01`…`DOS-DX-16`, no ICD/category inference.
- Oncology scalar fields can remain populated while is_oncology=false. Add an active flag to selections so deselection retains relational history; scalar and selection changes are also recorded in audit_logs.
- Clinic-staff periods prove membership on a recorded date, but staff/clinic activation history is not complete. New diagnosis contexts require currently active, eligible records **and** a membership period covering the visit date (inclusive start, exclusive end). Unchanged saved contexts are preserved, including inactive historical records. This does not claim historical activation.
- Reuse shared `directoryFacility`, `Picker`, directory controls, clinic form styles, blood-bank conflict comparison and abortable API client. No authentication/AppShell redesign.

## Permission boundary

Facility permissions: dossiers.view, dossiers.create, dossiers.personal.update, dossiers.medical.update, dossiers.visits.create, dossiers.visits.update. Global shared identity/directory operations additionally require explicit patients.search, patients.create, patients.update, diagnoses.create as applicable. Blood-bank search authorization does not imply dossier search authorization (or vice versa). Permission definitions never grant roles automatically.

## Progress and data ownership

`dossier_section_progress` stores one row per dossier/facility/section, state (not_started, in_progress, saved, needs_review), last_saved_by/at, lock_version and optional scoped initial visit ID. GETs never materialize progress. Personal and medical versions use the dossier lock; shared patient edits additionally use the patient lock. Visit and diagnosis edits use visit/row locks. Domain tables remain authoritative. Opening the wizard creates nothing; sections 4–6 are unavailable. No activation, completion, reports or attachment writes are introduced.

`dossier_requests` reserves an actor/facility UUID and canonical content/operation fingerprint in the same transaction as the section save. Identical replay returns the same entity; different content returns 409. Failed transactions leave no patient, reservation or partial section. A dossier lock serializes initial-visit creation. Omitted saved diagnoses are retained; explicit removal voids them with an audit record.

## API and user workflow

All paths below are under `/api/dossiers`, reached through explicitly enumerated `/hospital-api/dossiers` rewrites in Next. Numeric route constraints remain in place. OpenAPI is available at `/docs/api` and `/docs/api.json`.

| Method/path | Contract |
| --- | --- |
| POST `/` | facility_id, request_id UUID, person_mode (existing/new), manual code, opening_date. Existing mode sends patient_id only; new mode sends supported personal fields. Returns 201 with saved snapshot; an existing same-facility dossier is returned without changing it. |
| PUT `/{dossier}/personal` | code/date and current patient fields, dossier lock_version and patient_lock_version; patient replacement is prohibited. |
| PUT `/{dossier}/medical` | dossier lock_version, disability_text, clinical_history, explicit is_oncology; history/treatment arrays, examinations and conditional medication source. Off confirmation retains saved oncology data. |
| POST `/{dossier}/visits` | actual visit_date, explicit visit_type_id, is_referred, conditional referral fields and diagnoses array (may be empty). At most one initial draft is created. |
| PUT `/{dossier}/visits/{visit}` | Same visit section plus lock_version; submitted saved diagnosis IDs need their own lock_version. No writes to other clinical sections. |
| GET `/{dossier}/progress` | Authoritative section snapshot, current patient, saved initial draft and per-section progress. No write on read. |
| GET `/options` | Explicit capabilities, facility-local today, Syrian governorates and active visit types. |
| GET `/options/{patients,cities,clinics,doctors,diagnoses}` | Bounded server search/page; patient search starts only with a nonempty query. Doctor options require clinic_id and use visit_date; cities require governorate_id. |
| POST `/diagnoses` | Global authorized directory create: code, name_ar, facility_id, request_id. Name comparison collapses repeated whitespace; duplicate names/codes return Arabic 422 errors. |

Writes return the domain snapshot in `data`. Validation is 422 with field errors; permissions 403; missing scoped records 404; stale versions or changed UUID content 409. Internal errors remain sanitized 500. A retry with unchanged content keeps the same UUID. A changed body obtains a new UUID. Snapshots and medical values never enter URL parameters or browser persistent storage.

The browser keeps separate unsaved section drafts and their baseline versions. Saving personal information must not silently advance a stale medical draft; conflict review refreshes only the explicitly reviewed section. Newer fields and rows win by default; the user selects specific draft fields/diagnosis contexts and then saves explicitly. Removed saved diagnoses are never automatically resurrected. Delayed requests abort on unmount/facility/session change. List filters remain in navigation URLs.

Patients use a generated `P-<UUID>` identifier (38 characters, within the existing 40-character column). Manual dossier codes remain separate and unchanged. No name/phone matching merges identities. Audit records distinguish the authenticated actor from each clinical doctor, include old/new data and explicit void reasons, and are part of the same transaction. Clinical identity stays in `patients`, so linked blood-bank records see current authorized identity rather than a copy.

## Explicit reference data

Run `DossierDiagnosisReferenceSeeder` explicitly; it does not run automatically in production. It reuses an existing normalized label, refuses a conflicting reserved code, and never fabricates an ICD code/category or reactivates an existing record.

| Internal code | Exact label |
| --- | --- |
| DOS-DX-01 | خباثات الاذن و ملحقاتها |
| DOS-DX-02 | خباثات الغدد اللعابية |
| DOS-DX-03 | لمفوما تائية |
| DOS-DX-04 | خلية مشعرة |
| DOS-DX-05 | فطار فطراني |
| DOS-DX-06 | ساركوما الانسجة الرخوة |
| DOS-DX-07 | ورم الحنجرة |
| DOS-DX-08 | خباثات الجهاز التنفسي و الصدر |
| DOS-DX-09 | خباثات الرئة |
| DOS-DX-10 | خباثات الرغامى و القصبات |
| DOS-DX-11 | خباثات البريتوان |
| DOS-DX-12 | خباثات الحوض |
| DOS-DX-13 | نقائل ورمية للأعضاء |
| DOS-DX-14 | ساركوما خد |
| DOS-DX-15 | الحبن |
| DOS-DX-16 | ورم القلب |

## Operator commands (documented, not executed on production)

Review a backup and the target environment before applying:

```sh
cd backend
php artisan migrate --force
php artisan db:seed --class=DossierPermissionsSeeder --force
php artisan db:seed --class=DossierWorkflowPermissionsSeeder --force
php artisan db:seed --class=DossierDiagnosisReferenceSeeder --force
```

The added migration is `2026_09_17_000001_add_dossier_section_workflow.php`. No deployed migration was edited. Optional periods apply to visits and visit_diagnoses; all existing period/facility and dossier/patient/facility FKs stay enforced. Referral fields have a database CHECK. Progress has scoped FKs, a unique dossier/section key, state/code/version checks; UUID reservations have a unique facility/user/request key.

Review `database/sql/dossier-workflow-permissions.sql` separately. It targets the existing active `super_admin` role in `admin_his`, inserts missing grants idempotently and includes verification SELECTs. It creates no role or scope assignment. Global identity/directory operations still need an existing explicit global assignment. No grants were run against production.

Rebuild Next with the deployment's existing LARAVEL_API_URL (rewrites are compiled), then use the existing standalone packaging process, including `.next/static` and `public`. No new facility environment variable is required. Existing clinic doctor-type configuration and shared visit-type/location directories must be populated by the operator; the wizard does not guess clinical types.

Rollback is only safe before the workflow is used. It refuses **before any DDL** when NULL-period visits/diagnoses, referral data, saved progress, idempotency reservations or inactive oncology selections exist. It never invents periods, deletes visits or loses saved progress to satisfy NOT NULL. Use maintenance isolation during schema rollback; prefer retaining the additive schema after data entry. Phase 1 rollback remains protected too.

## Safe test environment and real transport

Use only a confirmed isolated MySQL/MariaDB database with a test name and explicit host/name safety acknowledgements. Configure `.env.testing` or the process environment using the repository's existing testing example and guard. No SQLite fallback.

```sh
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --filter='DossierWorkflowTest|DossierApiTest|DossierWorkflowMigrationTest|DossierMigrationTest'
```

Real browser suite prerequisites: fresh production build with test Laravel URL `http://127.0.0.1:8194/api`; standalone on loopback port 3194 with public/static copied before startup; guarded `php -S 127.0.0.1:8194 tests/Support/dossier-server.php` using testing environment and synthetic `CLINIC_DOCTOR_STAFF_TYPES=DWF-DOCTOR`. Run `node --test tests/dossier-workflow-live.test.mjs` from frontend with the same test DB environment and installed Playwright browser. Fixture credentials are kept only under ignored backend/storage; the suite revokes its tokens. It asserts actual Laravel transport headers and persisted MariaDB data, not mocked API replies. Do not run RefreshDatabase in parallel with live fixtures.

The Phase 1 real suite `tests/dossiers-live.test.mjs` still covers reader-only access and no writes for a user without create rights. Clinical Catalog eligibility remains complete-only; blood-bank quantities, stage links and period-independent behavior are unchanged.

## Phase 3 boundary

Services/procedures, medications/outcomes, attachments/review remain unavailable steps. No report, upload, activation or completion route is added. Dossier activation and visit completion must remain separate explicit workflows. Future sections extend per-section progress rather than inferring a strictly linear current_step.

Actual synthetic browser gallery: [Phase 2 review](../../frontend/docs/reviews/dossiers-phase-two/README.md).

## Executed verification (2026-09-14–15)

Isolated server: **MariaDB 10.11.18**, Laravel driver **mysql**, database **blood_bank_cities_testing**, host **127.0.0.1:13416**. The existing safety guard passed before database work. No production connection, data, grants or deployment was used. No SQLite.

| Check | Actual result |
| --- | --- |
| `php artisan test-db:check --connect --env=testing` | PASS; confirmed the isolated host/name/driver and connection. |
| Laravel filter `DossierApiTest\|DossierWorkflowTest\|CatalogApiTest\|BloodEventsTest\|ClinicApiTest\|DoctorApiTest\|DirectoryLifecycleTest` | 94 passed, 4,647 assertions, 63.43s before the final two additional workflow regressions. |
| Final Laravel filter `DossierApiTest\|DossierWorkflowTest` | 18 passed, 353 assertions, 37.25s, including existing-patient identity/blood-link preservation and conditional organization validation. |
| Final OpenAPI/safe-error regression after refining nested request schemas | 1 passed, 41 assertions, 35.97s; new diagnosis rows do not require a saved ID, and patient options document the existing dossier ID. |
| Migration filter `DossierWorkflowMigrationTest\|DossierMigrationTest` | 2 passed, 21 assertions, 147.84s; populated upgrade, retained scoped FKs/period links, unused rollback and refusal after NULL-period data. |
| `node --test tests/dossiers-live.test.mjs` (`DOSSIER_SKIP_GALLERY=1`) | 4 passed, 0 failed/skipped, 73.06s; real Phase 1 reads/status/history/isolation, read-only Add and preserved clinical records. |
| `node --test tests/dossier-workflow-live.test.mjs` | 4 passed, 0 failed/skipped, 98.20s on the final standalone build; real transport and persisted database assertions, three widths, inline diagnosis, validation, resume, malformed section URL, failed transport, repeated conflicts and stale contexts. |
| `npx tsc --noEmit` | PASS. |
| `npm run lint` | PASS. |
| `npm run build` | PASS; Next 16.3.4 final production build `z2IHzpIGcVTtYKeJ_Dp-z`, new/edit routes included. Rebuilt standalone uses the isolated Laravel URL; the wizard suite also verifies a malformed section URL falls back to a valid section. |
| Pint on every changed/new PHP file (`--test`) | PASS. |
| `php artisan route:list --path=api/dossiers --env=testing` | PASS; 17 routes, including 13 added section/option routes. |
| `git diff --check` | PASS. |
| Actual browser review | Chromium/Playwright; 39 synthetic full-page screenshots at 390, 768, 1440px. See the committed gallery. |

The organization-name regression was first run before its validation fix: **1 failed** (expected 422, received 200). It passes in the final dossier run. Two obsolete Phase 1 assertions (POST absent/405) were updated to test authorized write documentation and denied reader writes (403); read chronology/eligibility assertions remain intact. Early browser runs exposed an ambiguous synthetic fixture name; fixtures now use unique names and exact patient codes, and the real suites passed after correction.

No requested verification remains blocked in this environment. Browser evidence is from Chromium only; no cross-browser or production deployment is claimed. The role-assignment SQL is delivered for operator review and was not executed against `admin_his`.
