# Permissions matrix — Phase 3

Based on develop `5c83a1f75cdbec8fd410ea33e9c428f62d4f7dda`, after merged PRs #58 and #59. No production database, deployment, FastAPI or 2FA changes.

## Operator activation and verification

Review the normal backup/deployment procedure. These commands are instructions for the operator, **not commands run on production for this PR**:

```sh
php artisan migrate --force
php artisan db:seed --class=PermissionMatrixPhaseThreeSeeder --force
php artisan route:list --path=statistics -v
php artisan route:list --path=session -v
php artisan access:super-admin
```

The Phase 1 and 2 roles must already exist. The repeatable seeder defines `statistics.view` and `statistics.export`, adds only these active permissions to `statistics` and `hospital_admin`, and creates no users, assignments or global grants. Disabled definitions stay disabled. Unrelated existing statistics-role authority causes a rollback and operator-review error rather than silent removal. Phase 1 remains rerunnable with these two approved additions. Existing protected super-admin assignment resolves active permissions dynamically; no user-ID exemption was added. Ordinary role management still cannot assign the protected role. Verify one statistics account has only its two local permissions and cannot read reception, cards, identified reports or accounts; view-only accounts must receive 403 on export.

Build Next with the intended **internal** `LARAVEL_API_URL` so the explicit `/hospital-api/statistics`, `/statistics/export/pdf|xlsx`, `/session`, and `/session/activity` rewrites are included. No wildcard proxy or new environment setting is introduced. `/statistics` uses `directoryFacility`, rejects unauthorized explicit facilities, and uses existing RTL/Cairo AppShell/table/button patterns. Statistics-only users land here after login. Clinical users retain their existing reports.

## Aggregate contract and definitions

`GET /api/statistics` and `POST /api/statistics/export/{pdf|xlsx}` require `facility_id`, `from_month`, `to_month` (`YYYY-MM`). All routes require active account, Sanctum `api` ability and live web session. Every request rechecks active facility membership and `statistics.view`; export also checks `statistics.export` after file generation before sending bytes. Responses are `private, no-store`; there is no public download URL.

Only 1–12 **completed calendar months**, from 1900 onward, in the facility timezone are accepted. Each month is a separate cohort with inclusive date boundaries. There is no cumulative range patient count, submonth filter, category filter, crossed filter or patient drilldown. Unknown query/body filters are rejected. Defaults use facility time, not device timezone; the server is authoritative.

| Section | Eligible facts and denominator |
| --- | --- |
| Visit status | Same-facility visits, `draft` or `complete`, not voided, actual `visit_date` in the month and not future. Each row is one visit; distinct patient IDs internally define patients. |
| Clinic | The recorded clinic on those same visits, including an unknown-clinic bucket. Directory identity, not name equality, defines a category. No inferred doctor/clinic assignment. |
| Gender | Current patient identity gender for eligible visits; unknown is separate before pooling. |
| Age | Age on the last day of the month from an exact DOB only: under 18, 18–39, 40–59, 60+, unknown. Estimated/missing/future-at-period-end DOB stays unknown. DOB is never projected. |
| Visit diagnoses/services/procedures | Nonvoid rows in `visit_diagnoses`, `visit_services`, `visit_procedures`, same facility, eligible **complete** parent visit, actual `diagnosed_on`/`performed_on` inside the month. Count rows, not quantities; repeated patients count once per section/month. Parent visit need not fall in the same month. |
| Occupancy | Unavailable: there is no reliable bed/admission-day denominator. No fabricated percentage. |

Dedicated blood-bank events, oncology appointments, dispensing quantities and free clinical text are not inferred as visit service/procedure events. Existing Catalog beneficiary and other report definitions are unchanged. Current directory labels and corrected current identity demographics may change historic aggregates; actual event dates, not creation/update time or reporting periods, define inclusion. Do not sum distinct-patient counts across rows, metrics or months; individuals may occur in several categories.

JSON contains only report title, facility name/timezone, filters, policy, months and protected sections (`key,title,definition,suppressed,patients,events,rows[label,patients,events]`). Internal subject sets and directory keys never leave the service. No patient identifiers/codes, names, contact/address, attachments, links or free medical notes enter JSON, workbook metadata/hidden sheets or PDF.

## Approved privacy policy

The approved alternative to suppressing an entire month is **complementary pooling**, with threshold five distinct patients, independently for each indicator/month:

