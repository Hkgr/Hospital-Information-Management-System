# Permissions matrix — Phase 2

Built from develop after PR #58. No production command has been run by this change.

## Operator activation

Back up and review the database before the normal deployment process. Run on the intended deployment only by the operator:

```sh
php artisan migrate --force
php artisan db:seed --class=PermissionMatrixPhaseTwoSeeder --force
php artisan route:list --path=reception
php artisan access:super-admin
```

Phase 1 definitions must already exist. The Phase 2 seeder is repeatable, defines seven permissions, adds the four reception permissions to `data_entry`, and adds reception plus the three review permissions to `hospital_admin`. It creates no users or assignments, leaves statistics without identified reports, and never assigns the new global authorities to ordinary administrators. Protected super-admin access remains the explicit Phase 1 role assignment, not a user-ID bypass.

Migration `2026_09_28_000001_add_identity_review_workflows` adds `users.lock_version` (default 1), `reception_identity_windows`, `patient_identity_corrections`, and `patient_duplicate_reviews`. It changes no existing patient, visit, treatment, or visit-bound `correction_requests` row. Windows and corrections have composite dossier/facility/patient FKs. Restrictive actor/history FKs preserve provenance. Rollback refuses before any DDL if any workflow history exists; it never deletes history to roll back. Existing users receive version 1; historical registrations receive **no new correction window**.

Facility authorities:

| Permission | Operation |
| --- | --- |
| `reception.correct` | Direct identity correction under the server window |
| `reception.corrections.request` | Submit identity review without applying it |
| `identity_corrections.review` | List/read/reject correction requests |
| `reception_accounts.manage` | Manage eligible limited reception accounts |
| `patient_duplicates.review` | Local identity search, impact preview and duplicate requests/decisions |

Approval additionally requires the separately assigned **global** `patients.identity.review` or `patients.duplicates.merge`. A facility role containing the code alone is insufficient. Operators may assign a dedicated global role containing just these two codes to individually approved reviewers through `global_user_roles`; do not globally assign the broad hospital-admin role. Inspect the exact user, role, active permissions and local assignments before granting anything. No automatic global grant is provided. Verify with an ordinary reviewer that approval is 403 without the global assignment and succeeds only after explicit authorization.

## Identity correction contract

`GET /api/reception/cards/{dossier}/identity` returns minimal identity, patient/dossier versions, server deadline, eligible fields/reasons and the caller's own requests. It contains no medical history. The existing reception summary and this endpoint are audited only after successful authorization and lookup.

`POST .../{dossier}/correct` and `POST .../{dossier}/corrections` accept `facility_id`, UUID `request_id`, `patient_version`, `dossier_version`, reason and nonempty `changes`. Allowlist: first/family/father/mother names, birth date/accuracy, gender, phone/alternate phone and address. Direct correction also requires that each selected field was supplied by this author on initial reception registration. The server interval is **[initial save, initial save + 15 minutes)**: exactly at 15 minutes review is required. Successful corrections never extend the deadline. Other authors, active clinical contexts, another facility/context, independent references or changed identity versions require review. Existing patient selection does not start a window. Medical fields cannot be supplied.

Review requests preserve the submitted baseline and proposed fields; they do not change the patient. `GET /api/reception/reviews/corrections[/{id}]` exposes the current comparison to authorized local reviewers. `POST .../{id}/decision` accepts UUID, request `lock_version`, `decision: approved|rejected`, and reason. Approval locks and rechecks patient/dossier versions and shared-context fingerprint. A changed record returns 409 and needs a newly reviewed request; no automatic rebase. Rejection records its reason without changing identity. Audits store previous/new identity values and actor/time; review events appear in the authorized system movement log.

## Reception accounts

`GET /api/reception/reviews/accounts` and `PUT .../{user}` are facility-scoped. PUT accepts UUID, version, active flag, explicit allowed permissions and reason. The target must be a limited reception account in this facility only; global suspension of a multi-facility account is refused. User 1, protected super-admin holders, the acting account, accounts with independent/elevated authority and grants beyond the actor are blocked. Per-user local roles prevent changing other clerks; these private roles are not assignable/editable through ordinary role management. Global patient-search/create authority remains separate and is not granted by this screen. Freeze revokes every existing token; reactivation does not restore tokens. No deletion is used.

## Conservative duplicate resolution

