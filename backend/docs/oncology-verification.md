# Oncology Phase 3 verification

This document retains the **initial reviewed implementation** evidence at
`a0b233a33557c45c155b320d107fe885ea10f518`. The corrective pass and current results
are recorded in [oncology-corrective-review.md](oncology-corrective-review.md).
The original browser linkage between a 2090 appointment and a 2001 visit was
invalid; it is now rejected and the workflow explicitly reschedules first.

Branch: `feature/oncology-treatment-plans-and-doses`.
Base: `e2ead6d96b2eee292186e3b7837a6eaa45a7d6d5` (develop after PR #26).
The PR description records the final tested commit SHA; no empty verification commit
is needed. See [workflow and operator instructions](oncology-treatment-workflow.md).

## Environment and migration evidence

- Windows, PHP 8.4.14, PHPUnit 12.5.35, Next 16.3.4, Node 24.11.0.
- Guarded `APP_ENV=testing`, PDO driver `mysql`, **MariaDB 10.11.18**,
  `blood_bank_cities_testing` at `127.0.0.1:13416`. Credentials remain in ignored
  local configuration. No production connection, new database, reset, truncate or
  `migrate:fresh` was used.
- Before migration the read-only reconciliation found 58 structured dose sessions,
  58 administered items and 58 visit-medication records; no cancer case/diagnosis/
  treatment rows, orphan, cross-facility or duplicate candidates in this test data.
  These are local fixture findings, not an assessment of production data.
- Additive upgrade on that populated database, rollback with empty new structures,
  and re-upgrade succeeded. The initial rollback/re-upgrade exposed MariaDB's reuse
  of FK indexes; the migration was corrected to restore the legacy supporting
  indexes and remove only its own indexes, then the complete sequence passed.
- After new clinical facts were saved, rollback refused **before DDL**, as intended.
  SHA-256 snapshots of retained columns and ordered rows in 13 clinical tables
  remained equal. No artificial periods, inferred plans or medical-data deletion.

## Laravel and real integration

The final affected Laravel run passed **87 tests / 14,220 assertions**, no failures
or skips (77.148 seconds). It included:

```text
OncologyTreatmentTest (14 tests)
PatientCardTest
DossierApiTest
DossierWorkflowTest
DossierCompletionTest
DossierCompletionSafetyTest
DossierCompletionIOTest
DossierClosureTest
DossierPathologyTest
DossierReleaseVerificationTest
```

Command: `php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php`
followed by those ten `tests/Feature/*.php` files. The guard succeeded first. The
bootstrap preserves the existing schema and runs transaction-based test fixtures.
An intermediate run caught a prior all-column export test requesting new treatment
columns without treatment-view permission. Its original card-column coverage was
preserved, and an explicit forbidden treatment-export assertion was added. Final
results above are after that fix, not a targeted substitute.

Eight real browser suites passed together: **38 tests, 0 failures, 0 skipped**:

```sh
node --test --test-concurrency=1 tests/oncology-live.test.mjs tests/dossiers-live.test.mjs tests/dossier-workflow-live.test.mjs tests/dossier-completion-live.test.mjs tests/dossier-review-live.test.mjs tests/dossier-closure-live.test.mjs tests/patient-card-live.test.mjs tests/dossier-pathology-live.test.mjs
```

Transport was a freshly built Next production standalone on `127.0.0.1:3194` →
Laravel on `127.0.0.1:8194` → the isolated MariaDB above. The oncology suite uses no
API interception or mocked responses. It verifies actual stored clinical facts,
unchanged unrelated domains/periods, synthetic registration, effective evidence,
activation, two appointments, historical actual attendance, administration,
independent dispensing and all three PDF/XLSX report scopes. It also tests explicit
conflict review and evidence invalidation. Two concurrent MariaDB connections
produced one dose ID for identical UUID retries (200/200) and one winner for stale
correction (200/409).

The oncology four-case suite was rerun after final doctor-option and display
changes and the local picker-height correction: **4 passed, 0 failed, 0 skipped**
in 114.501 seconds. Synthetic medical history is retained; owned fixture tokens
are revoked. This is not production or pharmacy-inventory testing.

## Broader tests: exact comparison against base

Ran the same `ClinicApiTest`, `DoctorApiTest`, `DirectoryLifecycleTest` via the
preserving bootstrap against both head and a detached base checkout, on the same
populated isolated schema. This is explicitly **not a green full Laravel suite**.

| Run | Tests | Assertions | Failures |
| --- | ---: | ---: | ---: |
| Head | 54 | 2,010 | 10 |
| Base e2ead6d | 54 | 1,981 | 11 |

The following ten failures have identical expected/actual row counts at both SHAs;
these old tests assume whole tables contain only their fixture:

| Test suffix | Table | Expected / actual |
| --- | --- | --- |
| Clinic: doctor_eligibility_fail_closed_and_validation | clinics | 0 / 142 |
| Clinic: history_same_day_reopen_and_stale_version | clinic_staff | 1 / 127 |
| Clinic: patients_count_distinct_complete_visits_only_and_delete_protection | visits | 4 / 849 |
| Doctor: crud_global_unique_code_specialties_and_unrelated_fields_survive | users | 1 / 191 |
| Doctor: bidirectional_links_history_versions_hidden_and_future_periods | clinic_staff | 2 / 128 |
| Doctor: counts_are_distinct_attending_complete_not_clinic_patients_and_references_block_delete | visit_procedures | 1 / 753 |
| Doctor: explicit_grant_is_previewed_idempotent_and_never_guessed_from_role_name | facility_user_roles | 1 / 269 |
| Lifecycle: inactive_clinic_cannot_receive_a_new_doctor_link | clinic_staff | 0 / 126 |
| Lifecycle: explicit_link_after_restore_does_not_reopen_cancelled_future_period, doctors | clinic_staff | 2 / 128 |
| Same lifecycle test, clinics | clinic_staff | 2 / 128 |

Base's additional failure is `test_reference_inventory_covers_all_current_foreign_keys`:
base does not classify `oncology_plan_revisions.doctor_id` on the upgraded test
schema. Head classifies the new references and passes it. This extra failure is a
base-code/new-schema compatibility difference, not a claim of an old application bug.
No whole-table count assertions were changed merely to make these broader tests pass.
The complete Laravel suite was not invoked: older `DatabaseMigrations` tests perform
the destructive schema recreation expressly prohibited for this task. The focused
upgrade/rollback/refusal helper supplies the permitted migration evidence instead.

## Reports, responsive review, and query counts

- Generated card, selected-visit and filtered-list PDFs were rendered using PDF.js
  and `@napi-rs/canvas`: **8 + 5 + 1 pages**, reviewed for RTL, repeated headings,
  readable tables and no clipping/blank overflow. This is PDF rendering, not an
  Excel printer preview. Existing report layout was preserved.
- PhpSpreadsheet reopened **all three** generated workbooks. Card: 21 sheets,
  17 typed date cells, 5 numeric values, preserved leading-zero protocol code and
  formula-like text; visit: 17 sheets, 9 dates, 3 numeric values; list: 1 sheet,
  2 dates, 1 numeric count. Checked RTL/Cairo, print areas, A4/orientation,
  fit-to-width with unconstrained height, repeated/frozen headers, filters,
  row heights/widths, margins, safe text and absence of private storage data.
- LibreOffice is unavailable. No LibreOffice XLSX-to-PDF or actual printer preview
  is claimed. XLSX property assertions and rendered application PDFs are separate.
- Chromium reviewed list, complete detail, editor/error focus, schedule, actual
  administration and dispensing at **390, 768, 1440 px**. Images use synthetic data.
  A local oncology-only rule lets long picker labels determine row height; the
  browser asserts labels stay inside their buttons and page width does not overflow.
  [Accessible screenshot gallery](../../frontend/docs/oncology-review/README.md).
- Measured card list: **6 queries at page size 10 and 6 at size 50** (10 and 14 rows
  respectively in this fixture); card detail 25, plan list 5, revision detail 4,
  session list 2, selected-visit doses/dispensing 5. Separate Laravel regression
  verifies query count does not grow per added plan. No 5,000-row load test is claimed.
- TypeScript, ESLint, production build, Pint and `git diff --check` passed. Windows
  initially locked an in-use standalone directory during one rebuild; stopping
  only the owned test server allowed the rebuild. This was an environment retry.
- OpenAPI export succeeded: **13 explicit paths / 17 operations**, operation-specific
  request fields, Bearer and error contracts verified. Nine resource-inference
  warnings are present both at base and head (unchanged Auth/Dashboard resources).

No merge, deployment, production role assignment, FastAPI or AppShell change was made.
