# Patient dossiers — Phase 1 operating contract

Read-only dossier list and detail foundation, based on develop `9f14022`. See [pre-implementation schema assessment](patient-dossiers-schema-assessment.md) for verified existing meanings, foreign keys, reuse and deferred fields.

## Storage and migration

`2026_09_16_000001_add_patient_dossier_foundation.php` adds:

- `patient_dossiers`: manual `code`, required complete `opening_date`, patient/facility FKs, disability_text, one clinical_history, is_oncology, previous_examinations, medication_source/conditional other_organization, draft/active status, entered_by/updated_by, lock_version and timestamps. Identity remains in patients. Unique patient per facility and code per facility; the same code in different facilities is supported, like clinics. No automatic dossier creation.
- `dossier_oncology_selections`: auditable actor/version/timestamps for relational `history` (medical/surgical/medication/family) and `treatment` (surgical/chemotherapy/radiotherapy/other) selections. Unique dossier/group/code; composite dossier/facility FK. Future writes must audit each selection change in audit_logs with the dossier transaction.
- Nullable `visits.dossier_id` with a composite dossier/facility/patient FK and chronology index; **existing visits remain unlinked**.
- Nullable `visit_diagnoses.clinic_id` with composite clinic/facility FK and index; nullable diagnosed_on for genuinely unknown dates. Existing dates and unknown clinic associations are not backfilled. Clinic deletion-preview reference checks include the new association.

No existing patient, visit, cancer case, transfusion, reporting period or service/procedure event is created, remapped or deleted by the migration. No attachments relation exists; report export storage is not a clinical attachment. Existing cancer episodes are not silently merged into the new current oncology profile.

Rollback preflights **before any DDL**: refuses when dossiers/selections, explicit visit/diagnosis links or unknown diagnosis dates would be lost. Keep the additive schema with a compatible application version, or restore a reviewed backup using the normal recovery procedure. Do not clear dossier rows to force rollback. Before first use, rollback remains possible. MariaDB DDL commits implicitly; use the established maintenance/backup procedure.

## Permission and navigation

New minimal facility permission: **`dossiers.view` — عرض إضبارات المرضى وزياراتها في المنشأة**. `DossierPermissionsSeeder` defines it idempotently and assigns nothing. No doctor/clinic/catalog/global permission substitutes for it. A user with only this permission can enter directly.

Navigation uses the existing primaryNavigation permission configuration, with the existing canView flag passed through desktop/mobile sidebar props. The only AuthenticatedLayout change passes the existing identity's dossiers.view capability to AppShell; login, sessions, tokens and authorization behavior are unchanged. No AppShell layout redesign. No additional facility dropdown or environment setting. Shared directoryFacility chooses the first permitted facility when URL has none; an explicitly forbidden facility never falls back.

An operator-reviewed assignment artifact for the **existing active `super_admin` role in `admin_his`** is [dossier-view-permission.sql](../database/sql/dossier-view-permission.sql). It includes verification, is idempotent, creates no account/role/facility/global assignment, and leaves an existing disabled permission disabled. It is never run by migrations, seeders or tests. Review the role's existing users/facility assignments before executing it. Missing/inactive roles yield no grant and require operator investigation, not an invented replacement role.

## Read API

All routes require active-account Sanctum Bearer with api ability, then dossiers.view in the requested active facility. Every response is JSON and `Cache-Control: private, no-store; Vary: Authorization`, including failures.

| Method / route | Response |
| --- | --- |
| GET `/api/dossiers` | `{data: DossierRow[], meta: {page,per_page,total,last_page}, totals: {dossiers}}` |
| GET `/api/dossiers/{dossier}` | `{data: Dossier}` including current patient fields, persistent oncology profile and latest visit |
| GET `/api/dossiers/{dossier}/visits` | Paginated visit id/no/actual-date list |
| GET `/api/dossiers/{dossier}/visits/{visit}` | `{data: Visit}` for an explicitly linked visit of that dossier/facility/patient |

