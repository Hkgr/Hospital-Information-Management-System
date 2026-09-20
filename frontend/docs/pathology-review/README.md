# Diagnostic assessment and pathology: visual review

Actual Chromium screenshots against production standalone Next, Laravel and isolated
MariaDB, using synthetic patients only. Existing Cairo, RTL, shared dialogs and tables
are reused. Small screens scroll the tables internally, with no document-level overflow.
The source visit is preserved for every report. Validation focuses the first invalid
field while retaining the report number and the rest of the draft.

| Width | List | Patient Card and selected visit | Validation | Lower form/actions |
|---|---|---|---|---|
| 390px | [List](list-390.png) | [Details](detail-390.png) | [Errors](form-errors-390.png) | [Actions](form-bottom-390.png) |
| 768px | [List](list-768.png) | [Details](detail-768.png) | [Errors](form-errors-768.png) | [Actions](form-bottom-768.png) |
| 1440px | [List](list-1440.png) | [Details](detail-1440.png) | [Errors](form-errors-1440.png) | [Actions](form-bottom-1440.png) |

Errors in the lower-form screenshots are from the preceding failed validation; entered
values remain available for resubmission. The subsequent real save succeeded at all widths.

The actual generated PDF was rendered using PDF.js, then all pages were inspected:
4 Patient Card pages, 4 selected-visit pages, and 1 list page. Pathology uses source-visit /
field / value rows in the current shared template, keeping report dates, leading-zero
report numbers and conclusions readable. Examples:

- [Patient Card pathology page](patient-card-page-2.png)
- [Selected visit pathology page](visit-page-2.png)
- [Filtered list PDF](list-page-1.png)

Three real XLSX downloads were reopened using PhpSpreadsheet and the existing shared
workbook assertions. This is not a claim of Excel desktop or printer preview testing.
See [backend verification and deployment notes](../../../backend/docs/patient-card-pathology.md)
for exact results and the populated-database limitations of the broader directory tests.

## State/date correction

The real integration regression changes source/status and diagnostic disposition,
preserves the draft after 409, explicitly reviews the latest version, and saves the
chosen compatible fields. It checks both effective-state panels and absence of stale
fields at every width. The following are synthetic screenshots from that passing run:

| Width | Draft retained during conflicting save | Saved effective state |
|---|---|---|
| 390px | [Draft](correction-conflict-390.png) | [State](correction-state-390.png) |
| 768px | [Draft](correction-conflict-768.png) | [State](correction-state-768.png) |
| 1440px | [Draft](correction-conflict-1440.png) | [State](correction-state-1440.png) |

The draft images capture the retained editor around the 409 response, before opening
the explicit latest-version review. They are not screenshots of the review checklist.
The full-page state images retain the existing fixed AppShell and shared tables;
this correction does not redesign them.

[Normalized PDF assessment and pathology page](normalized-page-2.png) shows the current
pending decision and retained generic context. The report's four pages were inspected;
the stale organization, unavailable reason, final conclusion and incompatible decision
reason are absent. The corresponding XLSX passed the shared workbook checks.