1. Categories with fewer than five distinct patients are pooled under “فئات مجمّعة لحماية الخصوصية”; their component labels are not released.
2. Recompute the union of patients in the pool, never add category patient counts. If the union is below five, absorb an additional visible category (smallest patient count, deterministic ordering) until the pool qualifies. This avoids publishing a small residual obtainable by subtracting other rows from a released total.
3. A nonempty entire cohort below five has null patient/event totals and no rows. An empty cohort returns zero. A qualifying total is retained; no unprotected totals are stored in export-only fields.
4. Screen and both exports consume the **same protected service projection**. No alternative raw-detail endpoint exists. Requests exceeding 100,000 underlying events per indicator/month or 1,000 output rows fail with 422 rather than truncate.

This is a risk-reduction policy, not differential privacy or a guarantee against auxiliary knowledge, changes between snapshots, or correlations across separate indicators/months. User-controlled intersections are deliberately absent. More flexible queries or externally published longitudinal datasets need a separate privacy review; do not remove pooling in exports or introduce hidden exact totals.

## Report handling

PDF uses embedded local Cairo through the existing private report renderer. XLSX is RTL, Cairo, A4 landscape, width-fit with unconstrained page height, explicit print area, repeated header row, frozen headers, filters and measured wrapped row heights. Counts are numeric; month labels and **all** other text use explicit string cells, including values starting with formula characters. No patient raw sheet or external links. Issuance time, facility, period, policy and each metric definition are included. Anonymous export audit contains actor/facility, months and format only. Files are returned in memory; existing mPDF private working storage remains outside public serving.

## Two-minute web idle sessions

Migration `2026_09_28_000002_add_web_token_idle_deadline.php` adds nullable microsecond-precision `web_idle_deadline` and `web_expired_at` to Sanctum tokens. It preserves token hashes and assignments. Existing tokens receive **one 120-second grace period at migration time**, not on first use. Deploy backend and frontend in the same release; old clients cannot renew and must sign in again. Login is the only application production token issuer found in this repository; every login-issued token gets a deadline regardless of `device_name`. Operator-created noninteractive PATs with null deadline remain separate; no public endpoint offers this exemption. Existing external/manual integrations must be inventoried by the operator before rollout because all preexisting tokens receive the grace window.

Both protected API groups include idle middleware. The token row is locked, server time read **after acquiring the lock**, expiration checked before extending. The idle duration is now configurable (default120 seconds) under the [facility and system session policies](facility-settings-and-guides.md). At the exact effective deadline the token is invalid. GET `/session` and all ordinary reads/writes/polling never extend. POST `/session/activity` extends only a still-valid token under the current policy. Increasing policy duration cannot revive an expired/revoked token. Account freezing still revokes every token; idle expiration affects only the current device. Super admin has a separate configurable policy, not an exemption.

The browser watches trusted keyboard/pointer/input/wheel interactions while visible, coalescing them at most once per 20 seconds. There is no unconditional heartbeat. An accessible warning appears during the last 30 seconds with explicit continue. The server cannot attest that an authorized custom client represents a human; the UI signal is not an authentication factor. Timers, focus and peer-tab notifications only check, never renew. BroadcastChannel uses a digest to scope messages to the same token, never broadcasts the bearer itself. Device sessions remain independent.

Sleep/clock gaps force revalidation; the mounted draft is hidden and inert while checking, preserving it if another tab extended the session. Failure at the local conservative deadline clears the session rather than granting offline time. Confirmed expiry aborts centrally tracked requests, clears the tab token, unmounts identity/UI memory and redirects with “انتهت الجلسة بسبب الخمول”. No patient draft is saved to persistent storage. A different login mounts a new tree.

Before the first server snapshot, a transport failure is not proof of expiry. Keep personalized content hidden and offer retry/local sign-out without deleting an unproven-expired token. A real login followed by immediate full navigation is covered, because navigation can abort that initial request.

A write admitted before expiry may finish and return its committed success; middleware never converts it into a false 401 afterwards. If transport is lost, the client does not replay automatically. UUID/idempotency and version rules stay in the existing writers. After reauthentication the operator must check the record before repeating an action whose outcome was unknown; no automatic draft recovery is promised. Export is read-only and rechecks authorization/session at release.

Expiry marker and facility audit are committed once under the row lock. Repeated rejected requests do not repeat the event. For users with no authorized facility, no facility is invented: the marker remains on the token and a minimal `web_session_expired` operational log records actor/time/reason after commit. No bearer/hash/password or patient content is logged.

Rollback refuses while any token has a web deadline, to prevent reviving expired sessions. The operator must explicitly revoke affected web tokens during a controlled maintenance procedure before rollback. Do not remove deadlines from live tokens or run a blind rollback as an escape from expiry. No automatic scheduler or production setting is changed.

## Verification commands and limits

Run only on an isolated MySQL/MariaDB database whose name passes the existing safety gate:

