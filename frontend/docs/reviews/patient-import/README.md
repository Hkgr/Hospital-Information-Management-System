# Patient Card import review

Real production-build Next → Laravel → isolated MariaDB screenshots, using only
generated synthetic fixtures. No API interception. The operator's original XLSX,
patient payloads, credentials and private storage paths are not published here.
The existing AppShell, shared directory tables, RTL and local Cairo are retained.
Tables scroll within their containers. Screenshots disable transient animations
so the captured sidebar and content have reached the same layout state.

| Viewport | Required-file/focus error | Validated preview | Partial commit with review rows |
| --- | --- | --- | --- |
| 390px | [Open](errors-390.png) | [Open](preview-390.png) | [Open](complete-390.png) |
| 768px | [Open](errors-768.png) | [Open](preview-768.png) | [Open](complete-768.png) |
| 1440px | [Open](errors-1440.png) | [Open](preview-1440.png) | [Open](complete-1440.png) |

The browser suite also downloads the template/error workbook, resumes a persisted
batch after navigation, corrects invalid references and resubmits stable source
IDs, verifies previously committed rows are skipped, opens the resulting Patient
Card, and rejects unauthorized access through the real Next proxy.
