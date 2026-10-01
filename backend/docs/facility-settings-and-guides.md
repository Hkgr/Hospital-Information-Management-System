# Facility settings, session policy and administrative guides

> Current card entry/access and retired reception-account routes: [Direct patient-card entry](direct-patient-card-entry.md). Earlier phase-specific routes and global-grant prerequisites below describe the historical implementation, not a required setup for the current local card flow.


This release extends the existing permissions matrix without assigning global roles to ordinary accounts. `/settings` and `/guide` use the existing authenticated AppShell, Cairo and RTL. Laravel authorizes every request; guide responses contain only a permitted facility, local/global permission codes and a server-derived system-admin capability.

The current illustrated guide starts with task cards, a large patient-card/visit diagram and a four-step synthetic example. Registration now lives under `/patient-cards`; `/reception` remains a compatible redirect and `/reception-admin` is labelled “مراجعة بيانات المرضى”. See [unified registration and task permissions](unified-patient-registration.md) for the current permission mapping and operator-reviewed assignment steps. Earlier reception names below describe the original access model, not a separate current navigation section.

## Activation (operator only; not run against production by this PR)

Deploy backend and frontend together after backup and normal operator review:

```sh
php artisan migrate --force
php artisan db:seed --class=FacilitySettingsPermissionsSeeder --force
php artisan optimize:clear
php artisan route:list --path=settings
php artisan route:list --path=guide
```

The definitions from permissions phases 1 and 2 must already exist. The dedicated seeder does not run general seeders, create accounts, assign the protected role, or change user 1. It adds `settings.view` and `settings.update` to active `hospital_admin`, preserving disabled permissions/roles and existing grants. It also grants exactly the two **local** codes `reception.patients.search/create` required to make the existing data-entry role assignable under the same subset rule. This is not global authorization: no `global_user_roles` row is inserted. Search/create still require the separate operator-reviewed global grant. Repeating this seeder is safe.

Build Next with the actual internal Laravel API base, then restart its standalone process using the project's normal deployment procedure:

```sh
LARAVEL_API_URL=https://<internal-laravel-host>/api npm run build
```

Copy `public` and `.next/static` to the standalone artifact as usual. Rewrites are build-time: `/hospital-api/settings`, `/hospital-api/settings/system-session`, `/hospital-api/guide` are explicit routes, not a generic proxy. Verify through Next with an authorized test account, including a denied facility. Do not run integration fixtures or testing commands on production.

## Settings contract and scope

The current installation operates as one hospital. Facility dropdowns have been removed throughout the frontend (including doctors, clinics, users, reports, audit, statistics, dashboards, settings and guides). Pages display the current hospital name and resolve an omitted context using the existing permitted default. No numeric ID is hardcoded. An explicitly invalid or unauthorized context is still rejected; server scope checks and token-wide policy calculations remain intact. This UI change does not merge facilities or alter their data.

- `GET /api/settings?facility_id=…`: `settings.view`; returns facility, saved minutes, version, update capability, and (only for the protected global system role) system policy.
- `PUT /api/settings`: `settings.view/update`, fields `facility_id`, `lock_version`, `name_ar`, `idle_minutes`, optional `reason` (required when duration changes). Minutes are whole integers 1–60. All extra fields are rejected. Server increments version and audits old/new allowed values and reason in the same transaction.
- `PUT /api/settings/system-session`: same facility permission plus the explicit protected global system role; only `facility_id`, `lock_version`, `idle_minutes`, `reason`. Facility is the authorized audit anchor, not the policy scope.
- Conflict: `409 SETTINGS_VERSION_CONFLICT`; access: `403 SETTINGS_ACCESS_DENIED`; invalid field:422. All responses are private/no-store, including failures. No database settings, API keys, secrets, historical timezone/code changes or generic settings-key editor.
- `name_ar` is the existing facility display name consumed by access contexts, dashboard and facility reports. Its default is the existing stored name. Other currently stored preferences are not exposed because no working application consumer was found. There are no decorative contact/report fields.
- Idle default is **2 minutes**, adjustable within1–60. Reset previews minutes only; it does not reset the name. Altering two minutes changes the organization's original default policy. Lowering warns that the current session and unsaved work may be lost.

