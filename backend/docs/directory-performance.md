# Directory performance and linking review

Measured on 11–12 September 2026 against baseline `87c4682ec94ee5e59ee4d0c31ba2d6a073d30967`, then the implementation on `fix/directory-performance-and-linking`.

## Findings and changes

- A normal paginated link-options request (`search=&page=1`) reached the nonexistent Laravel validation rule `prohibited_with` and returned a sanitized 500. `missing_with:ids` now implements the intended mutually exclusive batch/search contract using Laravel's installed validator. Both paginated pickers succeed, and combining `ids[]` with search or pagination returns 422, including an empty search value. Stable-ID conflict recovery remains unchanged.
- Doctor list loading waited for capability/options loading; closed relation and specialty filters requested choices on entry. The list now loads alongside capabilities, with mutation controls hidden until authorized options arrive. Relation filters load only when opened, or resolve the selected URL ID once for its name. Clinic specialty choices load on demand. Editors and doctor confirmation load through existing Next dynamic imports, without new packages.
- Both lists used joined, grouped patient totals across the facility's visits. MySQL `EXPLAIN ANALYZE` showed 144,000 facility visits being examined, 141,000 completed visits surviving, and materialized sorting/aggregation; the pagination count repeated this work. Correlated scalar counts now remain in the projection and do not run in the paginator's count. Count sorting still evaluates all matching records in SQL before LIMIT; it is not a page-local approximation.
- Existing visit indexes were insufficient for count sorting: after the query change alone, the doctor count-sort plan still took about 634ms with row lookups. Two covering indexes were added only after comparing EXPLAIN plans. The final doctor code-sort plan used a covering lookup (~0.41ms × 20 patient-count subqueries); the count-sort plan was ~139ms for all 400 doctors. These are individual plan observations, not latency percentiles.
- Existence checks for linking no longer load patient totals and previews. Single-record exports no longer first load the same detail/statistics a second time. The export pipeline, Cairo layout, full cell values, continuation sheets and access checks are preserved.
- General search now includes current counterpart names/codes through scoped `EXISTS`. Multiple current legacy links cannot duplicate a doctor/clinic, change its pagination total, or inflate distinct counts. Clinic search excludes inactive/ineligible doctors and expired/future links. Doctor search excludes inactive/foreign clinics and expired/future links. Existing own-record status filters still apply.
- `clinic_staff` and `ClinicStaffLinks` remain the sole write authority. No changes to permissions, lock versions, audit writes, historical periods, hidden relations, or foreign-facility links. Detail tables link to the counterpart only with its view permission. No protected-data cache was introduced.
- Tables retain previous results only in the same component, resource, facility and bearer session. New requests abort obsolete ones; errors offer retry, while 401/403 clear retained data. Existing URL/history/debounce and explicit conflict review behavior is retained.
- Modal widths follow content, headings remain outside the scrolling body, actions remain visible, and long choices scroll locally. Tables preserve RTL numeric isolation, wrap long text, expose full descriptions, and scroll horizontally inside their region. Cairo, branding and AppShell are unchanged.

## Test environment and dataset

PHP 8.4.14, MySQL 8.4.7, Node 24.11.0, Next 16.3.4 production standalone build, Chrome through Playwright, Windows local machine. Guard output: `APP_ENV=testing`, driver `mysql`, database `hospital_testing`, host `127.0.0.1`, port `13306`. No production connection or SQLite.

Synthetic September 2025–August 2026 workload: **60,000 distinct patients** (5,000/month), **180,000 visits** (three per patient), **60,000 linked procedures**, 400 doctors, 80 clinics, two facilities, five distinct staff accounts. The main facility has 64 clinics, 144,000 visits including 3,000 draft visits; the other facility has 36,000 visits. No real patient data. Before/after API measurements used the same inserted dataset and IDs; later functional writes and RefreshDatabase tests ran after those measurements.

The route was browser/client → production Next `/hospital-api` rewrite → guarded local Laravel router → MySQL. Instrumentation is opt-in under `tests/Support`, never part of application routes; it emits only aggregate test timing headers. API scenarios: one warm-up plus eight samples each, one HTTP request per sample, nearest-rank p50/p95, median SQL count/time and uncompressed response-body bytes. SQL count includes transactions and Sanctum's time-dependent `last_used_at` update; a one-query difference is not necessarily a removed SELECT. All 16 API scenarios returned 200 in both runs.