Numeric path constraints and corresponding explicit Next `/hospital-api` rewrites. No wildcard proxy, report routes or writes. `facility_id` is required. List query: search (≤200), oncology=yes/no, visits=with/without, from/to (opening dates), sort=code/patient_name/opening_date/visit_count, direction=asc/desc, page≥1, per_page=10/20/50/100. Default opening_date DESC with id DESC tie break; each row's visible number is pagination offset + position. All filters/search are applied before count/pagination; each dossier occurs once.

Search matches dossier code, patient code and current first/family full name. Whitespace is normalized; bound LIKE values use explicit `ESCAPE '!'` so `%`, `_`, `!` and backslash remain literal. Partial code matching may correctly match P1 and P10. No identity matching/merging by names or phone.

Only active dossiers and their **complete, nonvoided, nonfuture** visits represent available actual care. Latest visit is `visit_date DESC, id DESC`, independent of created_at. The same predicate governs counts/history. Existing unlinked visits are not attributed automatically. Diagnoses are equal peers, each with its own recorded clinic/doctor; unknown dates remain unknown. The older visit-level clinic/attending doctor is separately labelled and never assigned to every diagnosis. Historical inactive directory names remain visible for stored records.

Latest/selected visit includes nonvoided stored diagnoses, services, procedures, medications, administered dose-session items and outcomes, up to facility today. No fabricated prescription, primary diagnosis, discharge, attachment or date. Details/history use separate request identities and existing AbortController handling; prior results cannot cross record, facility or session. List retains same-context prior rows while clearly loading/failed; search/history use the proven shared URL/debounce logic. Filter/pagination/list context survives detail/back navigation.

Errors: 401 missing/invalid token; 403 missing api ability, inactive account or dossier/facility access denied; 404 dossier/visit not available; 405 unsupported write; 422 invalid query; 500 DOSSIERS_UNAVAILABLE with safe Arabic text and normal exception reporting. Swagger documents all four endpoints at the existing `/docs/api` and `/docs/api.json`.

## Deferred operations and operational limitation

The Add dossier button is disabled with “ستتاح إضافة الإضبارة في المرحلة التالية.” It opens nothing and sends no write. No create/edit/visit/diagnosis/service/procedure/medication/outcome/upload/report flow exists. **A fresh installation correctly shows an empty dossier list.** Existing patient.paper_file_number, visits.paper_reference and cancer case numbers require a separate reviewed legacy-mapping operation; do not run a guessed backfill. Existing visits remain available to their prior consumers and catalog statistics; they appear in a dossier only after a future authorized explicit link.

Future wizard contract: full-page arrow steps (personal → general/oncology → diagnoses → services/procedures → medications/outcome → attachments/review). Explicit first save selects/creates patient and draft dossier with code/date; diagnosis save creates the draft visit. Opening alone creates nothing. Future save/continue/draft/resume/back actions must retain later steps, use durable request UUIDs/optimistic versions and transactionally audit all changes. No transient drafts or draft APIs are implemented now. Clinical-event/reporting-period write requirements remain for future workflow design.

Reports deferred: filtered dossier list PDF/XLSX, individual/full-history dossier, visit list and individual visit. No unfinished report buttons.

## Deployment instructions — not executed on production

After normal backup, schema-history review and maintenance approval, on an already running installation:

```sh
cd backend
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=DossierPermissionsSeeder --force
# Operator separately reviews/applies database/sql/dossier-view-permission.sql if that role grant is authorized.
php artisan config:cache
php artisan route:cache
php artisan view:cache
cd ../frontend
npm ci
# LARAVEL_API_URL must point at the approved Laravel /api endpoint when building.
npm run build
```

Before starting the new standalone process, copy `public/` into `.next/standalone/public/` and `.next/static/` into `.next/standalone/.next/static/`, preserving the `chunks/`, `media/` and other subdirectories. Create destination directories first; verify a generated `/_next/static/chunks/...` URL returns 200. Restart approved app processes through the normal release procedure. No new environment variable, service or dependency. Do not use migrate:fresh, testing fixtures, grant SQL blindly or a guessed legacy import on production. The permission and verified legacy-data plan are prerequisites to useful populated access; this phase intentionally cannot create a dossier through the UI.

## Verification