```sh
php artisan test-db:check --connect --env=testing
php artisan migrate --env=testing
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter='AnonymousStatisticsTest|WebIdleSessionTest|ReceptionReviewTest'
```

The preserving bootstrap requires an already migrated test database and rolls test transactions back; it never fresh-resets a populated database. `AuthMigrationTest` is explicitly destructive and must be run separately on a disposable database, never included under the preserving bootstrap. Its rollback now explicitly revokes its own freshly created token first.

For real integration, freshly build Next with `LARAVEL_API_URL=http://127.0.0.1:8194/api`, copy standalone assets, serve standalone on 3194 and loopback-only Laravel `tests/Support/dossier-server.php` on 8194 with `APP_ENV=testing`. Then run `node --test tests/statistics-live.test.mjs`. It uses real login/requests, no API interception, four roles, isolated synthetic history and two independent PHP workers with a readiness barrier. Synthetic users are disabled and tokens revoked afterwards; history is retained locally. `idle-session.test.mjs` separately simulates sleep/offline/client clocks with mocked responses, not as a substitute for real integration.

Optional `node tests/render-statistics.mjs` uses the existing local PDF.js/Cairo QA tools to extract text and render all generated PDF pages to ignored `test-results/statistics`. Test outputs/credentials are not committed.

After real exports, `php tests/Support/verify-statistics-workbooks.php` (backend working directory) reopens both synthetic workbooks without bootstrapping Laravel or connecting to a database.

## Actual verification on 2026-09-28

Only mysql / MariaDB 10.11.18 at `127.0.0.1:13416`, database `blood_bank_cities_testing`, was used. The existing safety command passed before database work. No production connection, fresh/reset of this populated database, deployment or merge was performed.

| Check | Actual result |
| --- | --- |
| Affected Laravel suite: statistics, web idle, phase-one permissions, phase-two reception review, auth, audit, facility and directory reports | 81 tests: **79 passed, 2 failed, 0 skipped**, 3,118 assertions. Both failures reproduced on unchanged develop `5c83a1f75cdbec8fd410ea33e9c428f62d4f7dda` against the same isolated database. |
| Final focused Laravel run after safe error-response changes | **17 passed**, 566 assertions: `AnonymousStatisticsTest`, `WebIdleSessionTest`, `AuditLogApiTest`. |
| Real production Next → Laravel → MariaDB; statistics plus phase-one and phase-two reception suites together | **8 passed, 0 skipped**; no API mocking or interception. Four roles, real login/exports, facility isolation, account freeze, UUID replay, independent concurrency workers and session tabs. |
| Browser suites: idle, auth UI, AppShell, dashboards, reports, audit (mocked API) | **32 passed, 0 failed, 1 skipped**. The skipped opt-in legacy demo-login case needs `AUTH_LIVE_TEST`; actual login is independently covered in the real suite above. |
| TypeScript / scoped ESLint / production Next build | Passed. The standalone server was restarted from the new build with the local Laravel URL. |
| Pint / git diff whitespace checks | Passed for the changed files. |
| Real short/long PDF | 2 / 15 pages rendered and inspected locally; Cairo embedded, Arabic RTL, no blank pages or private fixture values in extracted content. |
| Real short/long XLSX | Both reopened with PhpSpreadsheet: 21 / 98 rows; print area, RTL/Cairo, repeated/frozen headers, filter, A4 landscape, width fit, numeric/string types, formula safety and row-height bounds passed. |
| Excel / LibreOffice print preview | Excel 16.0 build 20326 opened the long workbook, but PDF export hung in the local printer environment and its dedicated process was stopped. LibreOffice was unavailable. **Excel visual/print preview is not verified**; no application failure is inferred from that environment limitation. |
| Migration | Additive migration applied locally, then the unpublished timestamp precision refined to DATETIME(6) without data loss; rollback refusal with web tokens tested. A fresh full migration/rollback cycle and destructive `AuthMigrationTest` were **not run** against this populated database. |

Baseline failures, not claimed as successful or repaired outside scope:

- `AuthApiTest::access_filters_deduplicates_sorts_and_stays_dynamic_in_one_query`: expects one query, receives two with the existing global-access lookup.
- `PermissionMatrixTest::system_assignment_is_explicit_dynamic_protected_and_idempotent`: expects 200, receives 403 on the existing `/dossiers/options/patients` fixture.

The full oncology suite and repository-wide lint were not rerun; known unrelated issues remain outside this phase. These results do not claim a fully green repository suite or completed Excel print preview. Local synthetic screenshots at 390, 768 and 1440px and generated reports remain ignored artifacts, not repository or public patient-data attachments.
