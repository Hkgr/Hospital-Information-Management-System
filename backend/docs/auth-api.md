# Authentication API

Laravel owns users, password hashes, Sanctum tokens and the facility/role/permission
tables. FastAPI is an internal Python helper, not a user login gateway. This change
does not integrate Laravel with FastAPI.

## Interactive reference

With Laravel running at `http://localhost:8000`:

- [Interactive OpenAPI UI](http://localhost:8000/docs/api) (Scramble / Stoplight Elements).
- [Generated OpenAPI JSON](http://localhost:8000/docs/api.json).

The interactive reference documents all three authentication endpoints. Scramble
generates OpenAPI from `LoginRequest`, the controller and response Resources;
there is no separately maintained specification. `AuthDocumentTransformer` adds
Bearer security and errors using the same `AuthError` contract used at runtime.

Documentation defaults to enabled in `local`/`testing` and disabled elsewhere,
including `production`. `API_DOCS_ENABLED=true` explicitly enables it;
`API_DOCS_ENABLED=false` makes both URLs return 404, including in local and with
cached routes. Enabling documentation in production is an explicit operational
decision; restrict its network exposure as appropriate. Scramble's official
`viewApiDocs` Gate is used, preceded by the 404 switch because its built-in
middleware always allows local access. Rebuild configuration cache after changing
environment settings. No documentation was deployed by this PR.

## Using the API

Send `POST /api/login` with `Accept: application/json` and `Content-Type: application/json`:

```json
{"username":"admin","password":"example-password","device_name":"hospital-web"}
```

`username` is required, trimmed only at its edges, and limited to 60 characters.
Email is not a login identifier. Passwords are required strings and are not trimmed.
`device_name` is optional, a string of at most 100 characters, and defaults to
`hospital-web` when omitted. Null is not a valid device name.

The `200` response is `{ "data": { "token": "…", "token_type": "Bearer",
"expires_at": null, "user": { … }, "access": [ … ] } }`. The interactive reference
contains complete schemas and fictional examples. The user contains only `id`,
`staff_id`, `username`, `name`, `email`, `must_change_password`, and `last_login_at`.
Access is grouped by active facility with active roles and permissions, sorted by
code and deduplicated. Users without active role assignments authenticate with
`access: []`; this is not permission to access medical or administrative modules.

The plain-text token is returned only when created. Sanctum stores its SHA-256
hash and the single `api` ability. Roles and permissions are read dynamically
from MySQL, never copied into token abilities. Token creation, optional password
rehashing and `last_login_at` update commit together. Authentication failure
neither creates tokens nor updates the login timestamp. Login does not revoke
other devices. Successful auth responses use `Cache-Control: no-store`.

Send subsequent requests with:

```http
Authorization: Bearer <token>
Accept: application/json
```

In the interactive UI, use the **Authorization** control for `bearerAuth` and
paste the token value; the UI adds the Bearer header. These are Sanctum personal
access tokens, not JWTs. No session/cookie login is enabled.

- `GET /api/user`: `200`, the same `data.user` and current `data.access`, without a token.
- `POST /api/logout`: `204` with no body; revokes only `currentAccessToken()`.
  That device's token then returns `401`; tokens belonging to other devices remain valid.
- `401 INVALID_CREDENTIALS`: identical Arabic error for unknown username and bad password.
- `403 ACCOUNT_INACTIVE`: correct credentials for an inactive account.
- `422`: Laravel JSON validation response with `message` and field `errors`.
- `401 UNAUTHENTICATED`: missing/invalid Bearer token on protected endpoints, always JSON.
- `429 TOO_MANY_REQUESTS`: five login attempts per minute for trimmed, Unicode-lowercase
  username plus IP, with rate-limit/retry headers.

`must_change_password` communicates that a password change is required by the
account policy. This PR reports the flag; it does not implement or enforce a
password-change flow. Tokens currently have no configured expiration. Module
authorization, active facility selection, password change/reset, user and role
management, registration, 2FA and frontend login remain separate work.

## Safe MySQL test setup

Do not run tests with development/production credentials. Ask a database
administrator to provision an isolated MySQL test database and a user limited
to that database. Nothing here creates or drops an entire database automatically.
No SQLite fallback is supported.

1. Copy `.env.testing.example` to the ignored `.env.testing` and configure the
   authorized test credentials locally. Never commit them.
2. Keep `APP_ENV=testing`, `DB_CONNECTION=mysql` and a separate database name
   ending in `_testing` or starting with `test_`. The guard also rejects the name
   in the primary `.env`, DB_URL/socket overrides and read/write connection overrides.
3. Confirm the host/database are isolated and authorized, including explicit
   authorization if sharing a production host. Set `TEST_DATABASE_HOST` and
   `TEST_DATABASE_NAME` to the exact approved values, then `TEST_DATABASE_CONFIRMED=true`.
   Confirmation is an operator attestation, not automatic proof that a server is safe.
4. Set a testing-only application key (`php artisan key:generate --env=testing`).
   If configuration is cached, clear the local configuration cache first; never
   clear a shared production cache to prepare tests.
5. Before any migration, print/check the effective environment, driver, database
   and host with `php artisan test-db:check --env=testing --connect`. The optional
   read-only query runs only after the safety guard and verifies `SELECT DATABASE()`.
   Stop if the test database is unavailable; do not create a database or substitute SQLite.

Only after explicit verification and permission to run tests:

```bash
php artisan optimize:clear --env=testing
php artisan migrate:fresh --seed --env=testing
php artisan route:list --path=api
php artisan test --env=testing
php vendor/bin/pint --test
git diff --check
```

The example uses in-process array cache/session stores. TestCase checks safety
before `RefreshDatabase` runs; testing migration/seed commands have the same
guard. Login tests reset the array rate-limiter store for each test. The migration
guard also checks that the confirmed database exists before Laravel can offer to
create a missing database, and refuses `--database` overrides. The migration
test intentionally uses no transaction because MySQL DDL implicitly commits.
The demo seeder runs only in local/testing, creates no roles, and must never
be used as a production account source.

The tests cover login/token/logout behavior, active access, validation/rate
limits, transaction rollback, password rehashing, schema/response parity,
documentation visibility, and MySQL migration/rollback/seed. They were **not run
for this PR, at the user's explicit request**. No MySQL testing database was used.
Static document generation produced OpenAPI 3.1.0 with Scramble 0.13.43; array
Resources produce JR001 model-inference warnings while their explicit field
schemas are generated. These are not results of runtime or database tests.