`GET /api/reception/reviews/patients` searches minimal local identity only. `GET .../duplicates/preview` accepts canonical/duplicate dossier IDs. `POST .../duplicates` binds the preview fingerprint and a reason; the decision endpoint uses UUID/version/reason and explicit approval or rejection.

The only executable merge is a compatible, **empty draft duplicate identity** with no visits, independent dossier sections, treatment, imports, reports, blood-bank references, incoming aliases or another context. Actual FK references are inspected, including newly added modules. A canonical identity in another facility is also blocked. Any visit on the duplicate blocks the merge, including a first draft visit; records with clinical history remain intact for review. The decision rechecks the preview under row locks and uses existing `patients.status=merged` / `merged_into_id`. The old row, old code, context, request and audit remain; no clinical event moves and `DossierPurge` is never called. Exact old-code reception lookup and medical patient lookup resolve the canonical identity. Merged empty contexts cannot receive new dossier writes and do not appear as active identities. Import retains its existing explicit rejection of merged identities.

## Interface and verification

`/reception` adds a server-based countdown, selected-field correction draft, current values and request statuses. Refresh/conflict keeps the draft and requires explicit adoption and field reselection. `/reception-admin` provides correction comparisons, account decisions and duplicate impact reviews with the existing directory table/buttons. Facility/session changes abort pending reads/writes and remount the context. All API routes retain Sanctum, active-account checks, `api` ability and private/no-store responses; Next rewrites are explicit and numeric.

Functional checks:

```sh
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter=ReceptionReviewTest
```

The bootstrap requires an already migrated isolated MySQL/MariaDB test database and uses transaction rollback, not `migrate:fresh`. Build Next with the local Laravel test URL, start standalone on 3194 and the existing loopback-only `tests/Support/dossier-server.php` on 8194. Then:

```sh
node --test tests/reception-live.test.mjs tests/reception-review-live.test.mjs
```

These tests use real requests and MariaDB without API interception. The review suite additionally starts two independent PHP HTTP-kernel workers with a readiness barrier to test simultaneous version conflict and UUID replay. Synthetic tokens are revoked afterwards, and historical test rows remain on the isolated database. Never run these fixtures against a development/production database.

Deferred: anonymized statistics/export, two-minute logout and 2FA. Clinical record reconciliation and cross-facility duplicate merges are deliberately blocked, not reported as successful merges.

## Verification performed (2026-09-28)

- Safety gate and additive migration passed on existing isolated MariaDB 10.11.18, driver `mysql`, `blood_bank_cities_testing`, loopback port 13416. No database reset or production access. Populated rollback refusal is covered by the functional suite; a full destructive fresh-schema/rollback cycle was not run.
- Affected Laravel run: **169 tests, 142 passed, 27 failed, zero skipped, 7,803 assertions**. Passing groups: reception review 9, Phase 1 matrix 9, doctors 15, dossier API 9, dossier workflow 14, patient cards 15, patient workspace 4, import 42, pathology 14, users 3, audit 3, blood bank 1; oncology 4 passed / 27 failed.
- The same 27 oncology test names fail on a separate checkout of unchanged develop `86e48eea394a5122179c37e6d2e3194d540914ec` (baseline pathology/oncology: 45 tests, 18 passed, 27 failed). These tests submit legacy pathology inputs without the now-required clinic/doctor. They were not rewritten or presented as passing in this change.
- Real standalone Next → Laravel → MariaDB reception suites: **4/4 passed**, no skipped cases or mocked endpoints. Includes two independent PHP-worker races for conflicting versions and identical UUID replay. AppShell browser regressions: **9/9 passed** (these existing shell-only tests use fixtures and are separate from real API integration).
- After adding explicit merged-identity audit display, the final focused reception/Phase 1/audit rerun passed **21/21 tests, 637 assertions**, with no skips.
- TypeScript, production build, scoped ESLint, dirty Pint and `git diff --check` passed. All 17 reception routes registered.
- Repository-wide ESLint failed with **5 existing errors / 3 warnings**: conditional hooks in `AuditEventScreen` and `AuditLogScreen`, effect state update in `OncologyPanel`, and parsing errors in `oncology-live.test.mjs` / `simplified-treatment-live.test.mjs`. These files are unchanged. Their live suites were not run; this is not a claim of a completely green repository.
