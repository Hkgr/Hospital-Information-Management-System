# Patient dossiers — Phase 3

This extends the existing six-step arrow wizard. Patient identity and persistent medical/oncology data remain dossier-level; diagnoses, services, procedures, prescriptions, outcomes and private attachments belong to a selected visit. No completed-visit correction lifecycle is introduced: completed visits are read-only.

See [schema assessment](patient-dossiers-phase-three-assessment.md) for the inspected baseline and approved mapping. Phase 1 chronology (linked nonvoid draft/complete visits through the actual facility-local date, ordered actual date then ID) and Phase 2 identity contracts remain in effect.

## Storage and history

- `2026_09_18_000001_extend_dossier_visit_workflow.php`: extends per-visit section progress and initial/subsequent identity; makes service/procedure/outcome periods nullable without changing existing links; adds explicit clinical clinic scope; adds prescription header/items and private attachment metadata. Composite FKs and generated unique keys protect scope and one active prescription/outcome.
- `2026_09_18_000002_add_dossier_upload_reservations.php`: actor-owned, expiring upload reservations, scoped to dossier/visit/facility. Pending unexpired uploads block review/completion.
- `visit_services` and `visit_procedures` are reused. `dossier_managed` rows derive `performed_on` from the visit date; date corrections revalidate contexts, propagate dates and audit atomically. Pre-existing unmanaged rows with no recorded clinic retain their independent historical date contract; editing them explicitly requires a clinic/doctor and brings them under the new invariant. No clinic is inferred for legacy data. Such incomplete contexts must be reviewed before finalization.
- `visit_prescriptions` is one current header per visit with multiple `visit_prescription_items`. The header owns the explicitly selected prescribing clinic/doctor, visible `prescribed_on`, note, request UUID, actors, versions and void metadata. Each item keeps an immutable code/name snapshot for its selected medication. An explicit medication replacement writes new snapshots with old/new audit values; renaming the directory never changes unchanged saved items.
- Prescriptions are **not dispensing**. This workflow never writes `visit_medications`, stock, billing or administration records. Prescription/outcome dates remain explicit; changing visit date never silently substitutes them.
- An omitted saved row remains. Voiding needs row ID, current version, actor and a nonempty reason. Binaries and voided clinical history are retained. No hard delete is exposed.
- The exact result codes are `DOS-RX`, `DOS-NORX`, `DOS-STUDY`, `DOS-REFER`, `DOS-DEATH`. Outgoing referral destination/date/reason are separate from incoming visit referral fields; changing to another result clears current outgoing fields and preserves their old values in audit.

## Workflow and HTTP contract

Every endpoint is under `/api/dossiers`, reached through the specific `/hospital-api/dossiers` Next rewrites. Sanctum Bearer, `api` ability, active account, explicit active facility and `dossiers.view` are required. Responses are `private, no-store`; denied scope is never silently replaced. OpenAPI at `/docs/api` and `/docs/api.json` describes requests/responses.

| Operation | Endpoint suffix | Additional permission |
| --- | --- | --- |
| New medication definition | `POST /medications` | global `medications.create` |
| Scoped directory choices | `GET /options/services`, `/procedures`, `/medications`, `/outcomes` | view |
| New subsequent-visit snapshot | `GET /{d}/visits/new` | `dossiers.visits.create` |
| Create subsequent draft | `POST /{d}/visits/subsequent` | `dossiers.visits.create` |
| Resume selected visit | `GET /{d}/visits/{v}/progress` | view |
| Save occurrences / prescription and outcome | `PUT /{d}/visits/{v}/clinical`, `/medications` | `dossiers.clinical.update` |
| Confirm saved review | `POST /{d}/visits/{v}/review` | `dossiers.visits.update` or `.complete` |
| Complete selected visit | `POST /{d}/visits/{v}/complete` | `dossiers.visits.complete`, plus `dossiers.finalize` for initial activation |
| Reserve / upload / cancel file | `POST /{d}/visits/{v}/uploads`, `/uploads/{u}`, `/uploads/{u}/cancel` | `dossiers.attachments.upload` |
| Paginated metadata | `GET /{d}/attachments` | `dossiers.attachments.view` |
| Private streamed download | `GET /{d}/visits/{v}/attachments/{a}/download` | `dossiers.attachments.download` |
| Void attachment | `POST /{d}/visits/{v}/attachments/{a}/void` | `dossiers.attachments.void` |
| List / dossier / visit report | `POST /export/{format}`, `/{d}/report/{format}`, `/{d}/visits/{v}/report/{format}` | `dossiers.export` |

