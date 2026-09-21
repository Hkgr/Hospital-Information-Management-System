# Oncology plans and doses — schema decision record

Base: `e2ead6d96b2eee292186e3b7837a6eaa45a7d6d5`, latest develop containing
merged PR #26 and `c1ddfd5435f5d6de941c597a2e632422dad28856`.
Branch: `feature/oncology-treatment-plans-and-doses`.

## Inspection before implementation

- `patients.patient_code` remains the global identity. `patient_dossiers` remains
  the facility medical context, joined to actual visits by existing composite FKs.
- `cancer_cases`/`cancer_case_diagnoses` are legacy case facts, including their legacy
  case number. No new case identity/number is created or substituted for patient_code.
- `cancer_treatments` has a treatment-type reference, nullable dates and narrative
  description; no version, readiness, appointment or administration contract. Retain
  it unchanged. Do not infer structured active plans from these entries.
- Add `oncology_plans`, immutable `oncology_plan_revisions` and their planned items,
  and `oncology_sessions` independent of visits. Every amendment creates a new
  numbered revision; sessions retain their explicitly selected revision.
- Reuse `dose_sessions` for actual attendance/administration and `dose_session_items`
  for administered medication. Add nullable scoped plan/session links and item
  snapshot/void/locking metadata. Legacy records remain readable without these links.
- Reuse `visit_medications` for explicit dispensing; add nullable scoped dose link,
  code snapshot and dispensing purpose. Prescriptions remain in their existing
  tables. None of these writes mutate pharmacy inventory.
- Reuse staff, clinic assignments, medications, diagnoses, funding sources, UUID
  requests, audit, locking, actual-visit and facility authorization conventions.
  No protocol CRUD: protocol code/name and medication identification are snapshots.
- Existing actual dose and dispensing tables require a reporting period. Keep this
  contract: actual writes require an explicitly selected open same-facility period
  covering the actual date; corrections also respect the old period. Planning and
  scheduling do not require a period. Never create or reopen periods automatically.
- New actual dates must equal the selected actual visit date and cannot be future.
  Historical dates have no arbitrary September boundary. Scheduling can be future.
- Activation/review records the authoritative current Phase 2 readiness and evidence
  versions. Changed/withdrawn qualifying evidence blocks further administration until
  explicit authorized review. Historical administrations retain their original basis.
- A not-required exception needs a dedicated permission, explicit justification and
  responsible physician; missing/pending/referred evidence is never an exception.
- Additive migration only. Rollback refuses before DDL when new facts or metadata
  exist. The isolated populated test database is preserved; no fresh/reset/truncate.

Read-only preflight: `php artisan oncology:reconcile --env=testing` after
`php artisan test-db:check --connect --env=testing`. Aggregate reconciliation identifies
orphan/scope/duplicate candidates without names or inferred clinical conversion.

Inventory, procurement, batch/expiry tracking, billing, protocol catalog administration,
automatic legacy conversion and offline/import workflows remain deferred.

## Workflow and safety contract

The browser entry is the existing `/patient-cards` interface. There is no new
AppShell entry or parallel patient-card API. The global patient code and existing
registration, diagnosis, pathology, prescription and visit workflows remain intact.

1. Create a **draft** plan, even while diagnostic evidence is pending. Select the
   clinic, a doctor whose assignment covers the date, modality, intent, protocol
   snapshot, dates and optional planned medication items. A plan is neither a
   prescription nor proof of treatment.
2. Explicitly activate it. Normal activation requires effective
   `pathology_confirmed`. `pathology_not_required` additionally requires
   `dossiers.treatment.override`, a responsible physician and `override_reason`.
   Missing, pending, required, referred or invalid evidence cannot be overridden.
3. Confirm up to 24 explicit session dates per request. There is no interval-based
   automatic date generation. Appointments create **no visits**. Session numbers
   are unique within a plan, including cancelled history. Multiple distinct active
   plans are allowed.
