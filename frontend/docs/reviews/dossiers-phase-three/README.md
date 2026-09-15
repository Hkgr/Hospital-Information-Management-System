# Phase 3 — actual browser and report review

All data in this gallery is synthetic. These are screenshots of a fresh Next production standalone build using **real Laravel and isolated MariaDB 10.11.18**, not API mocks or design-canvas renders. The established Phase 2 wizard, doctor/clinic tables, local Cairo and RTL remain the references.

## Responsive comparison

The reference column links the unchanged Phase 2 implementation. Each Phase 3 link opens a complete-page capture; `-viewport` links show the screen at normal scale. Tables retain their own horizontal scroll area on phones. A browser regression separately asserts that the surrounding content canvas does not overflow — checking only the document width had initially missed this issue.

| View | 390px | 768px | 1440px |
| --- | --- | --- | --- |
| Phase 2 reference: saved diagnoses | [Reference](../dossiers-phase-two/multiple-diagnoses-390.jpg) | [Reference](../dossiers-phase-two/multiple-diagnoses-768.jpg) | [Reference](../dossiers-phase-two/multiple-diagnoses-1440.jpg) |
| Services/procedures | [Full](clinical-390.jpg) · [Viewport](clinical-390-viewport.jpg) | [Full](clinical-768.jpg) · [Viewport](clinical-768-viewport.jpg) | [Full](clinical-1440.jpg) · [Viewport](clinical-1440-viewport.jpg) |
| Prescription and outgoing referral | [Full](prescription-390.jpg) · [Viewport](prescription-390-viewport.jpg) | [Full](prescription-768.jpg) · [Viewport](prescription-768-viewport.jpg) | [Full](prescription-1440.jpg) · [Viewport](prescription-1440-viewport.jpg) |
| Validation errors | [Full](outcome-errors-390.jpg) | [Full](outcome-errors-768.jpg) | [Full](outcome-errors-1440.jpg) |
| Attachments and review | [Full](review-390.jpg) · [Viewport](review-390-viewport.jpg) | [Full](review-768.jpg) · [Viewport](review-768-viewport.jpg) | [Full](review-1440.jpg) · [Viewport](review-1440-viewport.jpg) |

Additional evidence: [list](list-1440.jpg), [completed dossier at 768px](detail-768.jpg), [completed dossier at 1440px](detail-1440.jpg), [explicit completion](confirm-768.jpg), [subsequent visit](subsequent-768.jpg), [explicit conflict review](conflict-1440.jpg). The conflict test also covers two editors creating a prescription concurrently: retained header/other items stay, and the user explicitly opts into adding their draft item.

## Actual server-generated reports

| Report | PDF | Excel |
| --- | --- | --- |
| Filtered dossier list | [PDF](list.pdf) | [XLSX](list.xlsx) |
| Individual dossier | [PDF](dossier.pdf) | [XLSX](dossier.xlsx) |
| Individual visit | [PDF](visit.pdf) | [XLSX](visit.xlsx) |

PDF pages were rendered using **PDF.js 6.3.289 / @napi-rs/canvas** and visually inspected: [list](print/list-1.jpg), [dossier 1](print/dossier-1.jpg), [dossier 2](print/dossier-2.jpg), [dossier 3](print/dossier-3.jpg), [visit 1](print/visit-1.jpg), [visit 2](print/visit-2.jpg), [visit 3](print/visit-3.jpg). The PDF font objects contain embedded `MPDFAA+Cairo-Regular` and `MPDFAA+Cairo-Bold`. Arabic shaping, repeated headers, footers/page numbering and visible ends of these samples were checked.

All three XLSX files opened **read-only in Microsoft Excel 16.0, build 20326**, with Cairo installed: one sheet for the list, 12 sheets each for dossier and visit. RTL and cell values were read successfully. PHP regression tests also reopen the workbook and verify text/formula safety and numeric/date cell types.

**Excel print-preview verification remains blocked.** `ExportAsFixedFormat` opened a local “Printer Setup” dialog and failed with `0x800A03EC`; selecting the existing PDF printer within that Excel instance also failed. The task's dialog was cancelled and its Excel instance closed. No Windows default printer was changed and unrelated Excel instances were left alone. Opening/reading workbooks is not claimed as visual print verification. The actual XLSX files above are available for that remaining review.

The [operating contract](../../../../backend/docs/patient-dossiers-phase-three.md) and PR contain exact test results and configuration requirements. No production data, deployment or merge was involved.
