# Dashboard and user-management entry repair

Base: develop `89d1055979b3839b13e0a141a262db4aaa221dfd` (includes merged #60). No backend production logic, permissions, seeders, migrations, authentication policy or production configuration changes.

## Proven causes

1. `DashboardScreen.tsx` fetched the catalog but sent a null facility directly to `dashboardDetail` for `/dashboard/general`. Laravel correctly requires a facility for this descriptor and returned 422. It now resolves **only an omitted** facility using the requested descriptor and existing `dashboardPath/default_facility_id`, replaces the URL, and lets that URL initiate the detail request. Explicit invalid/disallowed facilities never silently fall back; the server remains authoritative. Multiple allowed facilities retain the selector. Empty access has a clear state and the existing account menu/logout remains available.
2. `next.config.ts` omitted `users/roles` and numeric `users/roles/:role`. Before correction, real `GET users/roles` returned Laravel 200 JSON but Next 404 HTML. Both explicit rewrites now preserve methods, query, body and Bearer authorization; no wildcard proxy. The numeric user route and `users/options` retain their existing behavior.
3. `UsersScreen.tsx` passed `envelope=true` for options while consuming `options.data.capabilities` as though it were the unwrapped data. Real options and user-list requests already returned 200 through both Next and Laravel. The extra wrapper hid create/role controls and prevented the role-list request. Options now unwrap the data object; paginated users and role lists keep their envelopes. Failed/reloading options never retain stale capability controls, show a specific loading/error state and offer retry. List/role refresh errors also remain visible when old rows exist.

`navigation.ts` and `Sidebar.tsx` were inspected: home is the existing fixed `/dashboard/general` entry, and the logo enters `/`. Both now resolve through the server catalog; no hardcoded facility is added to navigation and no AppShell redesign is needed. The direct URL is covered independently of sidebar navigation. Users keep `directoryFacility` and the existing server ordering for omitted facilities; malformed/duplicate explicit IDs are rejected visibly before a request. Workspace keys and request cancellation preserve facility/user isolation.

These are reproduced source defects, not a claim about an inspected production deployment. No production connection was made. A stale Next build can independently retain the old rewrites after deployment, so copying source files alone is insufficient.

## Local verification

Safety gate: `php artisan test-db:check --connect --env=testing` passed for mysql / MariaDB 10.11.18, `blood_bank_cities_testing`, `127.0.0.1:13416`. No reset/fresh/drop of the populated database. Synthetic actors have only explicitly selected existing permissions; the fixture never seeds/enables permission definitions or alters existing accounts. It verifies the existing user 1 and local/global assignments are unchanged, then disables its synthetic users and revokes their tokens while retaining audit history.

Before repair, a fresh standalone build from develop was tested through Next → Laravel → MariaDB: 6 cases, 2 passed and **4 failed** (single/multiple facility entry, role proxy contract, user-management controls). Options/list were 200 in both paths; roles were upstream 200 versus proxy 404 HTML. A separate browser regression also proved that a failed options request had no visible error/retry. Older user UI tests needed the existing session-check fixture and waits for completed list refreshes; no auth/idle bypass was introduced in live tests.

Final results on 2026-09-28:

| Check | Result |
| --- | --- |
| Real Next → Laravel → MariaDB suite | **7 passed, 0 failed, 0 skipped**, 84.720s; single/multiple default facility, explicit rejection, user options/list/roles equality, role GET/POST/PUT, protected roles/users, unchanged user 1 snapshot, facility switch, idle rejection and real logout. |
| Laravel affected suites | **21 passed**, 639 assertions, 9.89s. |
| Mocked browser suites (users, dashboards, idle, AppShell) | **25 passed, 0 failed, 0 skipped**, 60.768s; includes visible options transport error and successful retry. |
| TypeScript | Passed. |
| ESLint for changed frontend files | Passed. |
| Production Next build | Passed; fresh standalone restarted with matching assets for the live run. |
| Pint for the test fixture | Passed. |
| git diff --check | Passed. |

All requested targeted checks ran; no environment-blocked check remains. The complete unrelated repository test suite was not run. No claim is made about the running production build or its environment, which were not accessed.

Commands (backend first, then frontend working directory):

```sh
php artisan test-db:check --connect --env=testing
php artisan test --env=testing --bootstrap=tests/Support/preserve-database.php --filter='DashboardApiTest|PrivateDashboardResponseTest|UserApiTest|RoleApiTest|WebIdleSessionTest'
php vendor/bin/pint --test tests/Support/dashboard-users-live.php

node --test --test-concurrency=1 tests/dashboard-users-live.test.mjs
node --test --test-concurrency=1 tests/users.test.mjs tests/dashboards.test.mjs tests/idle-session.test.mjs tests/app-shell.test.mjs
npx tsc --noEmit
npx eslint next.config.ts src/features/dashboards/DashboardScreen.tsx src/features/users/UsersScreen.tsx tests/users.test.mjs tests/dashboard-users-live.test.mjs
npm run build
git diff --check
```

The live suite uses fresh production standalone Next on loopback 3194, built with `LARAVEL_API_URL=http://127.0.0.1:8194/api`, and the safety-gated `tests/Support/dossier-server.php` on 8194. It uses no `page.route` or API mocking. Set `TEST_BASE_URL=http://127.0.0.1:3194` and `PLAYWRIGHT_CHANNEL=chrome` for the separate mocked browser suite. Real login/logout retain the 120-second deadline; repeated login scenarios use separate synthetic actors rather than disabling/flushing throttling.

## Deployment instructions (not executed)

1. Build frontend with the operator's correct internal `LARAVEL_API_URL` ending in `/api`: `npm ci`, then `npm run build`.
2. Package the new standalone server **with its matching `.next/static` and `public` assets**, following the existing deployment layout, then restart that Next process. Do not reuse a pre-fix standalone build; rewrites are baked into the build.
3. Verify authorized direct `/dashboard/general` redirects to a permitted facility before detail, and `/users` loads `options`, users and roles as JSON. Verify role GET/POST/PUT pass through to Laravel with the expected authorization failures for unauthorized accounts.

No new permission grants, seeders or migrations are required for this repair. Keep Laravel's facility checks, protected super-admin assignments and 120-second web idle middleware enabled. Never use a generic proxy or disable idle expiry to work around routing.
