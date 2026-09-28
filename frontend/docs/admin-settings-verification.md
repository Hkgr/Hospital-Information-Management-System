# Administrative portals, guides and session settings

Based on develop `7ce69698e40d7b7fd5a16bba6907e9ba6ce6917d` (PR #61). See [backend activation and policy contract](../../backend/docs/facility-settings-and-guides.md). This change does not deploy, alter production, or change FastAPI.

At the user's final direction, all nine facility dropdowns were removed. The current hospital name is read-only, and the existing authorized default is resolved automatically. The database/API scope and denial of explicit unauthorized IDs remain unchanged. Context-isolation regressions now navigate actual URLs rather than a removed selector. Older directory mock fixtures were given explicit noninteractive session responses so their clinical UI checks work with the existing idle middleware contract; they do not test real authentication.

## Functional verification

Environment: PHP/Laravel with the MySQL driver, isolated local MariaDB **10.11.18**, database `blood_bank_cities_testing`, loopback `127.0.0.1:13416`. The database safety check passed. No SQLite or production connection was used.

| Check | Actual result |
| --- | --- |
| `php artisan test-db:check --connect --env=testing` | Passed |
| Affected Laravel selection below | **69 passed, 1203 assertions**, 71.78s; two explicit exclusions described below |
| Token-lock subprocess test after adding missing-directory handling | **1 passed, 6 assertions**, 4.21s |
| `settings-live.test.mjs` | **4 real integration cases passed** through rebuilt standalone Next → Laravel → MariaDB; no API interception |
| `idle-session.test.mjs` | **6 browser cases passed using mocked session responses**; timing, sleep, offline, warning and peer-tab behavior, not a substitute for real integration |
| Combined `dashboard-users-live.test.mjs`, `settings-live.test.mjs`, `statistics-live.test.mjs` after removing facility selectors | **15 real integration cases passed, 0 failed, 0 skipped**, 226.383s; includes default context, protected role rewrites, real tab renewal/expiry, reception replay and two PHP workers |
| Affected directory/dashboard/audit/report browser suites after removing selectors | **81 functional cases passed, 0 failed, 0 skipped**, 200.806s; API fixtures, not live integration |
| Audit suite after correcting conditional hook and rebuilding again | **3 passed, 0 failed, 0 skipped**, 8.136s; includes default → denied → default navigation |
| `npx tsc --noEmit` | Passed |
| ESLint for changed/new TypeScript, TSX and browser test files | Passed |
| `npm run build` | Passed; fresh standalone artifact rebuilt with the local Laravel API URL |
| `php vendor/bin/pint --dirty --test` | Passed |
| `git diff --check` | Passed |

Laravel command, after the safety check and reviewed additive migrations:

```sh
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter='^(?!.*(activity_log_cannot_be_written|access_filters_deduplicates_sorts_and_stays_dynamic_in_one_query)).*(FacilitySettingsTest|SessionPolicyConcurrencyTest|WebIdleSessionTest|UserApiTest|RoleApiTest|DashboardApiTest|ReceptionReviewTest|AnonymousStatisticsTest|AuthApiTest)' --compact
```

Final browser command:

```sh
node --test --test-concurrency=1 tests/settings-live.test.mjs tests/idle-session.test.mjs
```

After the single-hospital UI change, the combined real run used `tests/dashboard-users-live.test.mjs tests/settings-live.test.mjs tests/statistics-live.test.mjs`. The 81-case mocked run used `tests/clinics.test.mjs tests/doctors.test.mjs tests/directory-lifecycle.test.mjs tests/directory-export-readiness.test.mjs tests/reports.test.mjs tests/audit.test.mjs tests/dashboards.test.mjs`, with `TEST_BASE_URL=http://127.0.0.1:3194` and `PLAYWRIGHT_CHANNEL=chrome`.

Scoped lint exposed an existing conditional hook in `AuditLogScreen`: a denied context could change the number of hooks. The query hook now runs consistently with a null path when unauthorized, issuing no request and rendering no prior rows. A further functional regression covers default → denied → default URL navigation.

The live fixture grants its synthetic administrator separate explicit patient-directory authorization solely to exercise the reception form. Newly created reception users do **not** receive a global grant. Their pending authorization message and a real 403 are checked. Fixture cleanup disables its accounts and revokes their tokens while retaining history.

Coverage includes permission subsets, role deactivation, protected/internal roles, facility tampering, read-only settings, global policy protection, audit before/after values, unknown fields, minute bounds, default fallback, optimistic conflicts, preview without writes, polling versus activity, shortest accessible facility, separate super-admin policy, reduction followed by increase, removal of a previously observed facility, integration-token exemption and expiration while waiting for a token lock. Existing freeze/revocation and protected-account tests remain in the affected suites.

## Test-database incident and explicit exclusions

An earlier expanded run had **69 passes and 2 failures**. One failure was this branch's test fixture assuming a preexisting protected role; the fixture now creates its own isolated role and passes. The other is the existing `AuthApiTest::test_access_filters_deduplicates_sorts_and_stays_dynamic_in_one_query`: it expects one query while the current system-role/access implementation makes two. That unrelated query-count assertion was not changed and is excluded from the final selection.

The existing `AuthApiTest::test_login_and_logout_succeed_when_activity_log_cannot_be_written` renames the audit table. MariaDB implicitly commits that DDL, which invalidated Laravel's transactional refresh state and triggered an unintended `migrate:fresh` in the **local testing database** on the following test. The local testing database was reset; its earlier synthetic contents were not restored. Production was never contacted or modified. This DDL test is also excluded from the final selection, not reported as passing in that selection.

`preserve-database.php` and `Tests/TestCase.php` now fail before automatic refresh if a test loses its transaction boundary. A direct guard probe verified rejection and an unchanged migration count. DDL tests still require a separately disposable database; the guard does not roll back DDL. No complete-suite pass is claimed.

Both additive migrations ran on MariaDB. Functional rollback refusal was verified while managed web tokens exist; a full destructive rollback/reapply cycle was **not** run on the populated test database. Operator migration and roll-forward limitations are documented in the backend guide.

An initial Next build encountered Windows `EBUSY` while the previous local standalone process was running. Stopping that local process and rebuilding resolved it. Superdesign draft generation was unavailable because of exhausted credits; the shipped UI reuses the actual project components and was inspected in the browser.

## Visual evidence

Chrome/Playwright captures of the actual production build with synthetic data, local Cairo and RTL. Screens were exercised at 390, 768 and 1440px. Horizontal page overflow assertions passed; tables retain local scrolling. These are current-build captures, not a before/after comparison. The warning capture uses the timing fixture; all other captures use real Laravel responses.

| Screen | 390px | 768px | 1440px |
| --- | --- | --- | --- |
| Settings | [Phone](images/admin-settings/-settings-390.png) | [Tablet](images/admin-settings/-settings-768.png) | [Desktop](images/admin-settings/-settings-1440.png) |
| Permission-specific guide | [Phone](images/admin-settings/-guide-390.png) | [Tablet](images/admin-settings/-guide-768.png) | [Desktop](images/admin-settings/-guide-1440.png) |
| Users | [Phone](images/admin-settings/-users-390.png) | [Tablet](images/admin-settings/-users-768.png) | [Desktop](images/admin-settings/-users-1440.png) |
| Create user dialog | [Phone](images/admin-settings/user-dialog-390.png) | [Tablet](images/admin-settings/user-dialog-768.png) | [Desktop](images/admin-settings/user-dialog-1440.png) |
| Role dialog | [Phone](images/admin-settings/role-dialog-390.png) | [Tablet](images/admin-settings/role-dialog-768.png) | [Desktop](images/admin-settings/role-dialog-1440.png) |
| Reception | [Phone](images/admin-settings/-reception-390.png) | [Tablet](images/admin-settings/-reception-768.png) | [Desktop](images/admin-settings/-reception-1440.png) |
| Reception validation | [Phone](images/admin-settings/reception-validation-390.png) | [Tablet](images/admin-settings/reception-validation-768.png) | [Desktop](images/admin-settings/reception-validation-1440.png) |
| Reception review | [Phone](images/admin-settings/-reception-admin-390.png) | [Tablet](images/admin-settings/-reception-admin-768.png) | [Desktop](images/admin-settings/-reception-admin-1440.png) |
| Statistics | [Phone](images/admin-settings/-statistics-390.png) | [Tablet](images/admin-settings/-statistics-768.png) | [Desktop](images/admin-settings/-statistics-1440.png) |
| General dashboard | [Phone](images/admin-settings/-dashboard-general-390.png) | [Tablet](images/admin-settings/-dashboard-general-768.png) | [Desktop](images/admin-settings/-dashboard-general-1440.png) |

[Idle warning at 390px](images/admin-settings/idle-warning-390.png). Spacing is scoped to administrative portals; the shared clinic field styling and native modal are reused. The warning sits in document flow rather than covering the bottom action buttons. No clinical workspace redesign is included.
