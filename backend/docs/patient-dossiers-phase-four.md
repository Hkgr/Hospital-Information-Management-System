> Current identity/registration contract: [Unified Patient Cards](patient-card-correction.md). This phase record describes the earlier implementation; the correction supersedes separate dossier codes, delayed initial-visit creation, and per-facility card identity. Medical progress/activation remain facility-local.

# Patient dossiers — Phase 4 closure

Built from `develop` at `cd00240ce2586023f11714d022890bee72812907`, after PR #22 was merged. This phase adds a read-only audit view and extends the existing individual dossier report. It does not change the wizard, completion/write contracts, AppShell, authentication, other clinical modules or FastAPI.

## Assessment and scope

Existing dossier writers already record authoritative `audit_logs` through `DossierWrites`/`ClinicAudit`. Catalog audit is catalog-scoped and cannot safely serve a dossier; no existing dossier audit permission or endpoint existed. `DossierAuditHistory` resolves ownership from the persisted dossier, patient, visit and child records, never from an `old_values`/`new_values` claim. No audit rows or clinical snapshots are copied into another table.

The previous individual dossier report excluded all voided facts. Its existing `POST /api/dossiers/{dossier}/report/{pdf|xlsx}` now supplies **تاريخ بطاقة المريض الكامل**, using the same Cairo/RTL sections, spreadsheet continuation layout and PDF renderer. List and individual-visit exports keep their previous scopes and endpoints.

## Audit contract and authorization

`GET /api/dossiers/{dossier}/audit` requires Sanctum Bearer `api`, an active account, an active explicitly selected facility, and **both** `dossiers.view` and `dossiers.audit`. Unauthorized users receive JSON 403 `DOSSIER_ACCESS_DENIED`, without entries or totals. The page offers an enabled history link only with audit authority. Server-side authorization remains authoritative. Attachment/upload events, filter choices and totals additionally require `dossiers.attachments.view`.

Query parameters:

| Parameter | Meaning |
| --- | --- |
| `facility_id` | Required authorized facility; no silent fallback |
| `from`, `to` | Optional inclusive local calendar days of the **audit timestamp**, in facility timezone |
| `visit_id` | Optional visit actually linked to this dossier/patient/facility; foreign reference returns 404 |
| `entity` | One key from the response's permitted `filters.entities` |
| `action` | `created`, `updated`, `activated`, `completed`, `reviewed`, `voided`, `started`, `uploaded`, `cancelled` |
| `page`, `per_page` | Default 1/10; sizes 10, 20, 50 or 100 |

Response: `data`, `meta {page, per_page, total, last_page}`, allowed filter dictionaries and `timezone`. Each event contains timestamp, actor display name, optional visit code/actual date, Arabic section/action, changed fields (`label`, `before`, `after`, `before_recorded`) and recorded reason. Order is `occurred_at DESC, id DESC`. Totals cover all matches before pagination. `saved` normalizes to creation/update from the stored prior snapshot; recorded void metadata produces the void action.

Only allowlisted public fields are projected. Omitted fields in a partial new snapshot are preserved, not represented as deletions. `before_recorded=false` explicitly means no old value was recorded. Unknown legacy details are not reconstructed. Medication item names/codes use their stored immutable snapshots. Other reference IDs resolve in batches to **explicitly labelled current directory names**, so renamed directories are not misrepresented as historical names. No existing safe general actor-search directory was available; actor search was deliberately omitted. No passwords, tokens, raw requests, internal paths, private storage keys or attachment binaries are serialized.

The UI uses existing directory cards, tables, filters, pagination and long-text controls. It lives on the dossier page, outside wizard steps. Date/entity/action/page parameters use `audit_` URL keys; an event's “تغييرات هذه الزيارة” action selects its visit without loading all visits into a dropdown. Existing list and visit URL state is preserved. Shared request identity, cancellation and session/facility keys prevent late replies restoring old entries. Static field labels keep filters usable while records load; previous event data is hidden on a new request or error.

## Historical report definition

Requires `dossiers.export`; attachment metadata additionally requires `dossiers.attachments.view`. An audit permission is not substituted for either permission.

Optional `from`/`to` on the individual dossier export select **actual `visits.visit_date`**, inclusively, through the facility's current date. Explicitly linked draft, complete and `void` visits are included with their state and stored void reason. Each fact within a selected visit retains its own event date; unknown diagnosis dates remain unknown. No filtering uses `created_at` as a substitute for a clinical event date. Attachment creation timestamps are labelled upload timestamps only.

The report includes diagnoses, services, procedures, prescriptions/items, outcomes/referrals and authorized attachment metadata, including persisted voided rows. Saved row versions and reasons distinguish historical states. A voided prescription header marks its items as voided in the report; it never becomes medication dispensing. Existing actual dispensing/administration records remain separate sections. Patient/medical identity sections are explicitly **current data**. The export does not fabricate prior snapshots: previously corrected values remain in the permission-gated change history. No completed-record correction workflow is introduced.

The existing 1000-list/5000-detail limits still reject oversized exports with 422 rather than silently truncate. Reports keep explicit text/date/numeric types, formula-injection protection, private headers and no embedded private files. The optional UI report range has separate `report_from`/`report_to` keys and never changes list filters or the selected visit.

## Schema and performance

**No migration.** Existing `audit_logs` indexes cover `(entity_type, entity_id, occurred_at)` and `(facility_id, occurred_at)`. Child IDs are primary keys; existing visit and composite scope indexes remain intact. A union of uniquely owned subject identities prevents join multiplication. Count and page read share a transaction; only page actors/visits/reference names are batch loaded. There is no query per event and no browser-side full-history load.

