# Phase 4 verification

Base: `e572a0130563f70a46b0ea68d0c4fe08a6ad6821`, merged PR #27; refreshed against
`origin/develop` before delivery. New branch:
`feature/legacy-patient-import-and-offline-capture`. Final commit is identified in
the draft PR; this document belongs to that same commit.

## Environment and retained history

Windows; PHP 8.4.14; Node 24.11.0; MariaDB **10.11.18** through Laravel's `mysql`
driver. Guarded existing database: `blood_bank_cities_testing` on loopback
`127.0.0.1:13416`. Credentials remain in ignored local testing configuration.
No SQLite, production access, database reset, truncate, `migrate:fresh`, or
deletion of retained medical/import history. Live fixtures revoke only their own
tokens. PHPUnit fixtures use transactions through the preserving bootstrap.

The additive migration `2026_09_21_000003_add_dossier_import_batches.php` upgraded
this populated database. Its rollback and re-upgrade succeeded before any import
batch existed, without changing older schema/data. Once evidence existed, the
regression proves rollback refuses before DDL. Composite facility FKs and unique
batch/source constraints are covered by tests and real concurrent commits.

## Commands and results

- `php artisan test-db:check --connect --env=testing`: passed; environment/driver/
  database/host confirmed before database work.
- `php artisan migrate --env=testing`: additive upgrade passed. New migration
  down/up exercised only while its new tables were empty; existing clinical data
  stayed populated. Older `*MigrationTest` classes that use destructive reset
  strategies were deliberately not run.
- `php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php tests/Feature/DossierImportTest.php`:
  **27 import tests / 278 assertions passed within the final affected run**, zero failures/skips (JUnit recorded 20.054s). The preceding standalone run also passed (27 tests / 277 assertions), before the final cross-sheet source-ID rejection assertion.
- Affected regression command below: **128 passed, 16,519 assertions, 0 failures, 0 skips**, 89.291s, 262 MiB peak.
- `node --test tests/dossier-import-live.test.mjs` from frontend: **4 passed,
  0 failed, 0 skipped**, 82.237s. Real fresh standalone build on loopback 3194 →
  test Laravel on 8194 → MariaDB. Template download, upload, validation, explicit
  confirmation, interrupted/resumed batch, partial commit, corrected resubmission,
  duplicate skipping, error download, imported-card navigation and 401/403 covered
  at 390/768/1440px. No `page.route` or mock API.
- `node --test tests/patient-card-live.test.mjs`: **6 passed, 0 failed, 0 skipped**,
  60.627s. Existing interactive identity, initial-visit atomicity, drafts, aliases,
  registration concurrency, legacy redirects and exports remain functional. Its
  fixture verified unchanged reporting/blood/catalog domain rows.
- `php tests/Support/dossier-import-live.php concurrency`: **3/3 independent-process
  races passed**, each producing one 200 and one 409, with five source rows saved
  once. Readiness signaling, not arbitrary sleeps. Lock acquisition precedes the
  commit's repeatable-read snapshot.
- `npx tsc --noEmit`: passed. `npm run lint`: passed. `npm run build`: passed with
  the explicit testing `LARAVEL_API_URL`; static/public assets copied before
  restarting standalone. The build contains `/patient-cards/imports`.
- Pint on all changed/new PHP files: passed.
- `php artisan scramble:export --path=storage/framework/testing/import-openapi.json --env=testing`:
  exported successfully; **9 existing resource-model inference warnings**, none
  in the new import operation transformer. No unrelated resources changed.
- `php artisan route:list --path=api/dossiers/import --env=testing`: all **8**
  intended routes present; numeric batch constraints and explicit Next rewrites.
- `php artisan dossiers:cleanup-imports --env=testing`: dry run passed, zero
  expired live fixtures. Transactional feature tests cover explicit apply/audited
  actor, expiry, preservation of committed encrypted provenance and all clinical
  facts. No production scheduler was changed.
- `git diff --check`: passed.

Affected regression command (from backend):

```text
php vendor/bin/phpunit -c storage/framework/testing/phpunit-import.xml --bootstrap tests/Support/preserve-database.php tests/Feature/DossierApiTest.php tests/Feature/DossierClosureTest.php tests/Feature/DossierCompletionIOTest.php tests/Feature/DossierCompletionSafetyTest.php tests/Feature/DossierCompletionTest.php tests/Feature/DossierImportTest.php tests/Feature/DossierPathologyTest.php tests/Feature/DossierReleaseVerificationTest.php tests/Feature/DossierWorkflowTest.php tests/Feature/OncologyTreatmentTest.php tests/Feature/PatientCardTest.php
```

