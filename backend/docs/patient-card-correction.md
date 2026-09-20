# Unified Patient Cards — بطاقات المرضى

See [Patient Card browser routes and presentation](patient-card-concept.md) for the current `/patient-cards` paths and presentation contract.

## Identity and facility ownership

`patients` is the authoritative global Patient Card identity, not a separate
patient directory workflow. Its primary key guarantees one identity/card per
patient; its existing global unique `patient_code` is the only public code.
New registrations ask for that code manually (1–40 characters, outer whitespace
removed by request middleware). Existing canonical codes, including older
generated codes, remain unchanged. No code is reassigned or inferred from names.

The approved multi-facility policy retains `patient_dossiers` as **local medical
contexts**, not additional business cards. Each context holds that facility's
medical/oncology data, opening date, activation state and progress. Existing
`(facility_id, patient_id)` uniqueness and every composite clinical FK remain.
The legacy numeric `id`/URL remains a local context handle; responses additionally
return global `card_id = patients.id`. Both compatibility fields `code` and
`patient_code` resolve to `patients.patient_code`. The UI/export has one code
column and one navigation entry, بطاقة مريض / بطاقات المرضى.

Selecting an existing patient with a local context opens their card for an
authorized subsequent visit or draft continuation. Selecting one without a local
context explicitly records a genuine visit in the selected facility and creates
only its medical context, never another patient/card. Users still need explicit
global `patients.search`; no global clinical access is added. Passing a context
or visit ID owned by another facility is rejected even for an account authorized
in both facilities. Clinical/attachment/audit queries retain their facility scope.

## First save and later sections

`POST /api/dossiers` remains the compatibility registration endpoint. A new person
requires `person_mode=new`, manual `code`, personal fields, `opening_date`,
explicit **actual** `visit_date`, active `visit_type_id`, facility and UUID
`request_id`. Existing-person mode sends only `patient_id`, opening/visit essentials
and request context; it prohibits a second code and personal-field overwrites.

The single transaction creates the new patient/card, its local context, genuine
initial **draft** visit, progress and audit. The visit date has no implicit default
and can differ from opening date. Future dates are rejected. There are no invented
diagnoses, providers, outcomes, dispensing or reporting periods. A failed write
rolls everything back. New registration requires both `dossiers.create` and
`dossiers.visits.create` plus the appropriate global identity permission. UUID
replays return the same identity/visit; changed content conflicts. A serialized
global code reservation and database uniqueness also protect simultaneous requests.

Creation-entry-point review found `DossierPersonalWriter` to be the only runtime
patient insert in `app`/routes; there is no standalone patient-write endpoint or
runtime patient import command. Blood Bank selects an existing patient or stores
its independent donor/recipient profile. It does not insert a patient or visit.
Existing seed/test fixtures represent historical states, not public registration.

The first successful response already includes the initial visit. Section three
therefore **updates it with lock_version**, rather than posting another visit.
Opening a wizard saves nothing. In-memory unsaved input survives validation and
subdialogs; no persistent medical browser draft is introduced. Existing codes
are read-only in identity editing. Existing completion, audit, attachment,
prescription-versus-dispensing and void rules remain unchanged.

## Legacy reconciliation and additive migration

Read-only operator command (no apply mode):

```sh
php artisan patients:reconcile-cards
php artisan patients:reconcile-cards --details
```

The default prints counts; `--details` prints internal IDs of the affected
patients, local contexts and unlinked visits. Treat that output as private operator
data. It never inserts visits, links rows, merges identities, changes codes or
rewrites historical audit payloads.

Before writing the migration, the isolated synthetic MariaDB inventory was:

| Check | Count |
|---|---:|
| Patients / facility contexts | 11 / 11 |
| Patients without contexts | 0 |
| Contexts without visits | 9 |
| Patients with multiple contexts / facilities | 0 / 0 |
| Context versus canonical code mismatches | 11 |
| Ambiguous legacy code groups / collisions with another canonical code | 0 / 0 |
| Duplicate / invalid canonical code groups | 0 / 0 |
| Unlinked visits | 6 |

These are **test-database counts**, not a production assessment. The operator
must run the same inventory in the intended environment before rollout.

`2026_09_20_000001_protect_patient_card_registration_visit.php` is additive. It
introduces nullable `patient_dossiers.registration_visit_id` and a composite FK
to the same visit/patient/facility/context. New transactions populate it; deleting
or detaching that registration visit is rejected. Legacy links remain NULL:
the migration does not guess which visit represents registration. Rollback refuses
before any DDL once protected registrations exist; never force it or fabricate
data to make it pass. No prior migration is edited.

