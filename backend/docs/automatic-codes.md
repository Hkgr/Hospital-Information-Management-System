# Automatic internal codes and retry-safe creation

Current contract (supersedes manual-code examples in older phase documents): clients do not supply or change internal codes. Existing codes are never renumbered. Creation is an explicit operation; previews, opening a form and import validation issue no code.

## Coverage and exceptions

| Actual create path / entity | Code policy | Uniqueness / retry reservation |
| --- | --- | --- |
| Patient card, reception, new patient import | Existing `PC-…` generator, only during the personal writer transaction | Global canonical identity and legacy aliases; existing dossier/import ledgers |
| Visit | Existing `V-…` sequence | Global; dossier UUID and import-source ownership unchanged |
| Doctors; quick doctor through `POST /api/doctors` | `AUTO-DR-…` | Global staff code; directory creation ledger |
| Clinics | `AUTO-CLI-…` | DB unique within facility; global issuer is deliberately stricter |
| Services / procedures / medications | `AUTO-SER-…` / `AUTO-PRO-…` / `AUTO-MED-…` | Each global directory; directory creation ledger |
| Service / procedure / medication classifications | `AUTO-SCG-…` / `AUTO-PRT-…` / `AUTO-MCG-…` | Each global classification directory; directory creation ledger |
| Quick diagnosis / medication | `AUTO-DOS-DX-…` / same `AUTO-MED-…` issuer | Existing dossier UUID ledger and explicit global permission |
| Stock suppliers / stores | `AUTO-SUP-…` / `AUTO-STR-…` | Facility uniqueness; global monotonically increasing issuer |
| Medication receipt | `AUTO-RCV-…` | Facility uniqueness; existing stock UUID ledger; immutable old numbers |
| Blood-bank person/event codes, donation date aliases, oncology plan codes, report numbers | Existing generators, unchanged | Existing domain transactions and historical meaning unchanged |
| Import `patient_code`, `legacy_code`, `paper_file_number` | Match existing identity / preserve source identifiers | Never replaced by a new source identifier; source ownership still enforced |
| Invoice number, manufacturer batch, license, external report/paper identifiers | External values remain literal, including leading zeros | No generated substitute or inferred conversion; receipt `invoice_number` is the separate external reference |
| Roles, permissions, staff types, outcomes, diagnosis/reference catalog seed codes | Fixed semantic/reference codes | Not converted to sequential identifiers; `AUTO-` is reserved for runtime issuance |

Existing legacy/manual receipt numbers retain their value and historical interpretation; no backfill or inference is performed. New receipts expose their generated number in the saved record. Updating a draft receipt cannot change that number.

## Atomic retry contract

Directory creates require `request_id` (UUID) with `facility_id` and the normal fields. Updates prohibit request_id and retain their existing lock_version contract. `code` is prohibited; receipts prohibit `receipt_no`. Permissions, active account, Sanctum ability and facility checks remain in front of replay. No new permissions or grants are introduced.

The new `directory_creation_requests` ledger has a unique `(facility_id,user_id,request_id)` key. Its canonical payload fingerprint includes operation/kind. A transaction locks the reservation, issues the code, saves the entity/links/audit, then stores its ID. Identical replay returns that ID without incrementing codes, creating links or repeating audit. Different content returns `409 CREATION_REQUEST_CONFLICT`. Existing quick entries use `DOSSIER_VERSION_CONFLICT`, receipts `STOCK_REQUEST_CONFLICT`. Deleted entities retain their reservation; replay must not recreate them (the normal read returns not found).

`number_sequences` locks serialize issuance, including concurrent requests with different UUIDs. Collision checks include archived rows. Sequences are not reset on deletion. A transaction failure rolls back both the reservation and counter. Do not purge these ledgers or reset counters as routine maintenance.

The frontend keeps an immutable payload/UUID for uncertain network/server responses. An edited draft cannot silently become another creation; “استعادة نتيجة الحفظ” explicitly resends the original operation. It does not apply later draft edits automatically. InlineDoctor uses this same recovery in DoctorEditor, not a name search. Once the saved doctor ID is known, failed option refresh/link verification keeps that identity and offers the existing linkage recovery. Known validation rejection permits correction; the server has committed no reservation. Drafts stay local to the open form/session; this does not introduce offline draft persistence.

## Reference seeding

New runtime prefixes are disjoint from `DR-*`, `CLI-*`, `DOS-DX-*`, `SER-*`, etc., even before reference seeders run. Both diagnosis reference seeding and missing-reference seeding reject code/identity collisions; MissingReferenceSeeder now rolls back all inserts/links when a known reference identity differs. Only its explicitly listed link pairs may be backdated; unrelated assignments are untouched.

MissingReferenceSeeder also consumes legacy references whose authoritative names are absent from the repository. Before running that **optional** seeder, the operator must review those identities against the approved reference source and place a private, uncommitted UTF-8 JSON file at `backend/storage/app/private/reference-identities.json`:

