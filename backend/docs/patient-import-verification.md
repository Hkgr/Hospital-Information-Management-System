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