| Operation | p50 ms before → after | p95 ms before → after | SQL count before → after | SQL ms before → after | Response bytes before → after |
|---|---:|---:|---:|---:|---:|
| doctors_list | 1056.54 → 237.88 | 1271.64 → 265.68 | 8 → 7 | 735.74 → 13.37 | 33322 → 33322 |
| clinics_list | 1063.53 → 328.43 | 1273.93 → 355.29 | 7 → 6 | 807 → 74.72 | 25279 → 25279 |
| doctors_page_2 | 1095.79 → 275.22 | 1186.01 → 335.81 | 8 → 7 | 782.77 → 14.39 | 33349 → 33349 |
| doctors_search | 1158.53 → 260.34 | 1241.17 → 281.81 | 8 → 7 | 827.5 → 11.88 | 1726 → 1726 |
| doctors_cross_search | 278.9 → 235.77 | 400.21 → 257.64 | 6 → 7 | 7.41 → 11.64 | 67 → 8380 |
| clinics_cross_search | 270.58 → 214.48 | 321 → 305.64 | 4 → 6 | 4.79 → 8.16 | 67 → 1323 |
| doctors_clinic_filter | 1235.92 → 288.19 | 1374.22 → 371.59 | 8 → 7 | 910.32 → 7.76 | 8380 → 8380 |
| clinics_doctor_filter | 1094.97 → 236.66 | 1240.24 → 401.96 | 7 → 6 | 749.3 → 8.22 | 1323 → 1323 |
| doctors_patient_sort | 1076.18 → 278.82 | 1411.94 → 296.56 | 8 → 7 | 764.72 → 62.68 | 33322 → 33322 |
| clinics_patient_sort | 1041.5 → 308.36 | 1269.23 → 430.84 | 7 → 6 | 720.71 → 67.99 | 25279 → 25279 |
| doctor_detail | 684.65 → 237.89 | 833.44 → 266.71 | 7 → 6 | 369.94 → 4.75 | 1668 → 1668 |
| clinic_detail | 687.73 → 240.1 | 982.44 → 260.54 | 6 → 5 | 369.16 → 5.95 | 1265 → 1265 |
| doctor_link_options | 709.95 → 239.61 | 864.81 → 261.59 | 10 → 7 | 377.6 → 4.63 | 3709 → 3709 |
| clinic_link_options | 691.75 → 240.09 | 815.77 → 276.53 | 10 → 9 | 386.89 → 7.36 | 5788 → 5788 |
| xlsx_export | 871.96 → 467.73 | 968.77 → 497.25 | 12 → 11 | 360.92 → 20.32 | 60760 → 60761 |
| pdf_export | 1357.13 → 523.49 | 1581.97 → 542.21 | 12 → 9 | 740.14 → 15.38 | 97784 → 97784 |

Cross-search totals changed from **0 to 5 doctors** and **0 to 1 clinic**, fixing missing results; smaller baseline payloads were incorrect empty results, not an optimization. Other matched list totals and payloads stayed equal. XLSX varies by one byte due to generated file metadata.

## Browser observations

One sampled run per module at 1440×900, real API, same navigation/search/edit/save actions. These are diagnostic operation observations, not statistically established UX percentiles. Timings include the harness's network-idle wait and its 500ms editor settling interval. A React refresh can start after a modal closes; these timings are not guaranteed end-to-end completion of every subsequent table refresh.

| Action | Observed ms before → after | Network / API requests before → after | SQL count before → after | SQL ms before → after | API bytes before → after |
|---|---:|---|---:|---:|---:|
| Open doctors | 3319 → 2142 | 42 / 4 → 45 / 3 | 20 → 18 | 976.92 → 51.23 | 35367 → 35146 |
| Search doctors | 1628 → 630 | 2 / 1 → 2 / 1 | 8 → 8 | 905.71 → 17.19 | 1726 → 1726 |
| Open doctor editor | 572 → 877 | 1 / 1 → 2 / 1 | 3 → 7 | 6.74 → 5.32 | 221 → 3709 |
| Save doctor | 855 → 848 | 2 / 1 → 2 / 2 | 22 → 30 | 365.25 → 40.79 | 1668 → 3394 |
| Open clinics | 3194 → 1406 | 43 / 4 → 44 / 2 | 17 → 10 | 681.89 → 83.64 | 27021 → 26676 |
| Search clinics | 1457 → 751 | 2 / 1 → 2 / 1 | 7 → 7 | 800.27 → 18.07 | 1323 → 1323 |
| Open clinic editor | 570 → 1351 | 2 / 1 → 3 / 2 | 3 → 14 | 6.01 → 19.38 | 221 → 5912 |
| Save clinic | 1370 → 351 | 2 / 2 → 2 / 1 | 19 → 14 | 395.99 → 10.23 | 1389 → 1265 |

Each baseline module entry/editor encountered one link-options 500; all after-run browser actions had zero HTTP errors. Opening a broken editor quickly is not a successful performance baseline. First editor opening now includes an extra lazy JS chunk and successful choices. Total network requests did not uniformly decrease. JS parsing/CPU and bundle-byte deltas were not profiled; no bundle size reduction is claimed. The unchanged proxy adds a local hop, and PHP CLI boot/instrumentation still accounts for much of the remaining ~200ms single-request time. No timeout increases, infrastructure change, global cache or dependency additions.

## Concurrency and functional writes

Five separate staff sessions, each issuing 12 serial mixed requests concurrently with the others: 60 requests/run. Before p50 **4388.52ms**, p95 **5448.44ms**; after p50 **1538.13ms**, p95 **1869.53ms**; errors **0/60 (0%)** in both. This Windows PHP CLI server serves requests serially; these figures include queueing and do **not** establish production throughput or staff capacity. There was no sustained write-pressure or multi-worker deployment benchmark.