The temporary ignored config copies `phpunit.xml`, rebases its file paths and
sets only test-process memory to 1024M. Earlier broad runs with the XML's 256M
limit exhausted memory in the pre-existing whole-database snapshot assertion in
`DossierCompletionIOTest` after large retained fixtures were added. PHP's CLI
`-d` alone did not override PHPUnit's child-process XML setting. No application
memory setting or old test was changed to hide this; the complete affected run
was restarted using that explicit testing config.

Earlier integration failures found and fixed a misplaced rewrite entry and the
download filename header; missing standalone assets/server locks were local
launch issues corrected before the successful fresh-build run. A performance
fixture initially omitted existing required patient columns; it now clones only
its synthetic fixture identity. These attempts were not counted as passes.

## 5,000-patient measurement

Commands: `php tests/Support/dossier-import-live.php performance`, then `metrics`.
Generated workbook: **5,000 patients, 9,669 source rows, 411,157 bytes**. It includes
new/existing identities, multiple/same-day visits, pre-/post-cutover dates, Arabic
and mixed text, leading-zero phones, formula-like literal notes, invalid directory
references and ambiguous aliases. A separate 5,000-patient variant with a duplicate
source ID was rejected before storage. Ambiguous/invalid patient bundles remain
uncommitted; the measured result is intentionally `completed_with_errors`.

| Operation | Measured result |
| --- | --- |
| Workbook generation | 7.321s |
| Upload + parse + protected persistence | 1.483s; 100 queries (bounded row inserts) |
| Final parser-only recheck after archive/source-ID hardening | 0.969s; 9,669 rows; parser-only peak 40 MiB |
| Validation | 500 chunks, 25.237s total, 0.117s maximum chunk; 25,195 queries |
| Commit | 480 chunks, 119.329s total, 0.557s maximum chunk; 245,518 queries |
| Final preview/detail including all summary groups | 0.203s; 8 queries |
| Final batch index including authorization | 0.033s; 6 queries |
| Peak generation/processing process memory | 117,440,512 bytes (112 MiB) |
| Outcomes | 9,188 committed rows; 481 review rows; no partial failed patient bundle |

These are service-level measurements on the guarded populated database, separate
from browser/HTTP timings. Writer/audit/provenance inserts and existing domain
checks account for commit queries; they are not falsely described as constant.
The focused regression measured **32 SELECTs for one patient and 32 for ten** in a
validation chunk sharing the same reference set. Identity/directory reads are
preloaded; distinct clinician/date pairs still use the existing domain validator. Before preloading, validation took 63.209s/58,544
queries and commit 173.991s/290,281 queries in the earlier generated sample.

## XLSX, visual review and limitations

All six final browser-downloaded templates/error workbooks (three viewports) were
reopened with PhpSpreadsheet; all were valid, RTL/Cairo and free of formula cells. Regressions cover workbook validity, RTL/Cairo, hidden machine
keys, date cells, leading-zero text, formula rejection/literal text, numeric error
row numbers and explicit error-sheet print area. No original patient workbook
or clinical payload was uploaded to GitHub.

**LibreOffice is unavailable** (neither `soffice` nor the standard Windows install
exists). No LibreOffice rendering or manual Excel visual verification is claimed.
Browser screenshots were inspected at all three widths, including file errors,
preview and partial commit; see the [synthetic review gallery](../../frontend/docs/reviews/patient-import/README.md).

This is an independently reviewable **draft**, not deployment approval. Structured
pathology/oncology/blood/attachments/dispensing, reference-directory creation and
automatic demographic merging remain excluded. Operator-reviewed permissions,
backups, private storage, retention scheduling and deployment commands are in
[patient-import.md](patient-import.md). No merge, deployment, FastAPI change or
production access was performed.

## PR #28 corrective review — replay ownership and audit

Reviewed behavior: `50ac2e9861c994ede1f4f11f610b7757d86ecbde`. The correction
stays on `feature/legacy-patient-import-and-offline-capture`; PR #28 remains a draft.

The old code built clinical input before resolving source skips. Fingerprint
equality did not establish parent ownership, so a skipped child could still be
written beneath a new visit. The correction loads the retained source rows,
their original parent rows and the authoritative dossier/visit FKs in bounded
queries. It checks that graph during validation and again with commit locks,
rejects the whole patient bundle on a mismatch, and removes every proven replay
from clinical input *before* preparation. Patient identity resolution is checked
against the retained dossier instead of replacing the resolved dossier afterward.
New same-day visits with new source IDs remain valid.

Import audit fields existed in the labels map but were missing from the entity
allowlist. They now appear through the existing authorized history API and UI.
Paper-file and legacy-alias assignments now lock their newly created authoritative
rows, capture before/after values, and call `DossierWrites::audit()` in the same
transaction. Creation, assignments and import provenance remain separate events;
canonical patient codes and existing contexts are not overwritten.

