# Patient Card legacy onboarding and offline capture

Base: `develop` at `e572a0130563f70a46b0ea68d0c4fe08a6ad6821` (merged PR #27).
Branch: `feature/legacy-patient-import-and-offline-capture`. This is a draft release;
do not merge/deploy without independent review and operator approval.

## Source workbook comparison

The operator confirmed `نموذج_الإضبارات_v2.1.xlsx` as the previous workbook.
Its sheet names, instructions and headers were inspected locally; no source
patient data or workbook was uploaded to the repository or design services.

| Previous sheet/contract | Current controlled contract |
| --- | --- |
| الإضبارات: preassigned `patient_dossiers.code` | `patients.patient_code` is canonical. A supplied code matches an existing identity only; new identities receive an automatic reserved `PC-00000001` style code. A historical code can be retained as the existing dossier alias after collision checks. |
| الزيارات: preassigned visit number and card code | Stable `source_record_id`, `local_patient_ref`, `local_visit_ref`; explicit actual date and visit type. A person without a visit row creates no visit. |
| التشخيصات / الخدمات / الإجراءات: labels, descriptions, optional clinic/doctor fields | Stable directory IDs plus explicit clinic and eligible doctor at the visit date. No name guessing, directory creation or logged-in-user substitution. |
| جلسات الجرعة / أدوية الجلسات / علاجات السرطان | Unsupported in bulk import. Continue via diagnostic/pathology and oncology workflows. Never reinterpret these rows as ordinary prescriptions or dispensing. |
| ملفات السرطان and free notes | Explicit supported dossier narrative/oncology flag only; import-only notes remain encrypted provenance, not structured pathology/treatment evidence. |
| مخطط الاستيراد: legacy mappings | Replaced by the versioned schema in `ImportWorkbook::SHEETS`. The old file is rejected, not silently mapped. |

Existing interactive registration still requires its explicit real initial visit
and manually entered canonical code. The new import adapter reuses the same
identity reservation, personal validator/writer, clinical validators/writers,
auditing and scope checks, with a trusted internal option to create a legacy
context without a visit. It does not change the interactive API contract.

## Workbook v1

`template_version=patient-import-1`. Exact ordered sheets:
Instructions, Patients, Visits, Diagnoses, Services, Procedures, Prescriptions,
Medications, Outcomes, Reference_Data. Hidden row 1 contains stable machine keys;
row 2 contains Arabic labels; data starts at row 3. Template/reference metadata
is protected. RTL, Cairo, fixed readable widths, frozen headers, filters and
reference/enumeration dropdowns use the existing PhpSpreadsheet dependency.
Reference_Data contains directories and assignment intervals, never patient data.

Download a fresh template for the authorized facility, explicit purpose
(`legacy_migration` / `offline_capture`) and cutover date. The Emirates Hospital
UI initially suggests 2026-09-01; the operator can change it. Server clinical logic
has no hardcoded cutover. The upload must exactly match its template metadata.
Changing metadata never shifts the real opening or occurrence dates.

Patients require a stable source ID, local reference and real opening date.
New identities require first/family name, gender, displacement status and birth
precision. Existing canonical codes or unambiguous aliases match only authorized
global-patient lookup. Conflicting populated identity or dossier values require
external operator review; imports never overwrite them. Paper-file collisions
also require review. Names alone never merge identities.

Birth precision is preserved: `unknown` has no date; `year_only` accepts a four
digit year and uses the existing date-plus-precision storage convention. It is
never promoted to `exact`. Codes, phone numbers and leading-zero values are text.
Ordinary date fields accept real Excel date cells or ISO dates; no formulas.

Visits need explicit dates, type and referral state. Opening must not follow any
imported visit; future occurrences are rejected in facility time. Multiple real
visits on the same date remain distinct by source ID. Imported visits and new
contexts remain drafts; import never infers completion, activation or readiness.
The first explicit recorded visit is the registration visit, not a lifetime-first
claim. Existing completed/voided visits are never changed through reimport.

Prescriptions is one header per visit, with clinic, doctor and explicit date.
Medications contains its items, with immutable medication snapshots written by
the existing prescription writer. These rows never enter `visit_medications`.
Services/procedures use their current dossier-managed, period-optional contract.
Other modules' reporting-period rules are untouched.

## Safe processing and retries

Upload validates structure and saves private source/provenance only. Validation
performs no clinical writes. States: uploaded → validating → validated or
needs_review → committing → completed/completed_with_errors; cancellation affects
only uncommitted rows. A batch with both valid and review groups is `validated`,
with its review counts visible. Explicit confirmation is required to commit.

No tested deployed queue-worker contract was found. Processing therefore uses
bounded, resumable HTTP steps of at most ten patient bundles. Each patient and all
its explicit facts share a transaction/savepoint; clinical validation failure
rolls back that bundle. The enclosing chunk/version lock rolls back an interrupted
chunk. Previously completed chunks stay saved. Reopen the durable batch URL and
continue. No daemon, scheduler modification or arbitrary delay is required.

At commit, permissions, matching, identity/context versions, active references and
assignment dates are checked again. The shared identity sequence reservation and
database unique source keys serialize competitors. Stale batch versions return
409. A concurrent successful commit is never repeated. The same facility/file
SHA-256 returns its previous batch. Source IDs must be unique across the entire workbook. Retained source identity is keyed by facility and
sheet across all batches: identical payloads skip; changed payloads are review
conflicts, never updates. Do not assign a new source ID to evade a conflict.

An expired batch cannot resume after its source retention ends. Download a new template and resubmit the still-needed sources with their original stable IDs; unchanged committed sources remain duplicates, not new facts.

Correct failed source rows outside automatic commit, retain their local/source
references, and upload the corrected workbook. Committed source rows remain
unchanged and are skipped. A new explicit visit may reuse the patient source.
Adding facts to a previously imported visit requires its existing correction
workflow, not resubmission. Unsupported notes stay labeled as import provenance.

## Endpoints and permissions

All routes use Sanctum Bearer `api`, active accounts, facility scope and
`private, no-store`. Next has only specific rewrites under `/hospital-api/dossiers`.

| Method/path under `/api/dossiers` | Additional permission |
| --- | --- |
| GET imports; GET imports/{batch} | dossiers.import.view |
| POST imports (multipart XLSX, facility_id, purpose, cutover_date) | dossiers.import.create |
| POST imports/{batch}/validate | dossiers.import.validate |
| POST imports/{batch}/commit (confirm=true) | dossiers.import.commit |
| POST imports/{batch}/cancel | dossiers.import.cancel |
| GET import-template.xlsx; GET imports/{batch}/errors.xlsx | dossiers.import.download |

Every route also requires `dossiers.view` and `dossiers.import.view`. Clinical
operations additionally require the existing `patients.create`/`patients.search`
global permissions and facility `dossiers.create`, `dossiers.visits.create`,
`dossiers.medical.update`, `dossiers.clinical.update` as applicable. Seed definitions
only; never grant permissions automatically. The operator must review actual
global and facility role assignments before use.

Mutation steps require current `lock_version`. Responses contain the batch state,
counts, matching actions and paginated rows with safe field errors and saved-card
links. Row filters: status/action; 50 rows/page. Batch list: 25/page. Codes:
401 unauthenticated, 403 missing scope/permission, 404 inaccessible batch,
409 stale version/state, 413 oversized input, 422 invalid template/archive/input.
Row errors in a successfully processed batch are represented in its preview.
The OpenAPI transformer documents the current import contract.

## Privacy, limits and retention

- XLSX only, maximum 10 MiB compressed, 80 MB expanded, 500 archive entries,
  40 MB/entry and 500:1 compression ratio; 5000 patients, 30000 data rows,
  500 rows/patient bundle, 20000 characters/cell. Reject macros, encrypted files,
  external relationships, DTD/entities, embedded files and arbitrary/future layouts.
- Forward-only XML reading avoids materializing the complete PhpSpreadsheet cell
  graph. Bounded reference maps are preloaded per HTTP chunk. Clinical writers
  still perform their necessary transactional per-fact checks/writes.
- Generated text cells use explicit string types, including formula-like notes.
  Source formulas are rejected and never evaluated. Error workbooks contain
  stable references, field names, fixed messages and corrective instructions only.
- Files use the existing `dossier_private` disk, outside `public`, with `serve=false`.
  Do not symlink this directory, expose it through a web-server alias or log
  request bodies. Normalized source payloads are encrypted with Laravel APP_KEY.
  Preserve the encryption key and key rotation plan in secured operator backups.
- Original workbook retention is 30 days. Cleanup removes expired workbooks and
  uncommitted encrypted drafts, preserving source hashes, matching/outcomes,
  clinical FKs and audit. Committed encrypted provenance, including explicit
  import notes, remains part of the retained historical record. No public source
  download URL is exposed. Error workbooks are streamed, not persisted.
- Audit contains batch/source identifiers and decisions. Patient-card history
  links only that card's own imported source rows, never other patients' workbook
  contents. Retention is attributed to an explicit existing operator account.

## Operator deployment steps (not executed on production)

1. Take the normal verified database/private-storage backup and maintenance steps.
2. Deploy backend code/dependencies and apply additive migrations:
   `php artisan migrate --force`.
3. Define new permissions: `php artisan db:seed --class=DossierImportPermissionsSeeder --force`.
   Existing DossierWorkflow/Completion permissions, outcome and directory seeders
   remain prerequisites from prior releases; do not reseed or overwrite directories.
4. Review and assign the six import permissions to intended facility roles, and
   the necessary existing clinical/global permissions. No assignment is automatic.
5. Rebuild Next with the intended `LARAVEL_API_URL`, copy standalone public/static
   assets before starting the server, then use the existing deployment procedure.
6. Keep PHP ZIP/XMLReader/SimpleXML extensions (already PhpSpreadsheet dependencies)
   enabled; allow at least 256 MiB worker memory. Set PHP/web-proxy multipart limits
   above 10 MiB, with bounded request timeouts appropriate to measured chunk times.
7. Review a dry run: `php artisan dossiers:cleanup-imports`.
   Example operator-managed cron (replace paths and audited user ID):
   `17 3 * * * cd /srv/hospital/backend && php artisan dossiers:cleanup-imports --apply --actor=OPERATOR_USER_ID >> /var/log/hospital-import-retention.log 2>&1`
   This PR does not change the server scheduler.

Migration: `2026_09_21_000003_add_dossier_import_batches.php`. It adds batch, row
and committed-source tables with composite scope FKs and unique source/file keys.
Rollback preflights before DDL and refuses whenever any batch history exists. It
never deletes clinical facts or provenance to make a rollback succeed.

## Verification

Use only the existing guarded, populated test MariaDB and the preserving bootstrap:

```text
php artisan test-db:check --connect --env=testing
php artisan migrate --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/DossierImportTest.php
php tests/Support/dossier-import-live.php prepare
node --test tests/dossier-import-live.test.mjs   # from frontend, fresh standalone Next → test Laravel
php tests/Support/dossier-import-live.php concurrency
php tests/Support/dossier-import-live.php performance
php tests/Support/dossier-import-live.php verify
php tests/Support/dossier-import-live.php cleanup
```

The live fixture retains all synthetic clinical/import history and revokes only
its own tokens. No reset, truncate, fresh migration or disposable empty database.
Do not run older `*MigrationTest` classes that call `migrate:fresh` on this retained
test database. The new migration has its own non-destructive preflight coverage.
Exact final results and remaining limitations are recorded in the PR review notes.