`node tests/directory-live.mjs` passed 16 real HTTP checks through the frontend proxy: create doctor/clinic, paginated pickers, link/unlink from both directions, re-read the counterpart and counts after each save, both cross searches, ended-link exclusion and stale-version 409. Observed after-only times: create doctor 919ms (20 SQL, 30.18 SQL ms, 1451 bytes); create clinic 480ms (14 SQL, 18.18 SQL ms, 967 bytes); link from doctor 271ms (20 SQL), unlink from clinic 311ms (19 SQL), link from clinic 338ms (20 SQL), unlink from doctor 331ms (20 SQL). These writes were not given a matched before benchmark, so no write-speedup percentage is claimed. The first live run timed out in Ramsey UUID initialization on Windows before returning a create result; a fresh retry passed. This transient failure is outside the measured 60-request concurrency runs and is not included in their error rate.

## Verification

- `php artisan test-db:check --connect --env=testing`: passed before database operations.
- New migration `up`, `rollback --step=1 --path=...`, and `up` again: passed against the 180,000-visit testing database (up ~2–3s, rollback ~0.67s). Existing migrations were not edited. Subsequent RefreshDatabase tests also successfully rebuilt the testing schema.
- `php artisan test --env=testing --filter='DoctorApiTest|ClinicApiTest|DirectoryReport'`: **46 passed, 3121 assertions**. Includes true MySQL linking/history/scope/permission/conflicts, active types, search and count sorting/pagination, bounded queries, no visit reads in options, reports/OpenAPI, and prior long-text report regressions.
- `TEST_BASE_URL=http://127.0.0.1:3103 PLAYWRIGHT_CHANNEL=chrome CLINIC_CAPTURE=1 node --test tests/doctors.test.mjs tests/clinics.test.mjs`: **40 passed**, no skips. These UI regression suites deliberately mock API responses; they do not replace the real API checks above. Covers delayed capabilities, lazy filters, selected URL name, counterpart navigation, retained rows/retry, obsolete search/facility/session responses, double-save guards, both conflict review flows including 400 explicit link changes, hidden/renamed links, and history restoration.
- `npx tsc --noEmit`: passed. `npm run lint`: passed after correcting the new benchmark script's reserved variable name. `npm run build`: production build passed; the last build compiled in 30.5s. On this PowerShell installation the executable commands use `npm.cmd` / `npx.cmd` because `.ps1` execution is disabled.
- `php vendor/bin/pint --dirty --test`: passed. `git diff --check`: passed (only Git line-ending conversion notices).
- Local visual inspection of Chrome captures at 390/768/1440: tables, editors and linked records; long content scrolls within the dialog/region, headings and save/cancel remain available. Keyboard containment and focus restoration passed. Captures stay in ignored `.superdesign/directory-review`; no image, video, PDF or XLSX output is added to Git or the PR.
- The three responsive doctor cases were rerun with an additional assertion that the last choice remains reachable above the save bar after scrolling: **3 passed**. Its initial run found an ambiguous test heading selector, corrected to the dialog title before rerunning; this was a test-harness failure.

## Reproduction and rollout requirements

Use an already provisioned, explicitly isolated MySQL testing database. Configure ignored `backend/.env.testing` following `.env.testing.example` and pass `test-db:check --connect --env=testing` **before** migrations/seeding/tests. Do not create or drop a database automatically. Run schema setup only after that guard. No production credentials belong in tracked files.

For opt-in measurements, from `backend` run `php tests/Support/prepare-directory-performance.php` after setting up the test schema. It checks the same safety guard and inserts only synthetic records in a transaction. Its manifest, generated credentials/tokens and results live in ignored `storage/framework/testing/`; keep the manifest and database unchanged across before/after runs. It refuses to replace an existing manifest. After a RefreshDatabase test run, that manifest is stale: archive it locally and intentionally prepare a new workload before measuring again. Never reuse it against another database.

Start the test-only router on loopback: `php -S 127.0.0.1:8010 tests/Support/directory-performance-router.php`. Build Next with `LARAVEL_API_URL=http://127.0.0.1:8010/api`, copy public/static assets to the standalone output as Next requires, and run standalone locally on port 3103. Then run `node tests/directory-performance.mjs before` / `after` and `node tests/directory-live.mjs` from `frontend`; `--ui` reruns browser observations only. `php tests/Support/explain-directory-performance.php before|after` saves local EXPLAIN ANALYZE results. Run these before RefreshDatabase tests reset the workload. Instrumentation/configured synthetic staff type is not for deployment.

Deployment, if separately approved, needs the new `2026_09_12_000002_add_directory_patient_count_indexes.php` migration. Both covering indexes add storage and visit-write maintenance; production index-build locking, disk use, write throughput and hardware capacity were not measured here. Review an appropriate rollout window. Configure `CLINIC_DOCTOR_STAFF_TYPES` with actual active `staff_types.code` values; no guessed types or IDs. Missing/nonexistent/inactive configured types produce an explicit configuration state, not an ambiguous empty picker.

No production database was contacted; no deployment or merge was performed. No FastAPI, Login, auth contracts or permission model changes.