JSON writes carry `facility_id`, `request_id` UUID and the current `lock_version`; saved child rows also carry their versions. UUID replay resolves the original saved entity without performing the write twice; the response reloads its current authorized snapshot. A UUID with changed content is 409. Version conflicts are 409; the UI retains the draft and requires fetching current data and choosing individual changes before another explicit save. Validation is 422 with field errors and focus on the first invalid field. Unauthenticated/forbidden requests return JSON 401/403; unexpected errors are reported through Laravel and sanitized.

Clinical requests contain `services` and `procedures` arrays. A row has `catalog_id`, `clinic_id`, `doctor_id`, optional `note`, and ID/version for updates. Its date is the visit date. Medication requests contain nullable `prescription` and `outcome`: null preserves an existing row; explicit removal uses `remove` and `void_reason`. New medication directory creation is a separate explicit UUID action, normalized code/name duplicate rejection, and never a prescription-save side effect.

Finalization requires all six applicable sections explicitly saved/reviewed, a diagnosis, one supported outcome, valid clinic/doctor membership at the visit date, no pending upload and explicit confirmation. Final review selects the attending clinic/doctor required by the existing completed-visit CHECK; it never infers them from diagnoses. Initial activation and completion are atomic with separate audit records. Subsequent completion affects only the chosen visit and leaves dossier activation unchanged. Opening any wizard/review/lookup creates nothing.

## Private attachments

The `dossier_private` local disk defaults to `storage/app/dossier-private`, outside public/executable paths, with direct serving disabled. Back up this directory together with the database. Ensure the application account can write it; never symlink it into `public`.

`DOSSIER_ATTACHMENT_MAX_KB` defaults to 10240 (10 MiB) per file. Configure PHP `upload_max_filesize` and `post_max_size`, and the reverse proxy body limit, consistently. Content is checked using `fileinfo`, image dimensions, and supported Excel-reader identification; XLSX archive size/path/macro checks run before identification. PDF/XLS/XLSX/JPEG/PNG/WebP are allowed; SVG/HTML, traversal and multiple-extension filenames are rejected. No malware scanner is configured or claimed.

The client reserves an upload with title/original filename and then posts multipart `file` with the same facility query and Bearer header. Retries verify identical hash/size. Files are staged under generated UUID keys and finalized with metadata in a transaction; failed writes clean staged/uncommitted files. Downloads stream with attachment disposition and `nosniff`; keys and physical paths never appear in public metadata.

`php artisan dossiers:cleanup-uploads` is a dry run. The separately reviewed `--apply` removes only UUID-named abandoned files older than 24 hours that have no attachment metadata, including preservation of voided metadata/binaries. This is not a patient-record retention policy. Expired reservations no longer block completion; a retry after expiry requires a new reservation.

After reviewing the dry run, the operator can add this example to the application account's crontab (replace `/srv/hospital/backend`, PHP binary and log path with the approved installation paths):

```cron
15 3 * * * cd /srv/hospital/backend && /usr/bin/flock -n storage/framework/dossier-cleanup.lock /usr/bin/php artisan dossiers:cleanup-uploads --apply --no-interaction >> storage/logs/dossier-cleanup.log 2>&1
```

This schedules cleanup once daily and prevents overlapping runs. Give that account access to the private disk and lock/log directories. Keep logs under the site's existing rotation policy. The command uses that installation's operator-managed environment. No credentials belong in cron. This PR does not install a cron entry, modify an external scheduler or delete recorded/voided attachment binaries.

