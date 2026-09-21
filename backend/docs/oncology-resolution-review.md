# PR #27 follow-up: resolution invariants and retained-attempt visibility

Reviewed starting SHA: `34c87ca65081672837c327c5ce14dadee77fdb7b`.
Final SHA is recorded in the PR description. Same branch and open draft.

## Causes and corrections

- Carry-forward previously copied revision/clinic/doctor even for cancelled, missed
  and referred resolutions, and accepted already-current sessions. The shared writer
  now permits it only for obsolete sessions with an explicit rescheduled date and
  effectively active plan. Invalid combinations return ONCOLOGY_INVALID_CARRY_FORWARD.
- The request exempted carry-forward from mandatory dates and the writer reused the
  old planned date. Every reschedule now requires planned_on in both layers.
- Selecting dose.voided_at from an active-only join always returned null. Session
  reads now return a boolean has_voided_dose through correlated EXISTS, independently
  of active dose_id/visit_id. The table and Card appointment reports use the indicator
  without multiplying rows for multiple historical attempts.
- The UI clears carry on terminal selection, hides it for terminal/current sessions,
  keeps an explicit required date for reschedules and normalizes the reviewed draft
  and submitted payload after conflicts. Other draft fields are retained.

Terminal resolution without carry preserves original revision, clinic, doctor and
planned date. Invalid atomic void keeps dose/session/audit unchanged. Existing active
uniqueness, scope/visit/revision FKs, effective readiness, historical revisions,
independent dispensing and concurrent-write protection remain. No migration, route,
permission name, general dossier contract or patient identity change is added.

## Before-fix evidence

On the reviewed application code, new regression tests ran with the preserving
bootstrap: **6 tests, 80 assertions, 5 failures**, 2.465 seconds. One existing-correct
case (terminal resolution without carry preserving context) passed. Failures proved:

1. The writer accepted terminal carry-forward.
2. It accepted meaningless carry of a current revision.
3. It accepted carry without a submitted planned date.
4. Invalid full-dose void returned 200 and mutated records.
5. Session history had no boolean historical-attempt indicator.

The tests call the public writer directly as well as HTTP endpoints, so tightening
FormRequest alone cannot make them pass. Local proof is in
`storage/framework/testing/oncology-resolution-before.xml` (not committed).
The first fixed run passed all six with 154 assertions; a further assertion also
covers a direct non-carry reschedule without a date.

## Verification

Existing guarded populated `blood_bank_cities_testing`, `127.0.0.1:13416`, driver
`mysql`, MariaDB 10.11.18. No fresh/reset/truncate, new database or retained clinical
history deletion. Test fixtures roll back transaction writes; browser history is
retained and its temporary tokens are revoked.

- `php artisan test-db:check --connect --env=testing`: passed.
- `php artisan migrate --env=testing`: no pending migrations; none added here.
- `php tests/Support/oncology-integrity-migration.php`: active-revision FK rollback
  and re-upgrade passed. The prior active-uniqueness migration correctly refused an
  incompatible rollback before DDL because historical replacement attempts exist.
  Ordered SHA-256 snapshots of five clinical tables were unchanged. Safe rollback
  of that older migration was demonstrated in the preceding pass, not repeated here
  by deleting the retained records.
- Affected Laravel: **101 tests / 16,241 assertions / 0 failures / 0 skips**, 56.467 s.
  Classes: OncologyTreatmentTest (28), PatientCardTest, DossierApiTest,
  DossierWorkflowTest, DossierCompletionTest, DossierCompletionSafetyTest,
  DossierCompletionIOTest, DossierClosureTest, DossierPathologyTest and
  DossierReleaseVerificationTest. Command prefix:
  `php vendor/bin/phpunit --bootstrap tests/Support/preserve-database.php`, followed
  by those ten `tests/Feature/<class>.php` files. JUnit:
  `storage/framework/testing/oncology-resolution-suite.xml`.