The regression creates 12,000 synthetic audit events (2,000 owned and 10,000 unrelated), checks total and unique page IDs, identical query counts for 20/100-row pages, a maximum of eight statements for that dataset, and selection of an existing audit index via MariaDB EXPLAIN. No missing-index evidence justified DDL. This is a bounded fixture check, not a production performance benchmark.

## Operator deployment and rollback (instructions only)

Use the normal approved backup/release procedure; no production command was executed here. Phase 3 schema and seeders are prerequisites. This phase adds only this definition seeder:

```bash
composer install --no-dev --optimize-autoloader
php artisan db:seed --class=DossierAuditPermissionsSeeder --force
php artisan optimize
```

Review and execute [dossier-audit-permissions.sql](../database/sql/dossier-audit-permissions.sql) in an **explicitly selected** approved database. It links `dossiers.view`/`dossiers.audit` to an already existing active `super_admin`, idempotently. It creates no role, user, global assignment or facility assignment. The seeder defines the permission only, never grants it or reactivates disabled definitions. Review existing facility assignments before granting sensitive history access.

Build Next with the installation's reviewed `LARAVEL_API_URL`: `npm ci` then `npm run build`. The specific numeric-ID `/hospital-api/dossiers/{dossier}/audit` rewrite requires that rebuild. Package `public` and `.next/static` **before** starting standalone. Do not include `.env.testing`, fixture tokens or local test storage in a release.

Rollback: revert this phase's application/frontend release together and rebuild. No schema rollback is needed; leave the unused permission definition/audit data intact. If withdrawing assigned audit access, the operator must review the existing grants before removing only that permission association. Never delete audit or clinical history. Existing private-storage backup, access and cleanup scheduling requirements from [Phase 3](patient-dossiers-phase-three.md#private-attachments) remain unchanged; this phase installs no scheduler.

## Verification and review artifacts

Use only isolated MariaDB with `php artisan test-db:check --connect --env=testing` before any database test. This run uses driver `mysql`, MariaDB 10.11.18, `blood_bank_cities_testing`, loopback `127.0.0.1:13416`; no secrets are recorded here.

Final verification (2026-09-15):

| Check | Actual result |
| --- | --- |
| Test database guard | Passed; explicit isolated mysql/MariaDB context above |
| DossierClosureTest, DossierCompletionTest, DossierCompletionSafetyTest, DossierCompletionIOTest, DossierReleaseVerificationTest | **25 passed, 6117 assertions, 0 failures/skips, 47.40s** after the final report change |
| Five real dossier browser suites together, serial files | **23 passed, 0 failures/skips, 367.95s**; Phase 1 (4), workflow (4), review (4), Phase 3 (7), closure (4) |
| Final closure live rerun after preserving both item/header void reasons | **4 passed, 0 failures/skips, 33.24s** |
| TypeScript / ESLint | Passed |
| Fresh Next 16.3.4 production standalone build | Passed; numeric audit rewrite exercised through Next |
| Pint dirty test / git diff check | Passed |
| Reopened generated full-history XLSX | **12 sheets passed** existing print/RTL/Cairo/type/formula assertions, historical reasons and private-payload checks |
| Generated PDF | **3 pages**, rendered in PDF.js 6.3.289 and visually inspected; embedded Cairo Regular/Bold confirmed in compressed font objects |

The full repository suite was not run (optional for this deliberately narrow phase). No unrelated failing tests were found in the selected suites. Initial new-test fixture errors were corrected to match required audit UUIDs, actual medication route and the visit void CHECK; the first standalone browser attempt was blocked by omitted static files in the local package. Packaging was corrected before the passing runs. Filters received explicit accessible names and retain their static choices during loading. These failed exploratory attempts are not counted as successful checks.

LibreOffice is unavailable on PATH and in the standard Windows installation paths. XLSX-to-PDF conversion/visual print preview was **not run**. Server PDF rendering is separate; no printer-environment error is presented as an application defect.

[Synthetic 390/768/1440px screenshots, PDF and Excel samples](../../frontend/docs/reviews/dossiers-phase-four/README.md) are committed review artifacts. Existing Phase 1/3 galleries were preserved.

Reproduction from a guarded development test environment:

~~~bash
cd backend
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --filter='DossierClosureTest|DossierCompletionTest|DossierCompletionSafetyTest|DossierCompletionIOTest|DossierReleaseVerificationTest'
php tests/Support/verify-dossier-history-workbook.php
php vendor/bin/pint --dirty --test
~~~

Build Next with the test-only Laravel URL at loopback port 8194, package assets before startup, and run the new standalone on 3194. Serve Laravel with the existing guarded tests/Support/dossier-server.php and CLINIC_DOCTOR_STAFF_TYPES=DWF-DOCTOR. Both processes must inherit the approved testing environment. From frontend, with the existing Playwright browser path:

~~~bash
node --test --test-concurrency=1 tests/dossiers-live.test.mjs tests/dossier-workflow-live.test.mjs tests/dossier-completion-live.test.mjs tests/dossier-review-live.test.mjs tests/dossier-closure-live.test.mjs
npx tsc --noEmit
npm run lint
npm run build
git diff --check
~~~

Use DOSSIER_SKIP_GALLERY=1 to preserve earlier UI galleries; the existing Phase 3 suite also downloads its report samples, so back those up/restore them when running a later-phase regression. The closure suite writes only its own synthetic gallery. Fixtures store tokens only in ignored testing storage and revoke them in teardown. Never run database-refresh suites concurrently with live fixtures.

## Deferred

Actor-directory search, reconstructed pre-audit history, completed-record correction workflows, new statistics, dispensing/inventory/billing, malware scanning, notifications, automatic clinical decisions and further wizard work remain outside scope. No merge or deployment is performed by this task.
