# Patient Card Phase 2: diagnostic assessment and pathology

Built after PR #25, from develop `d955be79c7eb184727924c0ddeec5d7fa5876dee`
(contains `39d52996b0f263d1a10650b3141b43ddb61fc8d0`). Browser routes stay
`/patient-cards`; internal routes, permissions and facility context IDs stay `dossiers`.
No new patient identity, patient category or AppShell entry is introduced.

## Facts and decisions

`visit_diagnostic_assessments` contains one audited current decision per real visit.
Its dispositions are `not_assessed`, `pathology_required`, `pathology_pending`,
`pathology_confirmed`, `pathology_not_required`, `referred_out`.
No row means **not assessed**, never not required. Explicit not-required needs a reason;
required needs a reason. Optional clinic/doctor must be supplied together and their
assignment must cover the actual visit date. Existing historical assignments are retained.

`visit_pathologies` holds independent internal/external cases: `requested`,
`specimen_collected`, `pending_result`, `completed`, `unavailable`, `cancelled`.
These states record known clinical facts; historical entry need not invent each previous
stage. Completion requires a known result date and conclusion. External source requires
the organization. Cancellation/unavailability needs an explicit reason. A cancelled case
remains visible, distinct from an explicitly voided erroneous record. Updates preserve
omitted fields and other records. Audit snapshots retain corrections to completed facts.
Results may arrive after a visit completes: specific pathology/assessment permissions
allow these independent records to be updated without changing visit completion or
unlocking other clinical sections.

### Authoritative conditional fields and transitions

Laravel merges omitted fields, then normalizes the current medical state before
validation/persistence. Hidden browser inputs are not an integrity boundary.

| Assessment disposition | Retained conditional fields |
|---|---|
| not_assessed | None: both decision reasons and evidence are cleared |
| pathology_not_required | Required nonblank not_required_reason only |
| pathology_required | Required nonblank required_reason only |
| pathology_pending | Applicable required_reason; no not_required_reason/evidence |
| pathology_confirmed | Valid completed nonvoided evidence; applicable required_reason may remain |
| referred_out | Applicable required_reason may remain; no not_required_reason/evidence; requires the saved DOS-REFER outcome |

Generic note, follow-up and responsible clinic/doctor remain omission-preserving. The
editor clears incompatible local values on selection changes and after explicit conflict
review; it never saves a selection change automatically. Details show only relevant fields.
The audit still preserves previous decisions as history, not as current medical facts.

Pathology normalization clears external_organization for internal source; clears
unavailable_reason outside unavailable/cancelled; and clears result_on/conclusion for
every non-completed state. Required values are checked after merging: omitted valid saved
values remain, while an explicit null/blank required value is rejected.

The minimal transition policy allows movement between non-completed states and direct
historical entry into completed. A completed report stays completed during an authorized,
locked, audited correction. Every completed → non-completed transition returns 422
atomically. An erroneous completed report must be voided with a reason, then replaced by
an explicitly created record. No additional workflow engine or automatic replacement exists.

MariaDB CHECK constraints enforce current-row mutually exclusive fields, required reasons,
final-result presence/absence, responsibility pairs and nullable chronological ordering.
Transition checks and visit-relative/facility-local date validation require service-level
knowledge and remain enforced under the existing locks; a CHECK is not a cross-row trigger.

Confirmation explicitly references a completed nonvoided pathology in the **same facility
medical context**, possibly from an earlier visit for follow-up. The report always shows
its source visit. The recorded decision is preserved; effective_disposition becomes
not_assessed and needs_review if its evidence/visit is voided or the report ceases to be
completed. A withdrawn referral similarly requires review. Treatment readiness must use
the effective state and current evidence, not a historical decision label alone.

Nullable request/collection/result/assessment dates remain unknown, with no artificial
cutoff. Known request <= collection <= result; actual event dates cannot exceed facility
today. External reports can predate medical-context opening and the hospital visit.
No report creates a diagnosis, performed service/procedure or reporting period. An
optional procedure_event_id references an existing same-visit performed procedure.