## Authoritative session behavior

`SessionPolicy` calculates the shortest valid duration among **all active accessible facilities** for ordinary users, never the facility selected by the client. Missing/invalid settings use120 seconds. The protected system role uses its separate general policy, default120 seconds; neither user ID nor role name implies that authority.

Tokens keep `web_last_activity_at` separately from Sanctum `last_used_at`. Under the token lock and shared policy lock, the server caps the stored deadline by the current duration **and every shorter policy recorded since the last accepted activity**. Therefore lowering then raising without an intervening request cannot resurrect the token. GET/read/write/polling never renews. Raising benefits only the next accepted POST `/session/activity` from a still-live token. Check expiry after waiting for locks and before renewing. Concurrent settings writes serialize on the existing singleton policy row; settings do not acquire/renew a token while holding this row.

`GET/POST /session[/activity]` returns `idle_timeout` (current policy seconds), `applied_idle_timeout` (the token's capped interval), `warning_seconds` (one quarter, bounded15–60), remaining seconds and server timestamps. Frontend warnings use that contract. Trusted interaction is coalesced; background timers/focus checks are not activity. Each device token is independent; tab coordination, sleep verification, fail-closed transport behavior and freeze revocation remain in place. Explicit operator noninteractive tokens with null web deadline remain exempt; client `device_name` cannot request that exemption.

`2026_09_28_000003_add_session_policy_settings.php` adds typed duration/version to existing facility settings, a singleton system policy, indexed reduction history, and last activity on tokens. `2026_09_28_000004_remember_web_session_policy_scope.php` retains the policy scopes observed since the last accepted activity: removing a facility after its shorter policy expired a token cannot revive that token. Existing deadlines/hashes are preserved; last activity is backfilled as existing deadline minus the previous120-second policy. Null-deadline integrations are untouched. No clinical tables change. Policy history must not be deleted while tokens that depend on it exist. Rollback refuses while any managed tokens or policy changes remain; it never resets policies, revives tokens or silently discards security history. For a used installation roll forward; reverting to old application code would also lose configurable-policy enforcement.

## User administration and guides

Assignable roles remain active ordinary directory templates with active permission codes entirely within the actor's facility permissions. System roles and internal `reception-*` account roles cannot be assigned. Ordinary directory roles have no facility owner in the current schema; facility-specific internal roles are excluded, not repurposed. Role and permission checks run again during save. A role already used globally cannot be edited by a local administrator, even when its local codes are a subset; otherwise editing that shared definition could change global authorization indirectly.

Creation returns `pending_global_permissions`; the UI explicitly states that the account was saved but reception search/create await global authorization. Statistics-only accounts do not receive that warning. Username/email uniqueness and protected system-account behavior remain unchanged.

`/guide?facility_id=…` has permission-derived reception, hospital-admin, statistics, system-admin and actual general-dashboard sections. Multi-role users see multiple applicable sections. Links preserve explicit facility context; unauthorized explicit IDs never fall back. It explains15-minute correction eligibility, review decisions, safe duplicate limits, global authorization, anonymized grouping, distinct patients vs events, unavailable occupancy, adjustable idle default2minutes, settings scope, logout and assistance. It explicitly states external SQL changes are not automatically app-audited. No wizard, clinical workflow, new report or2FA is introduced.

## Verification

Use only a separate MySQL/MariaDB test environment after `php artisan test-db:check --connect --env=testing`. `tests/Support/preserve-database.php` refuses pending migrations and marks the schema as already migrated for transactional tests. The test base now aborts if a prior test loses its transaction boundary; this prevents an automatic refresh on the following test. This does not make DDL inside transactional tests safe: isolate those tests and never run them against a populated database. Real browser fixtures create isolated synthetic actors/facilities, disable only those actors and revoke their tokens afterward, retaining audit history. No live tests use API interception.

See [verification and screenshots](../../frontend/docs/admin-settings-verification.md) and the PR for the tested SHA, exact command counts, exclusions and test-database incident. UI generation with Superdesign was blocked by exhausted credits; implementation reuses the actual components and was inspected through real browser captures instead.
