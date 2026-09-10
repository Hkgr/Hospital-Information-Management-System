# Dashboard foundation

Laravel owns dashboard definitions, availability and data authorization. FastAPI is unchanged.
The existing login/user/logout JSON contracts and Sanctum Bearer token storage are preserved.
No dashboard tables or migrations are added.

## Authentication and navigation

After a successful login, Next.js replaces the Login URL with `/`. The shared authenticated
layout verifies `GET /api/user`, then the entry resolver reads `GET /api/dashboards` and
replaces `/` with the allowed default's local path, currently `/dashboard/general`.
Opening `/` without a token goes to `/login`. A direct dashboard URL or page reload repeats
authentication and dashboard authorization before displaying personal data.

Only compiled keys in `frontend/src/features/dashboards/registry.ts` can resolve to paths.
The API does not supply redirect URLs. `next`, `returnTo`, unknown keys, protocol-relative
URLs and JavaScript URLs cannot control navigation. An unsupported or missing default
shows an explicit empty state with retry, without a redirect loop.

## API contract

Both endpoints require `Authorization: Bearer <token>` and an active account; the token
must have the `api` ability. Send `Accept: application/json`. Access is recomputed from
active facilities, roles and permissions using `UserAccessContext` on every request.
All dashboard responses, including failures, use `Cache-Control: private, no-store`
and `Vary: Authorization`. Do not configure shared caching for these endpoints.

### GET /api/dashboards

Optional `facility_id`: positive integer, at most 2147483647. When present, the catalog is
restricted to that accessible active facility. Array/null/invalid IDs and other input
parameters are rejected with 422. Inaccessible and nonexistent facility IDs both yield 403.

```json
{
  "data": {
    "dashboards": [
      {
        "key": "general",
        "title": "لوحة التحكم",
        "requires_facility": false,
        "facilities": [],
        "default_facility_id": null
      }
    ],
    "default_dashboard_key": "general"
  }
}
```

Each descriptor contains only facilities eligible for that dashboard. For restricted
definitions `default_facility_id` is the first eligible facility in the existing stable
facility ordering. Dashboards sort by ascending priority, then key. The first **allowed**
dashboard is the default. An empty registry or no allowed definitions returns
`{"data":{"dashboards":[],"default_dashboard_key":null}}`.

### GET /api/dashboards/{key}

Exact, case-sensitive key lookup; dots cannot traverse configuration. Optional `facility_id`
follows the same validation as the catalog. A restricted dashboard requires an explicit
facility ID; if otherwise allowed but none is supplied, the response is 422.

```json
{
  "data": {
    "dashboard": {
      "key": "general", "title": "لوحة التحكم", "requires_facility": false,
      "facilities": [], "default_facility_id": null
    },
    "user": {
      "id": 1, "staff_id": null, "username": "example-user", "name": "مستخدم توضيحي",
      "email": null, "must_change_password": false, "last_login_at": null
    },
    "facilities": [],
    "selected_facility_id": null,
    "links": []
  }
}
```

`user` uses the existing safe UserResource. Facilities expose `id`, `code`, `name_ar`, and
`timezone`. When an ID is supplied, detail data contains only that facility. There are no
other-user records, clinical statistics, revenue totals, credentials or token values.
`links` is empty because no additional medical/administrative destinations are implemented.
Future links must use known local keys and be filtered by the same backend authorization.

### Errors

| Status | Code / shape | Meaning |
| --- | --- | --- |
| 401 | `UNAUTHENTICATED` | Missing, invalid, expired or revoked token |
| 403 | `ACCOUNT_INACTIVE` | Account disabled; all its tokens are revoked |
| 403 | `MISSING_API_ABILITY` | Token lacks the api ability |
| 403 | `DASHBOARD_ACCESS_DENIED` | Known dashboard, denied by current permissions |
| 403 | `FACILITY_ACCESS_DENIED` | Facility unavailable to this user; existence is not disclosed |
| 404 | `DASHBOARD_NOT_FOUND` | Key is not defined; this differs deliberately from denied access |
| 422 | `{message, errors}` | Invalid input or missing required facility |
| 500 | `DASHBOARD_UNAVAILABLE` | Generic failure, with exception details removed even in debug mode |

Domain errors use `{ "error": { "code": "…", "message": "…" } }`. The Scramble reference at
`/docs/api` and generated `/docs/api.json` document bearerAuth, parameters, schemas, examples
and these actual middleware/domain responses. No second Swagger library or static spec is used.