- Initial 101-case run: one fixture-setup error, 16,233 assertions. Faker generated
  an email already retained by a previous run. CatalogFixture's two synthetic user
  emails now use its existing unique fixture tag; no application factory, old user,
  medical row or production setting was changed. The complete 101-case run above
  passed after this harness correction, rather than only rerunning the failed case.
  A subsequent visual check found the new conditional required-date message falling
  back to English; its Arabic message and assertion were added. The final complete
  rerun above includes that last change.
- TypeScript `npx tsc --noEmit`: passed.
- ESLint `npm run lint`: passed.
- Next production `npm run build`: passed; built with the test Laravel URL ending
  in /api, fresh standalone/static assets, new loopback servers on 3194/8194.
- OpenAPI export: succeeded with nine existing inference warnings, as in the prior
  base comparison. Conditional date/carry requirements and read indicator documented.

Final `node --test tests/oncology-live.test.mjs`: **7 passed / 0 failed / 0 skipped**,
239.111 seconds, real Next ? Laravel ? MariaDB without API mocking or page.route.
The first corrected seven-case run passed (244.952 s), followed by the timeout run
explained below; the final complete run above includes the readiness correction.
Concurrency in the final run: same UUID 200/200, one dose ID; distinct UUIDs 200/409,
one active dose; correction 200/409. All three responsive widths and conflict review
were exercised. Local log: `frontend/.superdesign/tmp/oncology-resolution-browser.log`.

Readiness-harness investigation: the first real seven-case run passed. The next
run passed six cases but a PHP concurrency worker hit the existing 30-second
process timeout. Three instrumented real-connection trials then passed without
reproducing that original timeout. Its exact stage was not logged in the failed
run, so that timing cannot be reconstructed.

A deterministic defect was isolated in the existing waitUntil callback: Process
may read READY during start/updateStatus before the callback is installed; a READY
marker split across reads is also missed. The parent then keeps its dossier lock
while waiting for a signal already buffered. Two subprocess regressions failed
against the old callback and pass using the complete per-worker output buffer.
The original 30-second timeout and application locks are unchanged. The final
regressions use stdin handshakes (not timing sleeps) to force early/split output.
`php vendor/bin/phpunit tests/Unit/OncologyProcessTest.php`: **2 tests, 2 assertions,
0 failures/skips**, 0.929 s. Three further real trials with the fixed helper passed
all replay, competing-administration and correction checks. The final full browser
rerun is recorded above; no individual trial substitutes for it.


`php tests/Support/verify-oncology-workbooks.php`: all three workbooks reopened
successfully, including the new scheduled-session history text. Card: 21 sheets,
36 typed dates, 7 numbers, 2 leading-zero codes, 2 formula-like safe texts; visit:
17 sheets, 9 dates, 3 numbers; list: 1 sheet, 2 dates, 1 numeric count. RTL/Cairo,
print properties, selected columns, parent-void labels and privacy checks passed.
Existing mPDF outputs rendered using PDF.js: card 11, visit 6, list 1 pages. The
first successful run's pages were inspected, then the final affected Card schedule
page was re-inspected for the historical indicator alongside the active dose/visit.
No clipping or overlap was observed in the inspected samples.

Real screenshots at 390/768/1440 cover required-date focus/error, terminal conflict
review and one-row session history alongside an active replacement. See the
[latest gallery](../../frontend/docs/oncology-review/README.md).

Pint (dirty and both new readiness-test files) and `git diff --check`: passed.

The broader ten populated-table baseline failures from the preceding pass are
[documented separately](oncology-corrective-review.md); those unrelated classes
were not rerun for this focused correction. This is not a full Laravel-suite claim.
LibreOffice/Excel printer preview is unavailable; PDF.js inspection and workbook
assertions do not imply an Excel printer-preview pass.

No production access, FastAPI, AppShell, inventory, billing, global patient identity,
permission-name change, merge or deployment. PR #27 remains an open draft.