```json
{
  "staff": { "DR-003": "reviewed full_name", "DR-005": "reviewed full_name", "DR-006": "reviewed full_name", "DR-007": "reviewed full_name", "DR-008": "reviewed full_name", "DR-009": "reviewed full_name", "DR-010": "reviewed full_name", "DR-011": "reviewed full_name" },
  "clinics": { "CLI-001": "reviewed name_ar", "CLI-002": "reviewed name_ar" },
  "services": { "SER-2-001": "reviewed name_ar" },
  "procedures": { "PRO-006": "reviewed name_ar" }
}
```

The labels above are placeholders, not hospital data. Do not blindly generate this file from current rows: that would attest to the very collision being checked. Missing/mismatched entries stop before changes. The file is not required for normal app use or this migration. Repeated successful seeding preserves existing IDs, activity and unrelated fields. No production seeder was executed for this change.

## Coordinated deployment (operator only)

1. Deploy backend and rebuilt frontend together: old clients supplying codes or omitting UUIDs now get 422. Drain outstanding writes first; do not run a mixed frontend/backend version.
2. From backend: `php artisan migrate --force`. The only new migration is `2026_09_28_200000_directory_creation_requests.php`; it is additive and does not renumber/backfill existing records. No general seeder is required.
3. Rebuild frontend with the correct `LARAVEL_API_URL`: `npm ci` then `npm run build`; package public and `.next/static` with standalone as in the existing deployment procedure. Restart the application through the normal operator procedure.
4. Verify an authorized synthetic create/replay returns one ID/code; confirm ordinary update retains the code. Never use real patients to probe creation.
5. Application rollback must preserve the new table and `number_sequences`. Migration down refuses to discard any replay history; an unused empty table can be removed. Do not force a downgrade that drops reservations.

## Verification

Use only a separately authorized MySQL/MariaDB testing database and `php artisan test-db:check --connect --env=testing`. The preserved-database bootstrap refuses pending migrations and unexpected transaction loss; it never refreshes a populated database.

Focused regressions proved before-fix failures for new-patient import validation, duplicate creation on identical retry, and silent reference-code collision. Functional coverage includes unchanged source identifiers, import ownership, all ten new directory reservation paths, revoked permissions, receipt replay/legacy number preservation, reference-seeder order, counter rollback and deletion non-reuse. Concurrent PHP workers use a readiness barrier and actual MariaDB transactions, not mocked persistence.

`frontend/tests/automatic-codes-live.test.mjs` uses an explicitly local fresh standalone build, actual Laravel and guarded MariaDB. It covers thirteen creation contracts, replay/changed payload/denied access, browser doctor creation with a clinic link, and browser receipt creation preserving the manufacturer's batch number. It contains no page.route or API mocks. The doctor lost-response regression and existing directory UI suites use interception intentionally and are reported separately.

The fourth live case deliberately discards the **real committed** doctor response, then fails one options delivery in the browser. Recovery sends the original UUID/payload, selects the saved doctor by ID, and preserves the parent blood-bank name/quantity draft. These are transport fault injections; no successful API body is fabricated.

Verified locally on MariaDB 10.11.18, Laravel mysql driver, isolated `blood_bank_cities_testing` at `127.0.0.1:13416`:

| Check | Actual result |
| --- | --- |
| `php artisan test-db:check --connect --env=testing` | Passed before the additive migration/tests |
| `php artisan migrate --path=database/migrations/2026_09_28_200000_directory_creation_requests.php --env=testing --force` | Passed; populated test data retained |
| Laravel affected suites, command below | 129 passed, 5624 assertions; no skipped cases |
| `node --test --test-concurrency=1 tests/doctors.test.mjs tests/clinics.test.mjs tests/catalog.test.mjs tests/stock.test.mjs` | 58 passed, no skips; mocked API UI suite |
| `node --test tests/automatic-codes-live.test.mjs` | 4 passed, no skips; real Next/Laravel/MariaDB, including explicit transport faults |
| `npx tsc --noEmit` | Passed |
| ESLint for all nine modified frontend implementation files | Passed without warnings |
| `npm run build` | Passed; standalone rebuilt and used for the live checks |
| `php vendor/bin/pint --dirty --test` | Passed |
| `git diff --check` | Passed |

```bash
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter='AutomaticCodes|StockReceiptConcurrencyTest|DossierImportTest|MissingReferenceSeederTest|ClinicApiTest|DoctorApiTest|CatalogApiTest|CatalogWorkflowTest|MedicationStockTest|DossierWorkflowTest|DossierCompletionTest' --compact
```

Initial runs exposed outdated prefix/request fixtures and global-count assertions against preserved synthetic history. Those assertions now target their own fixture scope. The Windows concurrency harness initially failed its stdout readiness check; explicit ready files and a bounded release barrier fixed the harness before the actual concurrent test passed. The full repository suite, production, external integrations and optional operator reference data were not tested. No requested affected check remains blocked. No database reset, production access, FastAPI change or deployment was performed.