Known assessment dates and every known internal request/collection/result date must be
on or after the source visit date. A later result may arrive after visit completion, but
not after facility-local today. External historical dates may predate the visit and file
opening, with no September cutoff. Changing the visit date also validates its retained
internal pathology/assessment facts; it cannot move the visit past their known dates.

## Referrals and attachments

Incoming referral remains the visit's is_referred/referring_hospital/referral_date/
referral_reason fields. Outgoing referral remains the existing DOS-REFER outcome with
destination, date, reason, clinic/doctor and audit. Record the unavailability reason in
that outcome; assessment referred_out requires this saved nonvoided outcome. No second
referral table is needed.

`pathology_attachments` associates recorded private visit attachments, with composite
FKs enforcing the same visit/facility (and transitively context/patient). Reservation or
pending upload IDs are not accepted. Attachment linking requires attachments.view;
download/upload/void remain separately authorized through existing endpoints. Links
are additive: omission never erases history. Voiding a case never removes its files or
links. Responses and reports include only authorized metadata, never keys or binaries.

## API and authorization

All routes below require facility_id, dossiers.view, an active facility/account, and
Sanctum Bearer `api`; responses are private, no-store.

| Route under /api/dossiers | Method | Additional permission |
|---|---|---|
| /{dossier}/pathology | GET | none |
| /{dossier}/visits/{visit}/pathology | GET | none |
| /{dossier}/visits/{visit}/pathology/{pathology} | GET | none |
| /{dossier}/visits/{visit}/pathology | POST | dossiers.pathology.create |
| /{dossier}/visits/{visit}/pathology/{pathology} | PUT | dossiers.pathology.update |
| /{dossier}/visits/{visit}/pathology/{pathology}/void | POST | dossiers.pathology.void |
| /{dossier}/visits/{visit}/diagnostic-assessment | GET | none |
| /{dossier}/visits/{visit}/diagnostic-assessment | PUT | dossiers.assessment.update |

Writes use request_id UUID and lock_version (assessment first save uses zero), existing
dossier_requests fingerprint/replay transactions, context/visit/record locks, and audit.
Changed UUID payload or stale version returns atomic 409. Void requires void_reason.
The UI preserves its draft and requires fetching current data and explicitly selecting
fields before another save. Refetching does not silently replace or save the draft.
Specific numeric Next rewrites expose only these routes. OpenAPI documents their contract.

## Lists, reports and performance

The optional pathology_status column/filter uses the same labels in display and exports.
It is not a default table column. A bounded SQL window subquery ranks eligible nonvoided
case and assessment facts by actual known date (falling back to source visit date for
ordering only), visit ID, assessment-before-case tie priority, then record ID. It never
uses creation/update time to manufacture a medical date. There is no row-specific query.
History is paginated before attachment metadata is loaded in one bulk query.

Card and visit PDF/XLSX use existing templates, Cairo, RTL and typed/formula-safe cells.
Card history includes recorded decisions and cancelled/voided case semantics; visit reports
contain only that visit's facts. Unknown dates remain unknown. List export covers every
filtered match, respects selected columns and keeps the legacy patient_code alias.
The existing audit history exposes safe field-level corrections under dossiers.audit.

## Safe upgrade and operator actions

No production command was executed during implementation. Operator deployment order:

```sh
php artisan migrate --force
php artisan db:seed --class=DossierPathologyPermissionsSeeder --force
# Review dossier-pathology-permissions.sql and existing facility assignments explicitly.
php artisan optimize:clear
# Rebuild Next with the existing intended LARAVEL_API_URL (including /api).
npm run build
```

New migration: `2026_09_20_000001_add_visit_pathology_workflow.php`. It adds the three
tables and scoped reference indexes without backfill or inference from diagnoses.
Rollback removes only new structures when empty; it refuses **before any DDL** when
new medical facts exist. It does not erase facts or alter existing identities/visits.
The seeder defines Arabic permission labels only and never assigns or reactivates them.
The provided super_admin SQL is operator-reviewed, never automatically executed.