Testing uses only existing isolated `blood_bank_cities_testing`, mysql driver, MariaDB 10.11.18 at loopback 127.0.0.1:13416, after TestDatabaseSafety. No SQLite, production connection or database creation/drop. RefreshDatabase/DDL suites run separately from live HTTP fixtures.

```sh
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --filter='DossierApiTest|DossierMigrationTest'
php artisan test --env=testing --filter='DirectoryLifecycleTest|CatalogApiTest|BloodEventsTest'
php tests/Support/explain-dossiers.php
./vendor/bin/pint --dirty --test
# After a new frontend production standalone build with local test Laravel:
node --test tests/dossiers-live.test.mjs
node --test tests/app-shell.test.mjs
npx tsc --noEmit
npm run lint
npm run build
git diff --check
```

Live tests use Next → Laravel → MariaDB for read/scope/no-write/navigation checks. Delayed/error captures delay delivery of, or throw a transport error after, a real Laravel response; they do not fabricate API payloads. The existing shell regression suite separately uses its original API mocks to isolate unchanged shell behavior and is not claimed as real integration. Synthetic screenshots are linked in [the committed review gallery](../../frontend/docs/reviews/dossiers-phase-one/README.md).

Measured on 2026-09-14:

| Check | Actual result |
| --- | --- |
| `test-db:check --connect --env=testing` | PASS, mysql / MariaDB 10.11.18, isolated database above |
| `DossierApiTest` + `DossierMigrationTest` | 8 PASS, 171 assertions; fresh rollback, populated pre-phase upgrade, data preservation and refusal before destructive rollback |
| Final `DossierApiTest` after OpenAPI and optional date-bound checks | 7 PASS, 164 assertions |
| `DirectoryLifecycleTest`, `CatalogApiTest`, `BloodEventsTest` | 49 PASS, 3064 assertions; doctor/clinic history and catalog/blood semantics preserved |
| `dossiers-live.test.mjs` | 3 PASS, real Next standalone → Laravel → MariaDB; 0 skipped |
| `app-shell.test.mjs` | 8 PASS, original mocked shell harness; 0 skipped |
| TypeScript `tsc --noEmit` | PASS |
| ESLint | PASS |
| Next production build | PASS; new dossier routes generated and served by the rebuilt standalone |
| Pint, all changed/new PHP | PASS |
| Laravel route lists | Four protected dossier GET routes, two existing Swagger routes |
| `git diff --check` | PASS |
| Chromium visual review | 390/768/1440px; populated/empty/loading/error/no-visits, oncology, previous visit, unavailable Add; committed synthetic gallery |

No required verification remains blocked. Intermediate failures were corrected: an incomplete synthetic outcome fixture needed its required deciding doctor; the standalone test setup initially copied static assets without preserving their directory layout and returned 404 for JS/CSS. After copying into existing destination directories and restarting the owned local process, the full live and shell suites passed. The migration suite rolls its schema back on teardown; running the plan/live tools immediately afterward requires a guarded testing migration (or the normal API suite which prepares it). No production environment was used for any check.

### Query plans and expected growth

`tests/Support/explain-dossiers.php` inserts 6,000 additional patients/dossiers and one explicitly linked visit per additional dossier inside a rollback-only transaction. With the base fixture, 6,010 dossiers were measured. Default page: 20 returned / 6,010 total, 3 queries, **13.73ms**. Normalized substring: 20 returned / 6,000 matching, 3 queries, **19.01ms**. These are single local samples, not a production throughput guarantee.

MariaDB EXPLAIN selected `dossiers_listing_index` for the paged outer query, patients PRIMARY `eq_ref`, and `visits_dossier_chronology_index` for both correlated latest/count subqueries (estimated four rows per reference). Diagnoses are fetched in one page-batched query. The exact total query scanned the 6,010 dossier rows in this single-facility fixture; arbitrary leading-wildcard name/code matching cannot be solved by a B-tree. Large accumulated histories, deep pagination and sorting by computed visit count still require monitoring with representative deployment volume. No N+1 child loading or unrestricted browser fetch is used. Re-run the supplied plan script on an isolated representative test dataset when evaluating further indexing/search work.
