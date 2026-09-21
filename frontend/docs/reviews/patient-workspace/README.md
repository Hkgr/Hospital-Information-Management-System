# Patient card / visit workspace

Actual Chrome captures from the production standalone build, through local Laravel
and isolated MariaDB 10.11. All identities and clinical entries are synthetic.
These are implemented-page captures, not generated mockups. No before-image is
claimed. Cairo is served locally and the existing RTL application frame is retained.

The permanent card, current visit, optional oncology tools and final review have
separate navigation groups. On small screens the groups become a section selector.
Lookup controls open on demand and keep clinic/date eligibility enforced by Laravel.

| View | 390px | 768px | 1440px |
| --- | --- | --- | --- |
| New card and first real visit | [Phone](new-390.png) | [Tablet](new-768.png) | [Desktop](new-1440.png) |
| Validation, retained draft and first-field focus | [Phone](errors-390.png) | [Tablet](errors-768.png) | [Desktop](errors-1440.png) |
| Visit and clinic/doctor selection | [Phone](visit-390.png) | [Tablet](visit-768.png) | [Desktop](visit-1440.png) |

Additional desktop views: [assessment and pathology](pathology-1440.png),
[treatment planning](treatment-1440.png), [actual administration and dispensing](administration-1440.png).

The underlying real-browser regression is `tests/patient-workspace-live.test.mjs`.
See [backend contract and verification](../../../../backend/docs/patient-visit-workspace.md)
for migration and test limits.
