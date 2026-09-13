# AGENTS.md — Project conventions

Read this before writing any code in this repository. These conventions are extracted from the two modules already shipped (Clinics, Doctors). New modules must match them. When something here conflicts with a habit from your training data, this file wins.

---

## 1. What this system is

A **retrospective clinical registry and statistics system** for a single oncology / thalassemia day hospital in Aleppo, Syria.

It is **not** an operational HIS. Nobody uses it at the point of care. Clinical work happens on paper (the standardized `الإضبارة` file from the Health Directorate). A data-entry team receives the completed paper file afterwards and records it. Consequences:

- No queues, no bed management, no order routing, no clinical decision support.
- Every record is entered **after the fact**. Service date and entry date are different things and must never be conflated.
- The product that matters is the **monthly directorate report**. Every entity must be able to roll up into one of its columns.

Volume: roughly 2,200 consultations and 2,900 services per month.

---

## 2. Stack


| Part        | Technology                                                                         |
| ----------- | ---------------------------------------------------------------------------------- |
| `backend/`  | **The actual project.** PHP 8.3, Laravel 13, Sanctum 4                             |
|             | `dedoc/scramble` (OpenAPI), `mpdf/mpdf` (PDF), `phpoffice/phpspreadsheet` (Excel)  |
| `frontend/` | Next.js 16 (App Router), React 19, TypeScript 5, Tailwind 4 + CSS Modules          |
| `api/`      | FastAPI. **Mock endpoints only**, for the frontend engineer. Never business logic. |
| Database    | MariaDB 10.11, `utf8mb4`                                                           |


Work happens on `develop` and feature branches. `main` holds the initial scaffold only.

---

## 3. Backend layering

```
routes/api.php
  └── Http/Controllers/Api/XController.php      thin: authorize, delegate, return
        ├── Http/Requests/X/…Request.php        validation only
        ├── Services/X/XAccess.php              permission gate
        ├── Services/X/XQueries.php             reads
        ├── Services/X/XWriter.php              writes: transaction, lock, audit
        ├── Services/X/XCounts.php              shared count/eligibility queries
        ├── Services/X/XAudit.php               audit writes
        ├── Services/X/XReports.php             xlsx / pdf output
        ├── Exceptions/XException.php           domain failures
        └── OpenApi/XDocumentTransformer.php    spec adjustments

```

Controllers stay at one line per step. If a controller method needs branching, the branch belongs in a service.

**Use the Query Builder (**`DB::table`**), not Eloquent.** `App\Models\User` is the only model in this codebase and exists for Sanctum. Do not introduce Eloquent models, relations, or accessors.

---

## 4. Non-negotiable rules

### 4.1 Every endpoint authorizes first

```php
$facility = $this->access->authorize($request->user(), $request->integer('facility_id'), 'update');

```

`authorize()` returns the facility array plus `today` computed in the facility timezone. It throws `403` with an Arabic message when access fails.

The permission check is always a **pair**: `x.view` AND `x.{action}`. A user with `clinics.update` but not `clinics.view` is denied.

`facility_id` is a required request field on every endpoint, including reads.

### 4.2 A record in another facility returns 404

Never 403. An id the caller may not see must be indistinguishable from an id that does not exist.

### 4.3 Mutations follow one shape

```php
DB::transaction(function () use (...) {
    $old = $this->locked($facility['id'], $id, $input['lock_version']);
    // … validate, write …
    DB::table('x')->where('id', $id)->update($fields + ['lock_version' => $old['lock_version'] + 1]);
    $this->audit->record($request, $facility['id'], $id, 'updated', $old, $new);
}, 3);

```

- `DB::transaction(fn, 3)` — three attempts, for deadlock retry.
- `lockForUpdate()` when reading the row you are about to change.
- Compare `lock_version`; mismatch throws `X_VERSION_CONFLICT` (409).
- Increment `lock_version` on every update.
- Audit is written **inside** the transaction.

### 4.4 Nothing is ever hard-deleted

Event tables carry `voided_at`, `voided_by`, `void_reason`. Directory records carry `archived_at` and `is_active`. `DELETE` is permitted only for records with no references, and only through `DirectoryLifecycle`.

Archived records cannot be edited. Restore first.

### 4.5 Two error channels

**Domain failures** — a custom exception per module:

```php
throw new ClinicException('CLINIC_STATE_CONFLICT', 'استعد السجل المؤرشف قبل تعديله.', 409);

```

Renders as `{"error": {"code": "…", "message": "…"}}`. Codes are `MODULE_REASON` in SCREAMING_SNAKE. Messages are Arabic, addressed to the user, and say what to do next.

**Field failures** — `ValidationException::withMessages(['code' => '…'])` → 422.

A MySQL duplicate-key error (`errorInfo[1] === 1062`) is caught and converted into a field failure, never surfaced as a 500.

### 4.6 Response envelope


| Case          | Shape                                                                 |
| ------------- | --------------------------------------------------------------------- |
| Single record | `{"data": {…}}`                                                       |
| Collection    | `{"data": [...], "meta": {"page", "per_page", "total", "last_page"}}` |
| Create        | `201` with `{"data": {…}}`                                            |
| Delete        | `204`, no body                                                        |
| Error         | `{"error": {"code", "message"}}`                                      |