## Reports

PDF uses the existing shared section-report template and locally embedded Cairo. Excel uses the existing measured spreadsheet layout and continuation sheets, explicit safe text/code/date/numeric types, RTL, frozen headers and filters. No attachment binaries are embedded. Private attachment metadata additionally needs `.attachments.view`.

Every Excel sheet has an explicit A4 print area, fit-to-width 1 and unrestricted page height, repeated table headers, a Cairo page header and numbered footer. The shared layout reserves 0.5-inch top/bottom margins and separate header/footer space. Opening/birth dates and attachment timestamps use numeric Excel date cells with explicit formats; identifiers, phone numbers and user text remain literal strings. To reopen all three downloaded review workbooks with PhpSpreadsheet, without a database connection, run `php tests/Support/verify-dossier-workbooks.php` from a development `backend` checkout with Composer dev dependencies (optionally pass another sample directory). The regression suite additionally checks adversarial formula-like strings, leading zeros and long Unicode content. Install the project's Cairo font on the spreadsheet printing workstation; XLSX references the font rather than embedding it.

List exports use authoritative server filters and selected columns across **all** matching rows; the visible page is not the export boundary. UI readiness requires the current table request to succeed; pending/debounced/failed requests disable exporting, including inside the handler. Limits are 1000 dossier rows and 5000 detail event rows (`config/dossiers.php`); exceeding them returns 422 without a partial report. Dossier/visit reports are independent of table readiness and distinguish current identity from historical visit facts. Draft records are marked `مسودة — غير مكتملة`. Generation allocates an audited report number via POST.

## Operator actions (not executed on production)