Testing uses the repository guard and populated-database-preserving bootstrap, never
migrate:fresh/truncate/reset. Example:

```sh
php artisan test-db:check --connect --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/DossierPathologyTest.php
```

## Deferred scope

Future treatment-plan validation will consume current pathology readiness. This phase
does not implement treatment plans, chemotherapy protocols/doses/schedules/administration,
dispensing, Excel import, offline synchronization, or new directory CRUD.

## Initial verification (reviewed fa37b717)

Verification used `mysql`, MariaDB **10.11.18**, the existing isolated populated
`blood_bank_cities_testing` database at `127.0.0.1:13416`. The connection safety guard
passed. No fresh migration, truncation, reset, production connection or deployment ran.

| Check | Actual result |
|---|---|
| New additive migration on populated database | Applied, empty-new-tables rollback succeeded, reapplied; existing data retained |
| Populated pathology rollback | Regression proves refusal before DDL; new clinical facts retained |
| Nine affected Laravel suites | **68 tests, 9,772 assertions, zero failures/skips** |
| Seven real browser suites together | **33 tests, zero failures/skips**, real standalone Next → Laravel → MariaDB |
| New pathology real browser suite after final report/validation changes | **4 tests, zero failures/skips**, including two competing DB connections |
| Broader doctor/clinic/lifecycle regression | **54 tests, 2,006 assertions, 10 failures** described below; not reported as a passing gate |
| TypeScript (`npx tsc --noEmit`) | Passed |
| ESLint (`npm run lint`) | Passed |
| Next production build | Passed; standalone rebuilt with test Laravel URL and launched afresh |
| Pint, changed/new PHP files | Passed |
| `git diff --check` | Passed |
| PDF inspection | All **9 pages** of generated card (4), visit (4), list (1) PDFs rendered with PDF.js and visually inspected |
| XLSX reopening | PhpSpreadsheet reopened all three real exported files (14/14/1 sheets); shared assertions passed for Cairo/RTL, print areas, repeated headers, row heights, typed values, formula safety and private-key exclusion |

The affected Laravel run includes DossierApi, DossierWorkflow, DossierCompletion,
DossierCompletionIO, DossierCompletionSafety, DossierReleaseVerification,
DossierClosure, PatientCard and DossierPathology tests. The real browser batch includes
patient-card, dossiers, dossier-workflow, dossier-completion, dossier-review,
dossier-closure and dossier-pathology suites. No API interception or mock responses
were used. Concurrent UUID replay returned one ID; competing corrections returned
one success and one 409. The list query-count test compares one filtered result against
at least thirteen contexts, rather than asserting a fixed query count for one row.

An intermediate affected-suite run lost one local database connection (`2006 server
has gone away`). The guard and focused check passed afterward, followed by a complete
successful 68-test rerun. This is recorded separately from the final passing result.

The broader 10 failures are existing unscoped whole-table count assertions: three in
ClinicApiTest, four in DoctorApiTest and three in DirectoryLifecycleTest (including
the doctor/clinic data-provider variants). They expect empty-database counts for users,
clinics, visits, visit_procedures, clinic_staff and facility_user_roles. The required
preservation runner retains synthetic records from earlier integration runs, so those
assertions fail on this populated database. No unrelated test or data was changed to
make them pass. The complete repository suite and destructive migration test classes
were not run; this phase expressly prohibits resetting the database. The targeted
upgrade/rollback checks above cover this phase's new migration.

The affected older DossierCompletionIO tests were updated to use the fixture's real
visit version and current Patient Card report contract, with shared workbook assertions
instead of stale fixed cell coordinates. No application behavior was relaxed.

Pathology report facts use three columns (source visit, field, value) inside the existing
shared report pipeline to keep Arabic conclusions readable; no report template or
branding was redesigned. Excel desktop/printer rendering was not performed; XLSX
checks are programmatic and PDF review concerns the actual application-generated PDFs.

