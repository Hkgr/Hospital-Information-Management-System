# PR #27 corrective review

Reviewed starting SHA: `a0b233a33557c45c155b320d107fe885ea10f518`.
Branch: `feature/oncology-treatment-plans-and-doses`. The PR remains a draft.
The PR description identifies the final tested/pushed SHA.

## Root causes and corrected contract

- Administration checked the visit date but not the scheduled date or current
  revision. All three dates now match; an obsolete session requires explicit
  resolution after plan amendment/reactivation. Both conditions govern UI actions.
- Scheduling checked clinician eligibility but omitted revision date/count limits.
  New/rescheduled sessions now enforce those limits without expanding the plan.
- UUID replay did not detect identical clinical content under a different UUID.
  Normalized revision fields and ordered items now reject no-op amendments and
  exact scoped open-plan duplicates. Intentional duplicates need an explicit flag,
  reason, normal create access and an audit event.
- Full void kept an unconditional unique session link and a completed appointment.
  Void now atomically records a reason and explicit resolution with dose/session/
  plan versions. One generated active-session key allows one active dose while
  retaining historical attempts. A separate generated active-revision FK preserves
  exact active session/revision equality; historical revision/context FKs remain.
- Card medication details omitted their parent's void state. PDF/XLSX now label
  parent state/reason/date and the item's independent state/reason. Visit reports
  omit invalidated actual facts. Independently active dispensing remains labelled
  and independently voidable.
- Optional treatment columns were shown without view permission. The menu now
  derives its allowed columns from the same capability as treatment filters.
  Stale treatment-filter/export requests still return 403.

No new routes, permission definitions, automatic grants or clinical inference.
See [workflow/operator contract](oncology-treatment-workflow.md) for request fields
and the two additive migration names. Existing published migration is unchanged.

## Before-fix proof

Against the reviewed application code, newly added `test_integrity` regressions:
**6 tests, 51 assertions, 6 failures** (2.398 seconds). The application wrongly:

1. Accepted the 2090 appointment through a 2001 visit (201 instead of 422).
2. Accepted an obsolete-revision session after reactivation (201 instead of 422).
3. Accepted an out-of-bounds session (201 instead of 422).
4. Voided a whole dose without session resolution (200 instead of 422).
5. Created an identical open plan under a new UUID (201 instead of 409).
6. Created an unchanged clinical revision (200 instead of 422).

Command: `php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php
tests/Feature/OncologyTreatmentTest.php --filter test_integrity`.
Local JUnit proof: `storage/framework/testing/oncology-corrective-before.xml`.
After the first writer correction the same six passed with 99 assertions. Further
regressions cover report labels, all resolutions, historical revisions and direct
database constraints; final results are recorded below.

## Environment and migration evidence

Guarded `APP_ENV=testing`, driver `mysql`, MariaDB **10.11.18**, database
`blood_bank_cities_testing`, host `127.0.0.1:13416`. No secrets are included.
No new database, `migrate:fresh`, reset, truncate, clinical deletion or production
connection was used. Tests use the preserving bootstrap; live synthetic history
is retained and fixture tokens are revoked.

The first corrective migration was applied to populated data, safely rolled back
and reapplied. A second explicit snapshot run confirmed unchanged SHA-256 ordered
values across sessions, doses, items, dispensing and immutable revisions. After
recording replacement attempts and carry-forward, unsafe rollback correctly refused
before DDL. The generated active-revision constraint has its own additive upgrade
and non-destructive rollback. `tests/Support/oncology-integrity-migration.php`
verifies the applicable rollback/re-upgrade path and retained values.

## Verification status

Final affected Laravel run: **95 tests / 16,082 assertions, zero failures or skips**
(53.527 seconds), using the preserving bootstrap. Files:
`OncologyTreatmentTest`, `PatientCardTest`, `DossierApiTest`, `DossierWorkflowTest`,
`DossierCompletionTest`, `DossierCompletionSafetyTest`, `DossierCompletionIOTest`,
`DossierClosureTest`, `DossierPathologyTest`, `DossierReleaseVerificationTest`.
The oncology class contains 22 tests; eight corrective tests cover multiple
resolutions, boundaries and direct FK/unique failures in addition to API behavior.

TypeScript, ESLint, production Next build, Pint and `git diff --check` passed.
OpenAPI export succeeded; all 13 oncology paths and 11 write schemas were checked
for the updated fields. Export emitted 9 inference warnings; the same export
command on base also emitted 9 warnings. These were not hidden or treated as a
failure-free full documentation build.
The final local Next build embeds `http://127.0.0.1:8194/api`; standalone serves
on `127.0.0.1:3194`. No API mocking or `page.route` interception is used.

