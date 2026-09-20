# Patient Cards: browser routes and presentation

This follows the unified identity correction in PR #24. `patients` remains the
one global identity/card and `patients.patient_code` its only current public code.
`patient_dossiers` remains a facility-local medical/oncology/progress context.
This change adds no schema migration, permissions, grants, clinical workflow or
identity creation path.

## Routes and workflow

| Old browser URL | Canonical browser URL |
|---|---|
| `/dossiers` | `/patient-cards` |
| `/dossiers/new` | `/patient-cards/new` |
| `/dossiers/{numeric context id}` | `/patient-cards/{same id}` |
| `/dossiers/{numeric context id}/edit` | `/patient-cards/{same id}/edit` |

Next returns a permanent 308 for these four specific routes. Query parameters,
including facility, visit, section, search, filters and repeated values, pass
through. The path ID still denotes the local medical context; it is not replaced
by the global `card_id`. Other old subpaths are not given a wildcard redirect.
All current browser links use the new paths. `/api/dossiers`, the explicit
`/hospital-api/dossiers` rewrites, `Dossier*` components/services, and `dossiers.*`
permission codes remain for compatibility. There is no parallel backend API.

The registration page defaults to existing-person search when authorized. The
operator may explicitly choose new-person registration without a mandatory search.
An actor with global creation but no search permission starts in new-person mode;
no permission is added. Both form drafts survive switching modes. Opening a page
does not write anything. First save still atomically creates the identity/context/
genuine initial draft visit, with its UUID, optimistic locking and registration FK.
`initial` is presented as **أول زيارة مسجلة ضمن البطاقة**, not a clinical visit type.

## Lists, details and reports

Default columns: sequence, canonical code, full name, mother, gender, birth date,
phone, paper-file number, facility medical-file start, latest visit, context status
and current oncology flag. Diagnoses, clinics, doctors and visit/procedure counts
remain selectable. All use the existing shared table/column menu/pagination.
The longer column menu uses the directory filter's bounded-scroll pattern, scoped
to this page, and fits the phone toolbar. Identity numbers and dates reuse the
shared nonwrapping cell style. Other directory screens are unchanged.
Subsequent-visit actions follow the existing server-provided workflow capability;
draft completion does not activate the context or complete a visit implicitly.

The list API adds `mother_name`, `gender`, `birth_date`, `birth_date_accuracy`,
`phone` and `paper_file_number`; detail adds the paper-file number for reading only.
No per-row identity queries or new editable identity fields are introduced.
The birth display respects precision: year only, estimated, exact or unknown.

The same column selection applies to the full filtered PDF/XLSX export, not just
the visible page. New selectable keys are `mother_name`, `gender`, `birth_date`,
`phone`, `paper_file_number`, `opening_date` and `is_oncology`. Old selections,
including the `patient_code` alias of `code`, still work. The column maximum now
follows the allowlist. Counts stay numeric, exact dates remain Excel dates, and
codes/phones/paper identifiers remain explicit text. Partial birth dates retain
their precision as text rather than inventing a full date. Existing Cairo, RTL,
print layout, continuation sheets, limits, formula safety and no-store remain.

Details show identity, local medical summary, visit history/selected visit and
its clinical facts/attachments, reports, then audit. The canonical code appears
once in the identity display. Individual PDF uses the existing identity banner
and omits its duplicate code fact; XLSX retains that fact because it has no banner.
Reports remain historically complete under their existing authorization, including
voided facts/reasons. Filenames/report numbers and endpoints are unchanged.

## Directory ownership and deferred work

- Doctors/clinics retain clinicians, specialties, staff types, assignments and
  lifecycle management. Patient Cards only select their eligible entries.
- Services/procedures retain their catalogs, categories, lifecycle and reports.
- Medication management remains owned by the medications area. Its central CRUD
  is not implemented here. Existing explicit, permission-checked inline medication
  creation remains temporarily; prescription saving never creates a definition.
- Explicit inline diagnosis creation also remains until an authorized central
  replacement exists. Neither creation path is duplicated.
- Visit types/results/workflow states remain reviewed system seeders. Geography
  remains central reference data. No generic directory-management page is added.

Pathology, treatment plans, future dosing, dispensing workflows, new referrals,
imports and new directory CRUD are deferred. FastAPI/authentication are untouched.

## Operation and verification

No migration is needed. Rebuild Next with the deployment's intended
`LARAVEL_API_URL`; package fresh standalone assets because redirect configuration
is part of the build. If refreshing permission labels, run only the existing
`DossierWorkflowPermissionsSeeder`; it changes names, never assignments.

Tests use mysql/MariaDB 10.11.18 on `blood_bank_cities_testing`, loopback port 13416.
The missing temporary MariaDB binary was restored from the same official version
into ignored test-tool storage and started against the original datadir. No reset,
truncate, migration or production connection was performed.

```sh
php artisan test-db:check --connect --env=testing
php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php \
  tests/Feature/PatientCardTest.php tests/Feature/DossierWorkflowTest.php \
  tests/Feature/DossierApiTest.php tests/Feature/DossierCompletionTest.php \
  tests/Feature/DossierCompletionSafetyTest.php tests/Feature/DossierClosureTest.php
```

Real browser checks use freshly built standalone Next on 3194 -> loopback Laravel
test router on 8194 -> guarded MariaDB, without mocked API responses. Local review
media stays under ignored `.superdesign/tmp`; prior tracked galleries are unchanged.
The standalone test build uses `LARAVEL_API_URL=http://127.0.0.1:8194/api` and copies
`public` and `.next/static` to their standalone locations **before** starting Node.
Measured verification (2026-09-20):

- Guard: passed with an actual connection to the isolated database above.
- Six affected Laravel suites: **54 tests / 2233 assertions**, no failures or
  skips (final run 24.154 seconds, PHP 8.4.14 / PHPUnit 12.5.35).
- Real browser suites: workflow 4, completion 7, closure/audit 4, review 4,
  list/history 4, Patient Cards 6. Each case passed across the verification runs.
  The final combined replay of review + list/history + Patient Cards passed
  **14/14**, no failures/skips (227.136 seconds).
  After the final first-recorded-visit heading change and fresh standalone build,
  Patient Cards passed **6/6** again (44.096 seconds), including that heading.
- Earlier combined runs exposed the column menu extending outside the viewport
  for actors without export permission, including tablet width. The scoped
  alignment/scroll fix passed both affected tests at all three widths. One earlier
  registration-page startup timed out before any edit/action; it did not reproduce
  in the final combined replay. No automatic retries or disabled cases were added;
  the browser test now logs startup/server errors if it happens again.
- AppShell's separate mocked layout suite: **8/8**. It is not evidence of backend
  integration; the real suites above exercise Next -> Laravel -> MariaDB.
- TypeScript, ESLint, production build, Pint and `git diff --check`: passed.
- Actual list/card/visit PDF downloads rendered with PDF.js for local inspection:
  1/4/3 pages respectively. All pages inspected; titles, identity, Cairo/RTL and
  continuation headers retained. Five browser-downloaded XLSX files reopened with
  PhpSpreadsheet: valid, RTL/Cairo, explicit print areas, width-fit/no height-fit,
  no formula cells. Feature tests additionally assert date/count/text cell types,
  leading zeros, formula-looking input and exactly one canonical PDF identity code.
- Local screenshots cover list/detail/validation at 390, 768 and 1440px. The tests
  check no document-level horizontal overflow. Media is not committed or uploaded.

The entire repository's Laravel/frontend suites and a desktop Excel print preview
were not run; verification is scoped to this change. No production check or
deployment was attempted. There is no new migration to apply or roll back.
