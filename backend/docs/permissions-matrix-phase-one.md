# Permission matrix — Phase 1

`/reception` is separate from medical card reads. Existing medical, import,
oncology and blood-bank workflows retain their permissions.

## Roles

| Role | Facility permissions | Global authority |
| --- | --- | --- |
| `data_entry` | `reception.view`, `reception.register` | Separate explicit assignment for `reception.patients.search/create` |
| `hospital_admin` | Explicit administration/report/audit allowlist in `PermissionMatrixPhaseOneSeeder::ADMIN`; authorized medical reads, not clinical writes | None by default |
| `statistics` | None in Phase 1 | None |
| `super_admin` | All active permissions in every active facility after protected assignment | All active permissions after protected global assignment |

Ordinary facility membership never substitutes for global directory authority.
Permissions from multiple roles are combined: do not assign medical/report roles to
a clerk who must remain restricted. Definitions assign no users automatically.
Existing overprivileged `data_entry`/`statistics` definitions stop the seeder
atomically for operator review rather than silently removing previous grants.

## Operator deployment

After normal backup and release review, the operator runs:

```sh
php artisan migrate --force
php artisan db:seed --class=PermissionMatrixPhaseOneSeeder --force
php artisan access:super-admin --apply --reason="approved assignment" --execution-reference="CHANGE-58/operator-or-job-reference"
php artisan access:super-admin
```

Migrations: `2026_09_27_000001_protect_system_super_admin_role.php` and
`2026_09_27_000002_allow_operator_audit_without_user.php`.
No production commands were executed by this change. The assignment requires the
existing user 1, an active reserved role and an active facility for auditing. It
never creates or changes the user's identity, credentials or active state. Inactive
accounts remain unable to authenticate. Existing reserved-role assignments to other
users stop the transaction for explicit review. Repeated execution preserves prior
assignments without duplicating them or unchanged audit events.

New command audit records use `actor_id=null`: a terminal process is not an
authenticated application user. `new_values.user_id=1` remains the beneficiary,
with `source=operator_command`, protected role and mandatory `execution_reference`
(1–255 characters, an operator/job/change reference, not a password or token).
This is an operator-supplied reference, not verified application-user identity.
The audit list/detail UI explicitly shows «أمر طرفية — ليس جلسة مستخدم» and separate
beneficiary/reference/role facts. The reason remains a separate explanation.
The actor FK remains enforced for non-null users. Existing events are not rewritten;
the new migration's rollback refuses while null-actor events exist rather than
inventing actors or deleting history. Re-running assignment does not rewrite old
events or manufacture a new assignment. The command without `--apply` is read-only:
it reports assignment, active account and effective access. Verify `/api/user`
through normal login afterward. Future active facilities/permissions are resolved
on every request. No ID, username or role-name bypass exists: the protected marker
and explicit global assignment are both required. Ordinary role/account endpoints
cannot assign/edit the reserved role or remove its holder. Rollback refuses to drop
the marker while a protected role exists; revocation needs separately reviewed
maintenance, never automatic deletion of assignments.

Ordinary users receive facility roles through the current administration UI. Global
reception authority requires a separate operator-reviewed assignment. This repeatable
SQL for an existing clerk grants no facility access by itself:

```sql
SET @clerk_id = NULL; -- replace with the reviewed existing user ID
INSERT INTO global_user_roles (user_id, role_id, created_at, updated_at)
SELECT u.id, r.id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
FROM users u CROSS JOIN roles r
WHERE u.id = @clerk_id AND u.is_active = 1
  AND r.code = 'data_entry' AND r.is_active = 1
  AND r.is_system_super_admin = 0
  AND NOT EXISTS (SELECT 1 FROM global_user_roles g
                  WHERE g.user_id = u.id AND g.role_id = r.id);
```

Review the account's entire assignment set. The clerk/statistics baseline contains
no medical read or identified report permission.

## Reception and audit contract

- `GET /api/reception/options`: capabilities and facility-local date.
- `GET /api/reception/patients`: limited global identity search with facility access;
  minimum three characters, at most ten results, 30 requests/minute/user. Bound
  parameters and literal wildcard escaping. Only ID, code, names, birth date, gender
  and selected-facility card ID are returned.
- `GET /api/reception/cards/{dossier}`: minimal registration summary.
- `POST /api/reception/registrations`: reuses the personal writer, transaction,
  code reservation, durable UUID replay and first draft visit. Explicitly selected
  existing patients retain demographics. Duplicate local context is rejected.
  New system-generated codes are not national IDs.

Active Sanctum Bearer with `api`, active facility and current permissions are checked
server-side; responses are private/no-store. Reception does not return history,
diagnoses, treatment, attachments or reports. Medical/report/delete APIs reject the
reception-only role. The UI reuses directory components/facility selection, preserves
invalid drafts and focuses the first invalid field. Reception-only login goes directly
to reception.

Successful card/nested visit/detail-tab and limited reception reads emit `opened`
after authorization and lookup. Metadata contains only surface and optional visit ID;
the audit row holds card ID, actor, facility and time. List/search and audit reads are
excluded. Denied/not-found requests never become successful opens. Authorized card
and system audit pages expose these events.