4. Open/select an actual visit and explicitly record attendance/administration.
   Plan, session and visit versions must still match. A composite relationship and
   unique active-session link prevent duplicate attendance. The appointment date
   must equal the visit date and its revision must be the current approved revision.
   A different attendance date requires an explicit, reasoned reschedule first.
   Actual items are entered
   independently; planned items are not silently copied into medical facts.
5. Record take-home or supportive dispensing separately, linked to the dose and
   actual visit. No prescription, visit, stock movement or inventory deduction is
   inferred from a plan, appointment, administration or dispensing request.

Clinically changed plan amendments append immutable numbered revisions. Unchanged medication IDs
retain their original name/code snapshots even if the directory changes. Scheduled
sessions on an older revision become ineligible until explicitly carried forward
or resolved as missed/cancelled/referred. Completed administrations retain the
revision that governed them. Omission of
optional plan data or saved actual items preserves history; explicit removal of an
actual item needs its ID, version and void reason. Actual corrections and voids need
separate permissions and reasons. Voiding a dose does not erase its independently
recorded dispensing. Full void requires a selected appointment resolution:
`rescheduled`, `missed`, `cancelled`, or `referred`, plus dose/session/plan lock
versions and reason. Rescheduling requires a date and explicit `carry_forward`
when the appointment is obsolete. Void and resolution commit atomically.
Use correction when treatment occurred but its recorded details were wrong.

All new/rescheduled eligible appointments must be inside the current revision's
start/end dates and planned session/cycle counts, with a date-valid doctor/clinic
assignment. Neither boundaries nor counts expand implicitly. Session PUT requires
`plan_lock_version` alongside its own `lock_version`. Carry-forward copies the
current revision's clinic/doctor; audit retains the entire prior appointment.
`carry_forward=true` is valid only for an obsolete session in an effectively active
plan, with `status=rescheduled` and an explicit `planned_on`. Every reschedule
requires a submitted date, even when carrying forward; the old date is never used
as a server fallback. Current-revision carry and carry combined with a terminal
status return `422 ONCOLOGY_INVALID_CARRY_FORWARD`. Terminal resolution without
carry changes only status/reason (plus normal actor/version/audit metadata), retaining
the original revision, clinic, doctor and planned date. Both direct session writes
and full-dose void enforce these rules in the writer; invalid void resolution rolls
back dose, session and audit changes together. A missing reschedule date is a field
validation error; direct writer callers receive `ONCOLOGY_RESCHEDULE_DATE_REQUIRED`.
The editor shows carry only for obsolete reschedules, clears it on terminal choices,
and reapplies that rule during conflict review and payload construction.

Session lists expose current active `dose_id` / `visit_id` separately from boolean
`has_voided_dose`. The former are null when no active dose exists; the latter remains
true for retained attempts, including after replacement. Correlated existence avoids
multiplying session rows. Card appointment history uses the same indicator; effective
visit facts and independently recorded dispensing retain their existing meaning.
Historical void attempts retain their own immutable revision even after a resolved
appointment moves forward. Active/completed administrations cannot be moved.

Normalized meaningful fields and ordered regimen items detect accidental duplicates.
No-op amendment returns `422 ONCOLOGY_NO_CLINICAL_CHANGE`, without changing version
or approval. An identical open plan in the same dossier/facility returns
`409 ONCOLOGY_DUPLICATE_PLAN` and scoped `error.existing_plan_id`. Intentional
duplicates require `confirm_duplicate=true`, `duplicate_reason` and create access;
UUID retries remain ordinary retries. The normalization excludes technical IDs,
timestamps and amendment reason, preserves historical medication identification,
and compares decimal values independently of trailing decimal zeroes.

Pausing, completion and cancellation record actor, time and reason. Closed plans
cannot receive new schedules or administration. Rescheduling, missing, cancelling
and referring appointments retain previous values in the scoped audit history.
`DOS-REFER` remains the actual-visit referral convention and blocks new administration.
Radiotherapy without medication requires an explicit session title and performed-
treatment description; an empty appointment cannot count as administered treatment.

