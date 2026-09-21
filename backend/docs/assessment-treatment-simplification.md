# Assessment and treatment entry simplification

The patient-card workspace separates plans, appointments and actual visit facts. No write is triggered by opening a section or changing tabs.

- The pathology report dialog records an available report (`completed`), with its actual result date and conclusion. There is no report-lifecycle selector or supporting-file picker. Historical report states and attachment links remain readable; omission does not unlink existing attachments. The diagnostic assessment still records the physician's clinical decision and current evidence.
- The plan dialog has one protocol entry (`protocol_name`, labeled name or code), an explicit plan author and clinic, and collapsed optional scheduling metadata. Historical `protocol_code` and regimen items are preserved when amending a plan; new UI plans contain no planned medications. The existing historical API contract is retained.
- Plan-backed appointments are still entered as individually confirmed dates. A separate **next dose appointment** action needs no plan, approval, period or visit. It requires an eligible clinic/doctor relationship on the selected date, and the existing `dossiers.treatment.schedule` permission.
- Visit medications have one workspace with separate prescription, administered and dispensed panes. Prescriptions never count as administration or dispensing. Actual oncology administration retains its approved-plan/session/readiness rules and distinct supervising and executing actors; dispensing retains its actual-dose relationship. Independent appointments cannot be administered directly or silently converted to plans. Neither pane implies stock movement.
- Uploading optional attachments and reviewing/confirming the visit are separate panes. Pending uploads block review; switching panes preserves the mounted upload state. Saving review and explicit completion retain the previous validation and authorization rules.

## API and storage

`POST /api/dossiers/{dossier}/treatment-sessions` accepts `facility_id`, UUID `request_id`, `planned_on`, `clinic_id`, `doctor_id`, and optional `note`; returns `201 {"data":{"id":...}}`. It stores an independent `oncology_sessions` row with null plan/revision/session number. The existing list/detail endpoints, next appointment summary and patient reports include it. It creates no visit, plan, dose or dispensing record.

The existing session update endpoint still uses `lock_version`, reason and explicit status/date. Independent sessions reject `carry_forward`; plan-backed sessions still require `plan_lock_version`. UUID replay, audit, composite facility/card scope, Bearer authentication and private/no-store responses remain enforced.

Migration: `2026_09_22_000002_allow_independent_treatment_appointments.php`. It makes only the plan linkage and session number nullable, restores the existing administration foreign keys, adds an explicit card/facility foreign key and an all-or-none plan-link check. Existing plans, sessions, doses and revisions retain their IDs and links. Rollback refuses before changes if independent appointments exist; it never deletes them or invents plans.

Operator deployment, after the usual backup and review:

```sh
cd backend
php artisan migrate --force
```

Rebuild Next with the correct `LARAVEL_API_URL` (including `/api`) using the existing deployment procedure. The specific numeric `treatment-sessions` rewrite already covers the new POST. No environment variable, permission, seeder or automatic permission grant is added. No scheduler/storage change is needed.

## Verification

Only isolated local MariaDB 10.11.18 was used: mysql driver, `blood_bank_cities_testing`, `127.0.0.1:13416`, after the existing test safety gate. The populated database was migrated in place; tests used `tests/Support/preserve-database.php` rather than `migrate:fresh`.

- Before implementation: independent appointment regression failed with HTTP 405 (expected 201).
- Affected Laravel regression run before the request to reduce testing: 53 passed, 7,897 assertions, 38.40s.
- Final focused functional regression: 3 passed, 43 assertions. Covers UUID replay/mismatch, reschedule/cancel, stale version, permissions, clinic eligibility, facility isolation, next appointment, reports, no implicit clinical facts, database scope checks and safe rollback refusal.
- Focused real browser workflow: report save with draft-preserving validation, independent appointment, no fabricated plan/dose, single protocol input, medication panes, and attachment/review separation. It uses the rebuilt standalone Next on 3194, Laravel on 8194 and real MariaDB; no interception or mocked API responses.
- Full frontend suites and responsive screenshot matrices are intentionally not rerun after the user's request to limit testing to functional checks. Existing browser selectors were updated for the renamed/moved controls. Local synthetic desktop screenshots were inspected; this is not a claim of complete responsive visual QA.
- TypeScript, ESLint, production build, Pint and `git diff --check` are the final static checks. Final results are recorded in the PR.

No production access, deployment, merge or FastAPI change was performed.
