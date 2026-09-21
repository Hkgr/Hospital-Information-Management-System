# Patient card and visit workspace

The registration entry is `/patient-cards/new?facility_id=…`. A new card's first
successful save atomically records its real first draft visit using the entered
actual visit date. Merely opening the page creates nothing. Existing UUID replay,
optimistic locking, explicit conflict review and facility/global permissions apply.

`/visits` lists eligible draft/complete, non-voided, non-future visits in the
authorized facility. Its server query supports full name, patient code, visit
number, date/status filters, sorting and pagination. The navbar uses `dossiers.view`;
registration still requires `dossiers.visits.create` and the existing workflow rules.
Choosing an existing card reuses its patient identity; a draft card resumes its
first saved visit, while an active card can receive another independent visit.

All card/visit editing links lead to the same workspace, with `card`, `visit`
(an ID or `new`) and optional `section`. Existing `/patient-cards/{id}/edit` links
remain compatible. Facility selection uses `directoryFacility`; an unauthorized
explicit facility never falls back silently. The details page retains read-only
history, reports and treatment views, with links into the workspace for changes.

The stages distinguish the permanent card from the current visit and optional
diagnostic/treatment tools. Oncology stages appear for an oncology patient with
treatment access; an explicit historical treatment link remains usable. Multiple
clinics/doctors are supported per clinical occurrence. Each picker selects the
clinic first and queries eligible assignments covering the actual visit date;
changing that scope clears the old doctor, and stale requests cannot restore it.
The server still checks every new/corrected clinical relationship.

Prescriptions, appointments, actual administration and actual dispensing retain
their separate meanings and existing explicit save actions. Treatment tools use
the existing editors inside the workspace. No default diagnosis, treatment,
acceptance or visit completion is inferred. Card activation remains explicit.

## Removal of visit classification

`2026_09_22_000001_remove_visit_classification.php` drops the visit-type foreign
key, **the entire `visits.visit_type_id` column (including historical values)**,
and `visit_types`. Clinical visit rows, dates, ownership and scope constraints are
preserved. Older published migrations are unchanged. Rollback refuses explicitly:
removed classifications cannot be reconstructed. An operator must take a reviewed
backup before deploying this migration; restoring that backup is the recovery path.
Historical audit provenance is not rewritten; removed fields are excluded from
its public projection. `dossier_visit_kind` is an internal initial/subsequent
workflow marker, not the removed user-selected classification.

After review, the operator deploys backend and frontend together, runs
`php artisan migrate --force`, and rebuilds Next with its correct `LARAVEL_API_URL`.
These commands have **not** been run against production. No new permissions,
automatic grants, production settings or FastAPI changes are required.

New import workbooks use `patient-import-2`, without a visit-type column or lookup.
Download a new template; the old template is rejected rather than silently shifting
columns. Historical import source identities/fingerprints stay immutable: a changed
source replay is held for review rather than creating a duplicate visit. Existing
pending encrypted import evidence is not modified by this schema migration.

## Local verification

Only isolated local `mysql` connections to MariaDB 10.11 were used, after
`artisan test-db:check --connect --env=testing`. Browser fixtures use
`127.0.0.1:13416`, database `blood_bank_cities_testing`. The wider Laravel regression
uses a separate local MariaDB instance on port 13417 and a new empty database
`patient_visit_workspace_testing`; existing databases were not reset for that run.
The initial regression runs used Laravel `RefreshDatabase` on
`blood_bank_cities_testing` (synthetic local test data only). Subsequent checks on
that database use `--bootstrap=tests/Support/preserve-database.php` and transactions;
they never reset it. The populated migration regression creates and
removes its own prefixed test tables and verifies historical clinical rows survive,
new unclassified visits can be inserted, and rollback refuses data fabrication.

The final affected Laravel run passed **206 tests / 13,552 assertions**. It includes
patient/workspace, import, pathology, oncology, completion, reports, doctor, clinic,
catalog and lifecycle regressions. Earlier populated-database runs exposed absolute
table-count assumptions in directory tests; these pass on the separate empty test
database. The oncology test now explicitly seeds its audit permission and freezes
time when comparing query counts, so Sanctum timestamp writes cannot affect that
comparison. Application permissions were not loosened.

Before implementation, the new registration regression received 422 because
`visit_type_id` was required; the new visit directory returned 404. Both pass now.
The complete Laravel suite was not run; the result above is the affected suite.

Real browser tests use a fresh Next standalone build on port 3194, Laravel on 8194,
and synthetic MariaDB fixtures, with no mocked API responses. Run sequentially:

```sh
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter=PatientWorkspaceTest
# With the guarded Laravel test router and rebuilt Next standalone already running:
node --test tests/patient-workspace-live.test.mjs
node --test tests/oncology-live.test.mjs
```

`PLAYWRIGHT_CHANNEL=chrome` can select an installed Google Chrome for these tests.
Synthetic visual captures cover 390, 768 and 1440px. Superdesign generation was
unavailable because the connected team had insufficient credits; implementation
and visual verification use the project's existing components and local Cairo.

The actual captures are available in the repository's
[visual review](../../frontend/docs/reviews/patient-workspace/README.md).
They show the new-card form, validation with retained draft, clinic/doctor fields
and embedded assessment/treatment/administration tools.

TypeScript (`tsc --noEmit`), ESLint, production Next build, Pint (`--dirty --test`)
and `git diff --check` passed. Next standalone was restarted with the rebuilt
static/public assets; readiness was checked before the final browser run.

| Real browser suite | Actual result |
| --- | --- |
| patient-workspace-live | 2/2 passed, including all three widths |
| dossier-workflow-live | 4/4 passed |
| dossier-completion-live | 7/7 passed together on the final build (143.82s) |
| dossier-pathology-live | 5/5 passed |
| dossier-review-live | 4/4 passed |
| oncology-live | 7/7 passed |
| patient-card-live | 5/6 passed; the selected-report browser case remains unsuccessful |

These are separate suite runs, not a claim of one complete all-green frontend run.
The remaining patient-card case reaches Excel successfully but the PDF request in
installed Chrome and Edge receives HTTP 204 without the Laravel response marker,
so no browser download occurs. The same synthetic POST through Next using Node
fetch, and directly through Laravel, returns HTTP 200 with a valid PDF response
(approximately 98KB). Internet Download Manager is running locally; external
download interception is suspected but not proven, and its settings were not
changed. The completion suite's separate PDF/Excel downloads pass. The failing
selected-report browser case is retained, not skipped or declared successful.
The full import/closure browser suites were not rerun in this task; their affected
backend tests are included in the 206-test result.