Existing `patient_dossiers.code` values are retained as read-only **legacy aliases**.
Search matches these aliases and returns canonical codes for explicit selection;
ambiguous aliases can return multiple people and never auto-select or merge them.
New canonical codes cannot reuse any historical alias.
`2026_09_20_000002_keep_context_codes_as_legacy_aliases.php` makes this legacy
column nullable and indexes alias lookup. New contexts store NULL, so the current
code is persisted only in `patients`; historical values and their scoped unique
constraint are preserved. Its rollback refuses once code-free contexts exist,
rather than inventing replacement codes. Neither migration rewrites patient or
clinical data. On the populated test database, row hashes before/after the second
migration matched for patients, contexts, visits, diagnoses, services, procedures,
blood donations, transfusions and audit records.

Canonical duplicate/invalid codes block migration before DDL. Resolve their
ownership through an independently reviewed, audited correction plan. Multiple
facility contexts are retained under the approved shared-identity/local-medical
policy; they are not merged clinically. Legacy contexts with no linked visits
remain listed with an explicit warning. Patients without any local context and
unlinked visits remain present in the reconciliation report/global authorized
identity search. Only a reviewed unambiguous patient/facility ownership decision
may link an existing visit; this PR provides no automated apply mode.

## Operator rollout (not executed on production)

Back up and review the read-only inventory first, then use the normal deployment
maintenance process so old and new registration writers do not overlap:

```sh
php artisan patients:reconcile-cards --details
php artisan migrate --force
php artisan db:seed --class=DossierPermissionsSeeder --force
php artisan db:seed --class=DossierWorkflowPermissionsSeeder --force
php artisan db:seed --class=DossierCompletionPermissionsSeeder --force
php artisan db:seed --class=DossierAuditPermissionsSeeder --force
php artisan patients:reconcile-cards
```

The seeders define existing permission codes/updated labels only; no grants or
new permissions. Operator-reviewed assignments must include visit creation for
staff registering new cards. Keep existing private attachment storage and cleanup
scheduling. Build Next with the intended `LARAVEL_API_URL` and package fresh
standalone assets. No new rewrite, environment variable or FastAPI integration.
Never use `migrate:fresh`, truncate, destructive reseeding or forced rollback.

## Verification

The isolated MariaDB 10.11.18 database is `blood_bank_cities_testing`, driver
`mysql`, loopback `127.0.0.1:13416`. A stopped local instance was restarted against
its existing datadir; the stale test login was replaced for this task with a
separate database-scoped local test account. Existing accounts/data were retained.
The ignored local `.env.testing` now points to that working loopback connection;
its existing password was retained and no credentials are committed.
`test-db:check --connect --env=testing` passed before database work.

The new regression was run against the previous personal writer/request first:
**1 failed / 2 assertions** (entered canonical code versus generated `P-UUID`).
It passes with the correction. Tests use existing data and transaction rollback,
never schema resets. The opt-in bootstrap below refuses pending migrations and
prevents RefreshDatabase from rebuilding the database:

```sh
php artisan test-db:check --connect --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php \
  tests/Feature/PatientCardTest.php tests/Feature/DossierWorkflowTest.php \
  tests/Feature/DossierApiTest.php tests/Feature/DossierCompletionTest.php \
  tests/Feature/DossierCompletionSafetyTest.php tests/Feature/DossierClosureTest.php
```

Only select transaction-based tests with this bootstrap; historical migration
tests explicitly invoking destructive commands are not part of this task.
Real transport/browser regressions: `node --test tests/patient-card-live.test.mjs`
against newly built standalone Next (3194), the loopback Laravel test router
(8194), and the guarded MariaDB configuration inherited by all workers. It checks
390/768/1440 layouts without creating review media. No API interception is used.
Final regression: 51 Laravel tests / 2165 assertions; the six real browser suites
below passed together (27 tests, zero failures/skips). The concurrency case uses
two independent Laravel HTTP-kernel workers against the same guarded database;
the browser cases and transport checks use Next -> Laravel -> MariaDB. Existing
layout/navigation tests also passed (8 tests, using their original API fixtures).
TypeScript, lint, production build, Pint and `git diff --check` passed.

```sh
# frontend; use the loopback standalone build and test router described above
node --test --test-concurrency=1 tests/dossiers-live.test.mjs \
  tests/dossier-workflow-live.test.mjs tests/dossier-review-live.test.mjs \
  tests/dossier-completion-live.test.mjs tests/dossier-closure-live.test.mjs \
  tests/patient-card-live.test.mjs
```

The optional full repository suite was not run; existing destructive migration
tests were excluded under this task's no-reset rule. No production inventory or
deployment was attempted. No screenshots, PDF/XLSX samples, videos or existing
review-gallery changes are included in the commit/PR.