Synthetic responsive review images: [gallery](../../frontend/docs/pathology-review/README.md).
No real patient data, access tokens, private files or storage keys are included.

## State/date correction verification

Final correction verification used the same guarded MariaDB 10.11.18 database above.
The exact correction SHA is recorded in PR #26, which remains a draft.

| Check | Actual result |
|---|---|
| Four new regression tests before changing logic | **4 failures, 25 assertions**: stale assessment reason, stale external organization, accepted pre-visit assessment date, and absent SQL constraint |
| Final focused pathology tests (included in the affected run) | **13 passed, 3,849 assertions, zero skips** |
| Final nine affected Laravel suites | **73 passed, 12,357 assertions, zero failures/skips** |
| Seven real browser suites together | **34 cases: 33 passed, 1 failed**, zero skips; the new case selected a hidden status-filter option instead of the displayed pending-state paragraph |
| Final pathology browser suite after correcting that test locator | **5 passed, zero failures/skips**; real API, concurrent connections, conflict review and 390/768/1440px checks |
| TypeScript, ESLint, production build | Passed; a fresh standalone build/server used the isolated Laravel URL |
| Pint on all eight changed/new PHP files; `git diff --check` | Passed |
| PDF | All **13 pages** inspected with PDF.js: card (4), visit (4), list (1), normalized-state card (4); incompatible marker values absent, current note/reason retained |
| XLSX | Four real downloads reopened: **14/14/1/14 sheets**; shared print, RTL/Cairo, type, formula and privacy assertions passed; normalized workbook excludes stale marker values |

The six pre-existing browser suites passed in the combined run. The new pathology
suite was then rerun in full; the 34-case batch was not rerun after its locator fix.
No API mocking or interception was used. Browser authorization/isolation checks,
UUID replay (one record) and competing corrections (200/409) passed. Production
frontend code did not change after the successful build. Excel desktop/printer
preview was not performed; the visual PDF review uses application-generated PDFs.

Commands for the affected checks (from each respective project directory):

```sh
php artisan test-db:check --connect --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/DossierApiTest.php tests/Feature/DossierWorkflowTest.php tests/Feature/DossierCompletionTest.php tests/Feature/DossierCompletionIOTest.php tests/Feature/DossierCompletionSafetyTest.php tests/Feature/DossierReleaseVerificationTest.php tests/Feature/DossierClosureTest.php tests/Feature/PatientCardTest.php tests/Feature/DossierPathologyTest.php
node --test --test-concurrency=1 tests/patient-card-live.test.mjs tests/dossiers-live.test.mjs tests/dossier-workflow-live.test.mjs tests/dossier-completion-live.test.mjs tests/dossier-review-live.test.mjs tests/dossier-closure-live.test.mjs tests/dossier-pathology-live.test.mjs
node --test tests/dossier-pathology-live.test.mjs
npx tsc --noEmit
npm run lint
npm run build
git diff --check
```

See [base-versus-head directory comparison](pathology-baseline-comparison.md) for
the serial comparison on the same retained database. The ten count failures are now
established as baseline-equivalent; the base also has a schema-inventory-only failure
because it predates the preserved Phase 2 tables. No new head failure was introduced.

The existing unmerged Phase 2 migration was updated, without adding a production repair
migration. The local populated test database had already run its original definition.
The new nine CHECK clauses were applied additively after checking all existing rows;
row digests and attachment links remained unchanged. Populated rollback was verified
to refuse before DDL. The new guarded helper makes this check repeatable:

```sh
php artisan test-db:check --connect --env=testing
php tests/Support/pathology-state-upgrade.php
```

This helper is for the existing isolated test database only, not an operator production
upgrade command. A new environment applies the reviewed migration normally. Empty-table
full up/down was checked for the initial implementation; it was not repeated by dropping
these now-populated clinical tables. No rows were normalized, deleted or reset to install
the checks. Visit-relative rules and completed-state transitions remain locked service
validation because SQL CHECKs cannot validate a previous row state or another table.