Final oncology browser suite: **6 passed / 0 failed / 0 skipped**, 165.268 seconds.
It includes all three responsive widths, explicit date mismatch rejection and
reschedule, administration/dispensing, obsolete-revision rejection and carry-forward,
atomic void/reschedule, replacement administration, both kinds of conflict review,
hidden unauthorized columns, stale-filter/export 403 and evidence invalidation.
The seven related browser suites passed separately on the same final build:
**34 passed / 0 failed / 0 skipped**, 581.639 seconds. These cover dossier read,
wizard, completion, review, closure, Patient Card and pathology workflows. Together
with the separate oncology run, all 40 final browser cases passed; this is not a
claim of one combined final invocation.

Real competing MariaDB connections: identical UUIDs returned 200/200 and one dose
ID; different UUID administrations returned 200/409 with exactly one active dose;
correction contenders returned 200/409. The harness waits for both worker READY
signals while holding the dossier lock; it does not rely on arbitrary sleeps.

Initial live-run setup failures were diagnosed: the local build's Laravel URL
needed `/api`; the concurrency preparation process needed the same synthetic
`DWF-DOCTOR` classification already used by its workers. Neither changed production
settings or relaxed application eligibility. The corrected responsive workflow
then passed all three widths; full rerun evidence follows.

The first combined eight-suite run had 37/40 passing: a validation-focus timing
race (the RAF could precede the disabled fieldset's React commit), a boolean
restored as empty text during session-conflict review, and a dependent scenario
without the preceding fixture. Focus now runs after DOM commit, and review retains
`false` for unselected carry-forward. The final rerun is recorded separately.

### Broader populated-database baseline comparison

Ran the exact same `ClinicApiTest`, `DoctorApiTest`, `DirectoryLifecycleTest` on
head and a detached checkout of base `e2ead6d96b2eee292186e3b7837a6eaa45a7d6d5`,
sequentially against the same guarded populated schema with no live fixture writes
between them. Head: **54 tests / 2,010 assertions / 10 failures**. Base:
**54 tests / 1,981 assertions / 11 failures**. This is not a green full Laravel suite.

The following count failures match exactly on head and base:

| Test suffix | Table | Expected / actual |
| --- | --- | --- |
| doctor_eligibility_fail_closed_and_validation | clinics | 0 / 168 |
| history_same_day_reopen_and_stale_version | clinic_staff | 1 / 151 |
| patients_count_distinct_complete_visits_only_and_delete_protection | visits | 4 / 1009 |
| crud_global_unique_code_specialties_and_unrelated_fields_survive | users | 1 / 223 |
| bidirectional_links_history_versions_hidden_and_future_periods | clinic_staff | 2 / 152 |
| counts_are_distinct_attending_complete_not_clinic_patients_and_references_block_delete | visit_procedures | 1 / 889 |
| explicit_grant_is_previewed_idempotent_and_never_guessed_from_role_name | facility_user_roles | 1 / 316 |
| inactive_clinic_cannot_receive_a_new_doctor_link | clinic_staff | 0 / 150 |
| explicit_link_after_restore_does_not_reopen_cancelled_future_period, dataset 0 | clinic_staff | 2 / 152 |
| same, dataset 1 | clinic_staff | 2 / 152 |

The additional base-only failure is `reference_inventory_covers_all_current_foreign_keys`:
base code lacks the oncology doctor-reference inventory present in this PR's schema.
No old tests or unrelated application code were changed to hide these failures.

## Visual and report evidence

The real browser flow captures rescheduling, obsolete-revision resolution, full
void resolution, replacement history and permission-aware columns using synthetic
patients at 390/768/1440px. Review images are linked in the frontend gallery.
All generated card/visit/list workbooks are reopened using PhpSpreadsheet with
print-property, type, leading-zero and formula-text assertions; parent-void labels
must occur in the card and stay absent from effective visit administration rows.

PDFs are generated by the existing mPDF pipeline and rendered with PDF.js for
inspection. LibreOffice/Excel printer preview is unavailable; no such preview is
claimed. PDF reports and XLSX artifacts stay local; the committed review evidence
contains synthetic screenshots only.

Final XLSX results: card 21 RTL/Cairo sheets, 36 typed dates, 7 numeric values,
2 leading-zero code cells and 2 formula-like safe text cells; visit 17 sheets,
9 typed dates and 3 numeric values; list 1 sheet, 2 typed dates and 1 numeric count.
Card void labels/reason and independently active dispensing are present; effective
visit administration rows exclude the void labels. All print-property assertions
passed. PDF inspection covered every page: card 11, visit 6, list 1, with repeated
headers and no observed clipping, overlap or blank overflow pages in these samples.

Review gallery: [screenshots and rendered report examples](../../frontend/docs/oncology-review/README.md).

Production, FastAPI, AppShell, inventory and billing are untouched. No merge or
deployment occurred. A further review is required; no review approval is claimed.