After a mutation, re-read through `XQueries::find()` and return that. Do not hand-build the response from the values you just wrote.

### 4.7 Concurrency contract in requests

```php
'lock_version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],

```

Prohibited on create, required on update. `FormRequest::authorize()` returns `true` always — authorization lives in the Access service, not the request.

### 4.8 Links change by explicit deltas

Never replace-all. Use `x_add_ids` / `x_remove_ids`, reject ids appearing in both, and leave omitted links untouched.

### 4.9 Documentation is generated, not written

`#[Group('Clinics')]` on the controller, and a **one-line docblock on every method** that becomes the endpoint description in the OpenAPI document:

```php
/** Delete only unreferenced clinics. Requires clinics.delete; references or stale versions return 409. */

```

Write it for the API consumer. State the permission and the failure mode.

---

## 5. Rules for the modules not yet built

The database already carries columns for patterns no shipped module exercises. Anyone building visits, clinical events or reporting must implement them.

### 5.1 Idempotency

Every event table has `client_request_id VARCHAR(36) NOT NULL`. The client generates a UUID per logical write and resends it on retry. A repeated `client_request_id` must return the original result, not create a second row. This exists because the network at the site is unreliable.

### 5.2 Facility and period stamping

Event rows carry `facility_id` and `reporting_period_id` denormalized. Both are derived from the **event date** (`visit_date`, `performed_on`, `administered_on`), never from the current date or the entry timestamp. A September service entered in October belongs to September.

### 5.3 Period closing governs editability

`reporting_periods.status` is `open | submitted | locked`.

- `open` — the clerk edits their own records.
- `submitted` / `locked` — writes are rejected. Changes go through `correction_requests`, reviewed by the user named in `facility_settings.correction_user_id`.

### 5.4 Demographics are snapshotted

`visit_demographics` stores gender, birth date, governorate and displacement status **as they were at the time of the visit**. Reports read from this table, never from `patients`. A patient who is displaced next year must not change last year's submitted numbers.

The same principle applies to `medication_name_snapshot` and `report_runs.definition_snapshot`.

### 5.5 Newness is computed then frozen

Whether a visit counts as new or returning is derived on save and stored. `newness_override` plus a mandatory `newness_reason` allow a justified manual change. Never recompute at report time.

### 5.6 The report engine is versioned

`report_definitions → report_versions → report_metrics → report_metric_catalog_items`.

A metric declares `source`, `aggregation`, `date_basis`, `newness_basis`, `split_by`. Which diagnoses or services feed it is data in `report_metric_catalog_items`, each row carrying `mapping_status` and `verified_by` — mapping is a **reviewed workflow**, not a constant.

A `report_run` stores `definition_snapshot`, `checksum_sha256`, `revision` and `supersedes_id`. A submitted figure stays reproducible after later corrections.

When the directorate changes its form, that is a new `report_version`, not a code change.

### 5.7 Patient search uses tokens

`patient_search_tokens` holds normalized name tokens. Search matches tokens; it does not `LIKE '%…%'` against `patients.search_name`. Normalization folds hamza forms, taa marbuta and alef maqsura.

`staff_aliases.normalized_alias` does the same for doctor names as written on paper.

---

## 6. Testing

- A Feature test per module under `tests/Feature/`.
- Use the `Tests\Support\AssertsOpenApi` trait: responses are asserted against the generated OpenAPI schema, so an undocumented field fails the suite.
- Where locking matters, add a concurrency test — see `DirectoryLifecycleConcurrencyTest`.
- Never point tests at a database holding real data; `App\Support\TestDatabaseSafety` guards this.

---

## 7. Frontend

```
src/app/(hospital)/…      routes
src/components/layout/    AppShell, Sidebar, Header, MobileSidebar
src/features/<module>/    screens, components, api.ts, <module>.module.css

```

- One `api.ts` per feature exporting the TypeScript types **and** the fetch hooks. Types mirror the API response exactly.
- CSS Modules, not global styles. Tailwind for utilities only.
- Arabic RTL is the primary direction.
- Every request is abortable; a superseded request must not overwrite newer state.
- Error text shown to the user is Arabic.

---

## 8. Data-entry screens are speed-critical

At ~2,200 visits per month, entry throughput matters more than visual polish:

- One screen per paper file. No multi-step wizard.
- First field is the patient code; an existing code fills the rest.
- Full keyboard operation, sensible tab order, no mouse required.
- Save-and-next in a single action.
- Autocomplete on diagnoses and services.
- The clerk's name comes from their account, never a dropdown.

---

## 9. Known gaps

Do not treat these as settled conventions:

1. `ClinicAudit` generates a fresh `Str::uuid()` per audit row. Two rows written in one HTTP request receive different `request_id` values, so a request's changes cannot be grouped. This should become one correlation id per request, supplied by middleware.
2. No code uses `client_request_id` yet. The first module that writes events establishes the pattern for the rest.
3. Permissions are seeded for `clinics.*` and `doctors.*` only. The full permission catalog needs defining before more modules are built, or each module will invent its own naming.
4. `admissions` exists in the schema but inpatient care is not active at the facility, and the visit result list has no admission option. Confirm before building anything on it.

