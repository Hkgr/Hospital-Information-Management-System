# PR #10: table/export consistency

Baseline: `58ff775ef5ae576a257eaac2124dd72d074cff1f` on `fix/directory-performance-and-linking`.

The directory tables retained rows from request A while the workspace exported using request B's URL parameters. The old export guard only checked the debounce state, so committing a search, changing filters/page, or completing a save could enable export before the table's current request succeeded. A failed refresh also left export available alongside old rows.

## Implementation

Both workspaces now own their existing list request and pass that same result to the table. Export readiness is derived during rendering from that request's data, loading and error state, plus uncommitted search; it is not separate state or an asynchronous child notification. The request identity includes its complete path (including facility, search, filters, sort, page and page size), retry counter, save revision, response mode and bearer session. Identity changes on each transition, including A → B → A: returning to A's parameters must not treat the earlier A response as success of the new request. Old responses remain subject to the existing abort/context checks.

Excel/PDF controls and the export function itself reject export until the current list request succeeds. Failed refreshes retain prior rows and explicitly label them as previous results requiring a successful reload before export. Permissions, duplicate-export guards, selected columns and the full filtered-list export endpoint remain intact. Pagination is still handled by the table; the backend's existing export contract still exports all matching rows, not just the page.

Detail views pass a null list path and do not request a list. Their individual PDF report keeps its existing endpoint and remains independent of list readiness. No backend, migrations, authentication, conflict resolution or dependency changes.

## Actual verification

Tests ran against a local production standalone Next build, using Chrome/Playwright and the project's existing API fixtures/interception. No database or live backend was involved.

- Before changing application code: `node --test tests/directory-export-readiness.test.mjs` — **10 failed, 2 passed**. Each module reproduced enabled list-export controls during initial loading, delayed search, B→C response races, filter changes and facility changes. Both detail-report tests passed. The harness was first corrected to wait for A's rows (not merely the already-enabled export control), and to wait for the application's translated error message rather than a raw mock message; the counts above are from the corrected baseline run.
- Two additional A → B → A tests first failed after lifting the request but before correcting its identity: the parameter string matched the old A response while the new A request was pending. Both passed after replacing that reusable string with an identity specific to the transition.
- Final regression suite — **14 passed, 0 failed/skipped**. Assertions cover uncommitted search, failed B with old rows, no export requests while blocked, successful retry with B and selected columns in both formats, sorting/pagination/page size, post-save revision refresh, repeated parameters, and individual detail reports without a list request.
- Existing affected suites: `node --test tests/doctors.test.mjs tests/clinics.test.mjs` — **40 passed, 0 failed/skipped**, including conflict recovery, hidden links, session/facility cancellation, browser history, duplicate submissions and responsive cases.
- Final combined command: `node --test tests/directory-export-readiness.test.mjs tests/doctors.test.mjs tests/clinics.test.mjs` — **54 passed, 0 failed/skipped**, 40.6 seconds.
- `npx.cmd tsc --noEmit` — passed.
- `npm.cmd run lint` — passed.
- `npm.cmd run build` — production build passed.
- `git diff --check` — passed.

For reproduction, start the production standalone frontend locally and set `TEST_BASE_URL=http://127.0.0.1:3103` and `PLAYWRIGHT_CHANNEL=chrome` before the Node test commands. The test mocks export responses and does not save PDF/Excel files. Existing responsive captures remain in ignored local directories. No images, videos or generated reports are committed or posted to the PR. No production database connection, deployment or merge.