`effective_status` is authoritative for reads, filters, next-dose calculation and
new administration. A previously active plan becomes effectively `needs_review`
when its saved readiness fingerprint differs from current evidence, including
evidence/assessment/visit versions. This is computed without side effects on GET;
the stored approval and its evidence remain historical facts. Explicit reauthorization
records the new evidence snapshot, actor, time and reason. Completed administrations
retain their own activation basis. There is no automatic reactivation from an old
status string. Paused, closed and review-blocked plans have no eligible next dose.

Actual dates equal the selected visit date and must not exceed facility-local today.
Historic dates before September are supported. The existing actual dose/dispensing
period constraint is retained: select an open same-facility period covering the
date; correction/void also checks the previous period. Planning needs no period.
No period is created or reopened automatically.

## API and permissions

All routes remain under `/api/dossiers`, through explicit numeric Next rewrites
under `/hospital-api/dossiers`. They inherit active-account Sanctum Bearer tokens
with ability `api`, `private, no-store`, the existing safe error envelope, and
facility/dossier checks. OpenAPI is extended through `OncologyDocument`.

| Path after `/api/dossiers` | Methods / operation |
| --- | --- |
| `/treatment-options` | GET; date, optional clinic ID, authorized facility |
| `/{dossier}/treatment-plans` | GET paginated; POST draft |
| `/{dossier}/treatment-plans/{plan}` | GET revisions/items; PUT amendment |
| `/{dossier}/treatment-plans/{plan}/status` | POST activation/review/status |
| `/{dossier}/treatment-plans/{plan}/sessions` | POST explicitly confirmed dates |
| `/{dossier}/treatment-sessions` | GET paginated |
| `/{dossier}/treatment-sessions/{session}` | GET; PUT state/date with reason |
| `/{dossier}/visits/{visit}/doses` | GET actual doses/dispensing; POST administration |
| `/{dossier}/visits/{visit}/doses/{dose}` | PUT correction |
| `/{dossier}/visits/{visit}/doses/{dose}/void` | POST explicit void |
| `/{dossier}/visits/{visit}/dispensing` | POST explicit dispensing |
| `/{dossier}/visits/{visit}/dispensing/{dispensing}` | PUT correction |
| `/{dossier}/visits/{visit}/dispensing/{dispensing}/void` | POST explicit void |

Requests carry `facility_id`; writes carry UUID `request_id`. Updates require
`lock_version`. New administration also carries `session_lock_version`,
`plan_lock_version` and `visit_lock_version`. UUID replay is actor/facility/operation
scoped, serialized transactionally, and changed payload is rejected. `409` uses the
existing conflict contract. The editor keeps the draft, fetches the latest record
and requires explicit field/item review; it never blindly updates a version and
resubmits. Closing, changing context or session cancels outstanding reads/writes.

Every treatment action requires `dossiers.view` and `dossiers.treatment.view`.
Additional permission suffixes under `dossiers.treatment.` are:
`create`, `update`, `activate`, `override`, `status`, `schedule`, `administer`,
`dispense`, `correct`, `void`. Export, audit, patient registration, visit creation
and pathology permissions remain independently required by their existing APIs.
Full-dose void also requires `dossiers.treatment.schedule` because it resolves the
appointment. No permissions are newly defined or automatically assigned here.
No treatment-view access means no new treatment summaries, facts or audit entries;
requesting treatment filters/export columns explicitly returns 403.
Treatment filters and optional column-menu entries are hidden without view access.

## Reports and performance