After the usual reviewed backup and deployment procedure:

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=DossierCompletionPermissionsSeeder --force
php artisan db:seed --class=DossierOutcomeSeeder --force
php artisan optimize
```

Build the frontend using the operator-configured internal Laravel URL: `npm ci` followed by `npm run build`. Copy `public` and `.next/static` into the standalone package before starting it, following the existing service/deployment mechanism. Do not copy test environment variables or fixtures to production. These instructions are for the operator; no deployment was performed here.

Seeders define records idempotently and never grant permissions or reactivate disabled definitions. Review [dossier-completion-permissions.sql](../database/sql/dossier-completion-permissions.sql) against the explicitly selected database. It only links required permissions to the existing active `super_admin`; it creates no roles, users, global assignments or facility assignments. Facility permissions use existing facility-role assignments. `medications.create`, existing `diagnoses.create` and patient directory operations require an existing explicit global assignment.

Rollback is only possible before these additions contain data. Review migration status/batches first; roll back reservation migration before workflow migration using Laravel's targeted `--path` workflow. Both preflight and refuse before destructive DDL when relevant new data exists. They never fabricate periods, delete clinical facts or force NOT NULL over new null-period data. After use, keep the additive schema and prepare a separately reviewed forward correction instead of forcing rollback.

## Verification environment and evidence

Use only the existing isolated MariaDB database, configured through testing environment variables or `.env.testing`. Run `php artisan test-db:check --connect --env=testing` before migrations/tests; the guard checks `APP_ENV=testing`, mysql, an explicitly confirmed test name and host. Never use default `.env`, production, or SQLite.

Local validation used driver `mysql`, MariaDB **10.11.18**, database `blood_bank_cities_testing` at `127.0.0.1:13416`. Credentials are not committed. Migration tests exercised populated upgrades, safe unused rollback and rollback refusal; three migration suites passed (29 assertions).

Final release verification ran the **complete Laravel suite**: **212 passed, 2 failed / 14147 assertions / 1040.48s**. One failure was an obsolete blood-bank migration-test assumption that visits still require periods; the test now asserts that the migration preserves the installed visit schema. The other was a missing subprocess startup signal in the pre-existing doctor concurrency test. No directory code or concurrency test was changed. A focused rerun of both migration/concurrency checks plus the new release tests passed **6 tests / 5304 assertions / 207.27s**, including both doctors and clinics. The initial full run is not represented as a green run.

After finalizing the shared print header/footer, the dossier release, DirectoryReportTest, BloodBankReportsTest and CatalogApiTest suites passed **34 tests / 7991 assertions / 50.36s**. New tests reopen list/dossier/visit workbooks, compare every document cell's value and type, check page properties and private payload exclusion, exercise cleanup dry-run/apply on aged synthetic files, and preserve both active and voided recorded attachments. Before the fix, these tests exposed a missing Cairo page header and an over-permissive generated-file pattern; an initial public-path assertion was also corrected to accept either 403 or 404 (both deny access).

The complete relevant doctor/clinic/Catalog/export-readiness/lifecycle browser suites passed **86 tests, no failures or skips / 114.58s**. Shared authentication/AppShell/dashboard UI suites passed **22 tests**, with the opt-in real-auth test initially skipped; the real dossier suites and opt-in authentication result are recorded below. TypeScript, ESLint and a fresh Next 16.3.4 production standalone build passed. No frontend application behavior was changed for this release verification.

All four real dossier browser suites ran together: **18 passed, 1 failed, 0 skipped / 406.42s**. The Phase 1 Back assertion read the old visit-code search box before client navigation completed. It now waits for the dossier list and verifies its specifically named search box and URL parameter. The entire Phase 1 suite rerun passed **4 tests, 0 failures/skips / 103.67s**. Phase 2 workflow (4), Phase 2 review (4) and Phase 3 (7) all passed in the combined run. These runs cover all 19 dossier tests using actual Next/Laravel/MariaDB, without mocked API bodies. The opt-in real login/user/logout/token-revocation check then passed **1 test / 9.66s**, resolving the earlier opt-in skip. Test-only changes keep old galleries from being overwritten and align the former Phase 2 save-button selector with the accepted Phase 3 label.

Fresh downloaded XLSX artifacts passed the read-only PhpSpreadsheet command: **1/12/12 worksheets (25 total)**. Fresh server-generated PDFs rendered to **1/3/3 pages** in PDF.js 6.3.289 and all seven pages were visually inspected without clipping, blank overflow or overlapping content in these samples. Compressed font objects confirm embedded Cairo Regular/Bold. These PDF renders are not LibreOffice/Excel conversions. Existing accepted UI screenshots were retained; real viewport/overflow assertions ran again at 390/768/1440px.

Final `php vendor/bin/pint --dirty --test`, `git diff --check` and staged diff checks passed. TypeScript and lint were rerun successfully after the test-selector changes; frontend application source and its verified production build remained unchanged.

Unsaved input triggers link-leaving and `beforeunload` confirmation. Browsers implementing the Navigation API also receive a cancellable Back/Forward warning for same-document traversal; this behavior is verified in the bundled Chromium. Equivalent SPA-history cancellation is not claimed for older browsers without that API.

The live suite is `frontend/tests/dossier-completion-live.test.mjs`, using the guarded `backend/tests/Support/dossier-completion-live.php` fixture and `dossier-server.php`. Build with `LARAVEL_API_URL=http://127.0.0.1:8194/api`, copy static/public assets into standalone **before starting it**, then run on loopback port 3194. Fixture credentials remain in ignored local storage and are revoked in teardown. Never run destructive database suites concurrently with live fixtures.

Synthetic [screenshots and actual report samples](../../frontend/docs/reviews/dossiers-phase-three/) are review artifacts, not patient data. No production access, FastAPI/authentication/AppShell changes, merge or deployment are part of this work.

LibreOffice headless is **not installed** (neither PATH nor the standard Windows installation paths contain it), so no XLSX-to-PDF rendering or visual print-preview success is claimed. Automated PhpSpreadsheet print-property assertions are the accepted verification in this environment. The previously observed Microsoft Excel 16.0 build 20326 Printer Setup / 0x800A03EC error is a local printer-environment limitation, not an application failure or a remaining non-environment release check. No printer configuration or server scheduler was changed. PDF.js rendering of server-generated PDF reports is separate from Excel print rendering.