Scramble export currently succeeds with nine JR001 diagnostics for array-backed resources
without a model (six existing auth resources and three new dashboard resources). The response
types are explicit; feature tests compare generated schemas against actual JSON, including
validation errors. These diagnostics do not indicate a failed export, but remain visible.

## Adding a dashboard

1. Add a stable key/title/priority to `config/dashboards.php`. Only the implemented `general`
   definition ships now. It uses `access: authenticated` and grants **no module permissions**.
2. For a future restricted dashboard use `access: facility_permissions` and an explicit,
   nonempty `permissions` list. All permissions must be held in the **same** active facility.
   Roles may contribute permissions within that facility; role names and first-role order
   never grant access. Unknown access rules or empty restricted permission lists fail closed.
3. Add its actual data implementation to DashboardService after the common authorization
   check, scoped to the selected facility. The current service only returns self context;
   configuring a name alone does not implement a clinical data source.
4. Add the matching local component to the frontend registry. Preserve the shared route
   group/AppShell. The selector appears only when more than one supported dashboard is allowed.
5. Test direct API denial, revocation with the same token, facility isolation and default ordering.
   Multiple definitions in the test suite are fixtures only; none are shipped to production.

The frontend uses an AbortController per dashboard/key/facility request scope. Changing that
scope unmounts previous data immediately. Session token changes remount the entire authenticated
tree; 401/ACCOUNT_INACTIVE clear it. The API client checks the captured token again before
processing a response, so a late old-user 401 cannot clear a newer session. Dashboard-only
403/404 errors retain the session and show retry/default-navigation controls.

## Safe testing

Use the existing isolated MySQL setup and guard described in [auth-api.md](auth-api.md).
Never use SQLite, development databases or production. No database is created automatically.

```bash
php artisan test-db:check --connect --env=testing
php artisan test --env=testing
php artisan scramble:export --env=testing --path=storage/app/dashboard-openapi.json
php vendor/bin/pint --dirty --test
composer validate
```

`RefreshDatabase` and the existing migration test can rebuild the verified test schema.
After they finish, the existing local/testing seeder may restore the demo account with
`php artisan db:seed --env=testing`, only after the safety check. Serve Laravel with
`php artisan serve --env=testing`. Then build/start Next.js locally with `LARAVEL_API_URL`
pointing at this testing instance and run:

```bash
node node_modules/typescript/bin/tsc --noEmit
npm run lint
npm run build
# TEST_BASE_URL=http://127.0.0.1:3101 PLAYWRIGHT_CHANNEL=msedge AUTH_LIVE_TEST=1
node --test tests/auth-ui.test.mjs tests/app-shell.test.mjs tests/dashboards.test.mjs
git diff --check
```

Only enable AUTH_LIVE_TEST after verifying the test server/database. Browser fixtures for
failure, race and malicious-input cases exist under tests only. The live test performs real
login, default selection, reload, direct navigation, logout and revoked-token rejection.

## Deployment requirements (not performed here)

- Deploy frontend and backend together, including the new dashboard routes/config. No new
  migration, database table, credential, dependency or Python service is required.
- Rebuild the Next.js standalone output with the correct `LARAVEL_API_URL`. Its explicit
  rewrites now include `/hospital-api/dashboards` and `/hospital-api/dashboards/:key` alongside
  login/user/logout; the query string and Authorization header reach Laravel.
- Apache must continue routing `/`, `/login`, `/dashboard/*`, `/_next/*`, public frontend
  assets and `/hospital-api/*` to Next.js when Next owns the same-origin proxy. A vhost that
  previously allowlisted only login/user/logout under `/hospital-api` must include dashboards.
  `/api/*` and `/docs/*` belong to Laravel if directly exposed. Do not send `/dashboard/*`
  through Laravel's catch-all or serve it as static files; it is a dynamic Next.js route.
- The tracked `backend/public/.htaccess` already forwards Authorization and sends non-file
  requests to Laravel's front controller. No Apache vhost configuration is tracked, so its
  live routing cannot be verified from the repository. Confirm it during a separate deployment.
- Refresh Laravel route/config caches as part of normal deployment. Scramble remains a dev
  dependency with the existing documentation access switch; do not expose docs by accident.
- Preserve private/no-store headers at reverse proxies. No server settings, production database,
  FastAPI files, merges or deployments are modified by this implementation task.