Optional list filters: `treatment_status`, `treatment_modality`, `dose_from`, `dose_to`.
Optional columns: `treatment_count`, `active_treatment_count`,
`review_treatment_count`, `treatment_modalities`, `next_dose_on`, `last_dose_on`.
The default columns remain unchanged. Exports include all authorized matching rows.
Card reports add revisions, planned items, appointment changes, actual medication
and dispensing detail. Each historical voided parent dose and item carries explicit
state, reason and date; independently voided items keep their own reason. Active
dispensing linked to a voided dose remains a clearly labelled independent fact.
Visit reports include only that visit's effective non-voided actual facts, never
future appointments. Existing report aliases/templates, RTL/Cairo, safe text cells,
typed dates/decimals and attachment privacy remain in place.

Lists use aggregates/subqueries and bulk diagnosis reads, not per-card treatment
histories. Plans and sessions are server-paginated; revisions load only when one
plan is opened. Reports enforce existing bounded detail limits. The query-count
helper measures the current populated synthetic fixture; it is not a claim of a
5,000-card load benchmark. Indexed scope/status/date/FK columns are included in the
migration. Current measured counts are recorded in the verification document.

## Operator deployment instructions (not executed on production)

Review the additive migration and a backup/restore plan before deployment:

```sh
php artisan oncology:reconcile
php artisan migrate --force
php artisan db:seed --class=OncologyPermissionsSeeder --force
php artisan oncology:reconcile
```

The original migration is `2026_09_20_000002_add_oncology_plans_and_sessions.php`.
No old migration was changed. It adds `oncology_plans`, `oncology_plan_revisions`,
`oncology_regimen_items`, `oncology_sessions`, and nullable scoped links/metadata to
the three reused clinical tables. It preserves old data and infers no clinical
backfill. Doctor/clinic reference inventories include the new FK references.

The corrective follow-up is
`2026_09_21_000001_preserve_voided_oncology_administrations.php`. It adds a nullable
generated active-session key with a unique index, preserving every void attempt.
It backfills only `dose_sessions.oncology_plan_id` from the existing session FK.
Separate composite session/plan/context and historical-revision/plan/context FKs
retain relational scope without forcing an old dose to adopt a newer revision.
Existing visit/context constraints remain. Conflicting active duplicates refuse
upgrade before DDL. The follow-up can roll back safely while the old constraints
remain satisfiable; multiple historical attempts or a moved session with an older
dose revision cause refusal before DDL. No medical row is deleted or detached.
`2026_09_21_000002_constrain_active_oncology_revision.php` additionally enforces
the exact active dose/session revision tuple using a nullable generated revision
key. The historical revision FK remains enforced for voided rows; only the active
equality FK becomes inapplicable upon explicit void. Its upgrade preflights active
revision mismatches and refuses without choosing between medical facts.

The seeder defines permissions only. An operator must review role assignments,
including the elevated pathology exception. `database/sql/oncology-permissions.sql`
is an explicit optional super_admin assignment script, not an automatic deploy step;
it creates no facility memberships or global privileges. Refresh the client's
authenticated access context after operator-approved assignment.

Rebuild Next with the operator's existing `LARAVEL_API_URL` and the normal standalone
static/public assets. No new environment variable, scheduler, public storage or
FastAPI configuration is needed. Existing private attachment storage stays private.

Rollback refuses **before DDL** if any new clinical facts/metadata would be lost.
It never deletes them to make rollback possible. With empty new structures it restores
the pre-existing indexes, drops the additive structures, and permits re-upgrade.
Once new facts exist, plan a reviewed forward correction rather than forced rollback.

For local verification use the existing `.env.testing`, then:

```sh
php artisan test-db:check --connect --env=testing
php artisan migrate --env=testing
php tests/Support/oncology-migration-check.php
php tests/Support/oncology-integrity-migration.php
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/OncologyTreatmentTest.php
```

Never use `migrate:fresh`, reset or truncate for this task. The preserving bootstrap
requires an already migrated isolated test database and uses transaction rollback
for fixture writes. Live fixtures retain synthetic medical records and revoke their
tokens after use; they never delete clinical history. Full regression evidence,
baseline comparisons and review images are linked from the verification document.