Before changing application logic, the preserving MySQL regression run on the
reviewed behavior produced **13 tests, 184 assertions, 12 failures**: ten invalid
ownership previews, a commit-time retained-source integrity case, and the empty
audit provenance projection. Exact full clinical replay already passed. Invalid
ownership cases were also sent through the old commit path: counts increased in
`visits`, `visit_diagnoses`, `visit_services`, `visit_procedures`,
`visit_prescriptions`, `visit_prescription_items`, and `visit_outcomes`. The
regressions check all these tables plus patients/contexts and excluded
dispensing/oncology/blood tables. A further test isolates a different dossier
with an otherwise unchanged visit hierarchy.

No corrective migration, new permission, workbook version, route, UI component or
interactive registration contract is needed. The original additive migration and
operator-reviewed seed/permission/private-storage requirements remain unchanged.

### Corrective verification environment and measurements

All database operations used the preserving safety-checked **mysql** connection
to populated **blood_bank_cities_testing**, `127.0.0.1:13416`, **MariaDB 10.11.18**.
No migration, reset, truncate or retained-history deletion was executed.

The final corrected 5,000-patient measurement used 9,669 source rows (411,114
bytes): 9,188 committed, 481 intentionally needing review. Authoritative tables
contained exactly **3,761 visits for 3,761 visit sources** and **628 services for
628 service sources**. A separately uploaded, reformatted workbook then replayed
all source rows: 9,188 skipped, 481 still needing review, **zero new clinical
rows** across all nine checked identity/context/clinical tables.

To compare against current database/host conditions, the three changed existing
application classes (`ImportBatches`, `ImportBundle`, `DossierAuditValues`) were
extracted from exact reviewed SHA `50ac2e9` into ignored test storage and loaded
with a process-local PHP `auto_prepend_file`. All other application classes were
unchanged; the new resolver is not referenced by that original implementation.
The same current measurement harness ran against new synthetic source identities
for both versions. Neither the working branch nor the running Next/Laravel servers
was switched to base code. These are sequential local measurements, not a load test.

| Measurement | Corrected implementation | Exact reviewed implementation, remeasured |
| --- | --- | --- |
| Validation | 500 chunks; 32.783s; 24,695 queries | 500 chunks; 32.008s; 25,195 queries |
| Commit | 480 chunks; 137.034s; 245,038 queries | 480 chunks; 148.374s; 245,518 queries |
| Largest validation / commit chunk | 0.160s / 1.371s | 0.256s / 0.683s |
| Full separate-batch replay | 68.938s; zero additional clinical rows | 87.282s; zero additional clinical rows for this exact-replay workload |
| Initial workbook generation + import peak | 112 MiB | 112 MiB |

No material throughput/query/memory regression was observed in this comparison;
individual chunk timings vary. Corrected upload/parse was 2.516s/100 queries;
parser-only recheck 0.834s; final detail 0.511s/8 queries and list 0.037s/6 queries.
The unchanged 5,000-patient duplicate-source variant was rejected before storage.

Earlier attempts are **not** counted as successful measurements. The first hit
the local CLI's 128 MiB limit when generating the *second* large workbook after
the initial import. The combined measurement was rerun with an explicit 512 MiB
test-process limit, without changing application/server memory configuration.
Another attempt stopped with a `PDOException`; the original safe handler recorded
only its class, so its exact driver cause was not captured. The guard/connection
passed afterward, its remaining 149 chunks resumed successfully, and the full
fresh-source measurement above then passed without interruption. Test diagnostics
now include safe file/line and numeric driver code, never SQL or source values.

Independent-process concurrency ran **three times**, each yielding **200 / 409**,
five committed source rows and exactly two actual visits. The real fresh standalone
Next → Laravel → MariaDB import browser suite passed **4/4**, **85.136s**, no skips
or API interception, at 390/768/1440px. It verifies audit field values, paper/alias
assignments, the imported-action label/filter and real Laravel response headers.
Its retained MariaDB source/context verification also passed. Existing UI components
render the newly allowlisted values without a design or component change.

TypeScript, full ESLint and production build passed. Pint passed for every changed
PHP file. OpenAPI generation on both corrected code and the reviewed-class baseline
produced **identical documents and the same nine JR001 warnings**, with no new
warnings. `soffice` and the standard Windows LibreOffice installation remain absent:
no LibreOffice or desktop Excel rendering is claimed. This correction changes no
workbook formatting or print layout.

Final preserving Laravel regression run: **143 tests, 16,785 assertions, zero
failures/errors/skips**, 106.477s, 498 MiB peak with the documented ignored 1024M
test-process config. The command is the same eleven-class affected-regression
command above. It includes audit/history, registration and atomic saves, visit and
clinical writers, pathology, oncology, release safeguards and Patient Card scope.
`DossierImportTest` contributes **42 tests / 544 assertions**; the new replay/audit
regressions contribute **15 tests / 266 assertions**, all passing. These include
the original failing cases plus isolated dossier ownership and the valid textual
local reference `0`. No broad-suite failure remained to attribute to the base.
