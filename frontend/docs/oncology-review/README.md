# Oncology workflow — actual browser review

Synthetic fixtures only. Captured from Chromium against a fresh Next production
standalone → Laravel → isolated MariaDB, not mocked API responses. The existing
Patient Cards interface, shared clinic/directory components, RTL and local Cairo
are retained. There is no new AppShell navigation item.

Open the images at original size to inspect the complete detail page or a dialog's
current scroll position. On small screens, the long form scrolls inside the modal;
table overflow stays inside the shared table container. Scheduling and actual
administration/dispensing use distinct labels and explicit saves. The error images
show focus moving to the first invalid field while retaining the draft.

| Screen | 390 px | 768 px | 1440 px |
| --- | --- | --- | --- |
| Filtered card list | [Image](list-390.png) | [Image](list-768.png) | [Image](list-1440.png) |
| Complete card / selected visit | [Image](detail-390.png) | [Image](detail-768.png) | [Image](detail-1440.png) |
| Plan editor / clinician selection | [Image](plan-editor-390.png) | [Image](plan-editor-768.png) | [Image](plan-editor-1440.png) |
| Validation and focused first error | [Image](plan-errors-390.png) | [Image](plan-errors-768.png) | [Image](plan-errors-1440.png) |
| Explicit appointment confirmation | [Image](schedule-390.png) | [Image](schedule-768.png) | [Image](schedule-1440.png) |
| Actual administration | [Image](administration-390.png) | [Image](administration-768.png) | [Image](administration-1440.png) |
| Independent dispensing | [Image](dispensing-390.png) | [Image](dispensing-768.png) | [Image](dispensing-1440.png) |

The local oncology grid prevents short fields stretching alongside pickers. Picker
rows accommodate long wrapped labels; browser geometry checks verify the visible
label stays inside its button, without prescribing a particular CSS value.

Rendered PDF examples: [card treatment history](card-page-7.png),
[visit administration and dispensing](visit-page-5.png),
[filtered list](list-page-1.png). All 14 PDF pages were reviewed locally;
PhpSpreadsheet independently reopened all three generated XLSX files. These images
are application PDF renders, not an Excel/LibreOffice print preview.

See [verification, exact results and limitations](../../../backend/docs/oncology-verification.md)
and [workflow/operator contract](../../../backend/docs/oncology-treatment-workflow.md).

A synthetic rendered-design snapshot is also available on the existing
[review canvas](https://superdesign.dev/teams/549c1e0f-a226-4fd4-a322-680d4caca073/projects/d9d795ca-f01b-4f48-9b0a-f9db7209e7b7?node=draft-variant-d18680d1-3ebd-459d-ba23-f39c02c98f75).
The screenshots above are the actual implementation evidence.