## Verification

Use a separate MySQL/MariaDB testing database after `test-db:check --connect`.
`PermissionMatrixTest` covers API boundaries, revoked/inactive access, assignment
reruns, dynamic super grants, protected mutations, absent-user lookup, read auditing
and rollback refusal. Existing dossier/user/role tests also cover shared behavior.
`frontend/tests/reception-live.test.mjs` uses real Next on 3194, Laravel test router
on 8194 and MariaDB, with no API mocking. Build with
`LARAVEL_API_URL=http://127.0.0.1:8194/api`, copy standalone static/public assets,
start fresh servers, then run `node --test tests/reception-live.test.mjs`.
The fixture preserves synthetic historical rows and revokes/deactivates its own
test account afterward. Never run against a non-testing database.

Phase 2 correction windows/approvals/deletion changes, Phase 3 anonymous statistics
and inactivity logout, and 2FA are outside this change.

### Initial verification on 2026-09-27 (31eed93)

- Base: latest fetched `develop`, `3ef3476113d836e9a4f49cb52fa084b55d65ef7d`.
- Safety check and migration upgrade passed on local MariaDB 10.11.18,
  driver `mysql`, database `blood_bank_cities_testing`, loopback port 13416.
  Existing data was preserved; no reset or production connection.
- Final Laravel selection: 48 passed, 3522 assertions (PermissionMatrix, UserApi,
  RoleApi, DossierApi, DossierWorkflow, CatalogApi, BloodBank). Transactions used the
  existing `tests/Support/preserve-database.php` bootstrap.
- Real reception browser integration: 1 passed, no mocks/skips. The initial run
  exposed an unstable accessible name after validation; explicit input labels fixed
  it and the final run passed through the rebuilt standalone server.
- Auth UI: 8 passed, 1 optional real-login case skipped because its separate login
  fixture was not configured. These existing tests use mocks; they are not counted
  as real integration. An initial invocation pointed at unused port 3000; rerunning
  against the actual standalone port 3194 produced these results.
- TypeScript, scoped ESLint, production build, Pint dirty check and diff check passed.
- Full ESLint: 5 errors and 3 warnings in unchanged develop files (AuditEventScreen, AuditLogScreen,
  OncologyPanel, DossierScreen and existing oncology/stock test files); not repaired
  as unrelated work. No lint errors remain in the changed files.
- DoctorApiTest: all 15 cases blocked during fixture setup because it inserts the
  fixed `super_admin` code already present in this populated testing database. This
  is not a passing regression run. The new matrix test did verify doctor global
  capabilities through the actual API. The full Laravel suite was not run.
- Migration up ran on the populated test database; the protected rollback refusal
  was tested. A full destructive down/up cycle was not performed.

### PR #58 corrective verification

The matrix seeder now invokes `DossierCompletionPermissionsSeeder` before grants,
and verifies that every `ADMIN` code has a definition. Existing inactive permissions
are not reactivated. `PermissionMatrixFreshTest` starts without permission seeders,
checks the complete administrator allowlist and downloads an actual XLSX via the API.
Before the fix it failed specifically because `dossiers.export` was missing; afterward
it passed (1 test, 7 assertions) on newly created `matrix_review_20260927_testing`,
MariaDB 10.11.18/mysql, 127.0.0.1:13417, after the safety gate. The original local
test account could not provision a database; a separate existing local test-server
provisioning connection was used. No existing database was deleted.

The audit regression failed before the fix (`actor_id` was 1 instead of null).
It now checks terminal attribution, beneficiary, mandatory execution reference,
list/detail response facts, unchanged assignment replay, and rollback refusal.
The existing audit UI consumes these same actor/changes fields: both browser tests
passed, including distinct display of the terminal actor, beneficiary and reference.
These two UI tests use API fixtures, not real integration.

All 15 DoctorApiTest cases now pass. Its fixture uses a unique ordinary role instead
of assuming `super_admin` is absent, reuses existing directory definitions and scopes
counts/deletes/grant commands to its own fixtures. No doctor application behavior
changed. The first repaired-fixture run exposed four whole-database count assumptions;
these were corrected rather than deleting preexisting test data.

Final populated-database selection: DoctorApiTest, PermissionMatrixTest, RoleApiTest,
UserApiTest and AuditLogApiTest: **32 passed, 1365 assertions**, no skips, on
`blood_bank_cities_testing` at 127.0.0.1:13416 using the preserving bootstrap.
The nullable-actor migration also succeeded on this populated MariaDB database.
The earlier 15-test DoctorApi blocker is resolved by this corrective pass.
TypeScript, ESLint for the changed browser test, Pint dirty check and
`git diff --check` passed in this pass.

No frontend implementation changed; browser checks used the existing standalone
build. No new real Next→Laravel integration or full Laravel suite was run in this
focused pass. The initial verification and its full-lint limitations remain recorded
above rather than being presented as newly executed checks.
