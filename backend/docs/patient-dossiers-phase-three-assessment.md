# Patient dossiers Phase 3 — pre-migration assessment

The baseline assessment below was recorded before migrations. The mapping incorporates the subsequently approved prescription header/items contract. Actual verification is recorded in [the Phase 3 operating contract](patient-dossiers-phase-three.md).

## Approved prescription mapping

The user approved explicit global `medications.create`, reuse of `medications`, and one active prescription header per visit with multiple items. `visit_prescriptions` stores facility/visit, prescribing clinic/staff, visible explicit `prescribed_on`, general note, request UUID, actors, version and void metadata. `visit_prescription_items` stores prescription/medication, immutable code/name snapshots, medication-specific note, display order, actors, version and void metadata. Replacing a medication explicitly replaces its snapshots with the newly selected definition; unchanged saved items never refresh snapshots from mutable directory names/codes. Audits retain old/new values.

The prescription date defaults to the visit date only in a new browser draft and remains explicit. Later visit-date corrections revalidate the prescribing context against the new visit date but never silently change that explicit prescription date. Existing `visit_medications` dispensing and dose administration data/schema retain their meaning and are not written by this workflow.

One active header is protected by a generated nullable active-visit key and a unique index. Scoped prescription/item and visit/clinic FKs prevent cross-facility associations. Omitted items remain; replacement/void needs current versions and explicit reasons for voids. Medication creation is a separate UUID/audited action with normalized duplicate code/name rejection, not a side effect of prescription saving. Definitions are seeded without grants.

The existing visit completion CHECK requires `attending_staff_id`. Final review therefore explicitly selects the responsible visit clinic/doctor and validates membership; it never copies a diagnosis/prescription doctor implicitly. The six-section progress migration must add per-visit uniqueness and scoped visit/dossier integrity, preserving initial-visit identity. These are necessary schema constraints, not new clinical assumptions.

## Verified baseline

On 2026-09-15, fetched `origin/develop` at `cb6d6cfc66f58b6a37fdf3022322c61b3d9b8c01`. GitHub confirms PR #21 merged. `git merge-base --is-ancestor 6b6ba9bc43136a9265f6296a0a20433762345d12 origin/develop` returned 0. Created `feature/patient-dossiers-phase-three` from that head with an initially clean worktree.

Read `frontend/AGENTS.md` and the Phase 1/2 operating contracts. Findings below come from the committed migration chain and application code, not a production database connection. No database connection, migration, seeder, permission grant or test fixture was run for this assessment.

## Decision resolved

The existing medication directory had no authorized creation API. The user explicitly approved global `medications.create` and a separate prescription header/items model. Existing `visit_medications` represents dispensing and remains unchanged. No permission is granted automatically.

## Field mapping and remaining schema work

The table records the inspected baseline and the implemented mapping.

| Area | Existing authoritative fields | Required mapping / safeguard |
| --- | --- | --- |
| Identity and persistent dossier | `patients`; `patient_dossiers` with patient/facility/code uniqueness, status/version; relational oncology selections | Reuse without identity copies. Activation must be explicit and audited; no automatic activation from populated fields. |
| Visits | `visits.dossier_id`, patient/facility composite FK, actual `visit_date`, status/version/actors, server UUID visit number | Preserve actual chronology and initial-visit identity. A subsequent-visit create operation must explicitly target an active dossier and return its own visit ID, with request replay preventing duplicate creation. Completed records stay read-only; no completed-visit correction lifecycle currently exists. |
| Progress | `dossier_section_progress`: section VARCHAR(20), unique `(dossier_id,section)`, optional visit/facility FK; CHECK only personal/medical/visit; visit ID currently allowed only for visit section | Extend this table for clinical/medications/attachments. Merely expanding the section CHECK is insufficient for multiple visits: uniqueness must distinguish dossier-level personal/medical progress from each visit's clinical progress. A proposed generated non-null visit scope (`COALESCE(visit_id,0)`) supports unique dossier/section/scope while preserving one dossier-level row. Require a visit/dossier/facility FK and matching CHECKs; retain the original initial visit progress when adding later visits. Never create progress on GET. |
| Diagnoses | `visit_diagnoses`: diagnosis, clinic, diagnosing doctor, nullable diagnosis date, version/actors/void metadata; period already nullable | Preserve Phase 2 omission/CAS/audit rules and historic inactive contexts. Changing the visit date must validate all retained diagnoses plus new clinical contexts within the same transaction. |
| Services | `visit_services.service_id`, `performed_by`, `performed_on`, `note`, quantity default 1, delivery location default internal, scope/request/version/actors/void metadata | Reuse existing occurrences and definitions. Add nullable historical `clinic_id` with composite clinic/facility FK, require an explicit clinic/doctor for new dossier entries. `performed_on` equals actual visit date for dossier-managed entries; one selected row represents one occurrence. Never overwrite historical quantity/delivery metadata when correcting another field. |
| Procedures | `visit_procedures.procedure_id`, `specialist_id`, optional nurse, `performed_on`, note, quantity default 1, scope/request/version/actors/void metadata | Same clinic/FK/date invariant as services; specialist is explicitly selected, never substituted from attending staff. Keep nurse/history untouched. |
| Medication directory | `medications` as described above | Reuse definitions; explicit global creation permission, normalized duplicate protection and independent UUID/audit API. |
| Medication occurrences | Existing dispensing `visit_medications` and administration `dose_sessions`/`dose_session_items` | Do not relabel historical dispensing as prescriptions. Approved prescription header/items and snapshots are described above. |
| Outcomes | `visit_results` with code/name/result_group; `visit_outcomes.result_id`, `decided_by`, actual `outcome_on`, optional `referral_target`, note, version/actors/void metadata | Stable codes for the exact five requested outcomes. Add clinic context and distinct outgoing referral date/reason; keep incoming visit referral fields untouched. Current unique `visit_id` permits only one row even after voiding: replace with uniqueness of the nonvoid current visit if retaining voided predecessor rows. Preserve existing IDs because `case_reviews` and `death_records` reference outcomes. No automatic death/admission record creation. |
| Reporting periods | Services, procedures, dispensing and outcomes currently have NOT NULL period IDs and composite period/facility FKs; visits/diagnoses already nullable | New dossier-managed service/procedure/outcome writers need NULL periods without changing historical values or FKs. Prescriptions have no period field. The unrelated dispensing table remains unchanged. Rollback must preflight all new data/NULLs before any MariaDB implicit-commit DDL. |
| Attachments | No clinical attachment table or upload/download workflow. Report exports have a storage key but are not visit attachments | Proposed private attachment metadata tied to visit/dossier/facility with generated storage key, original filename/title, detected MIME, extension/size/hash, actors/time/version/void metadata. Separate staging/final state must prevent authoritative orphans. Stream through a permission-checked endpoint; never expose keys or bearer URLs. Dedicated private disk with direct serving disabled, configurable maximum, temporary-file cleanup and no unverified malware-scanner claim. |
| Clinical date correction | Each legacy clinical event has its own required date | For dossier-managed service/procedure entries, atomically propagate visit-date corrections to retained `performed_on` and audit/version every changed row after context validation. Preserve untouched legacy periods. Outcome date stays explicit; no automatic rewrite of incoming/outgoing referrals or historical dispenses. The explicit prescription date remains unchanged. |

## Existing mechanisms to reuse

- `DossierWrites` provides transactional UUID fingerprints/replay, row versions, dossier locking, section progress and `ClinicAudit`. `DossierVisitWriter` locks all retained/submitted staff before clinics and revalidates unchanged historical contexts on date correction. Extend those invariants to all visit sections; omitted rows must survive.
- `DossierWorkflowActions` batches initial-visit/progress and capability decisions. It currently assumes one initial visit and three sections; later visits need explicit visit-scoped action/snapshot selection, not a fallback that silently selects the initial draft.
- `ClinicStaffLinks` uses ascending staff-before-clinic locking and inclusive-start/exclusive-end membership periods. `DirectoryReferences::CLINIC` will need the additional clinical clinic FKs; any prescription table needs both doctor/clinic reference checks. Historical inactive actors remain visible.
- `CatalogBeneficiaries` requires complete, nonvoided visits. Keep that eligibility definition unchanged; draft dossier events must not prematurely become Catalog beneficiaries. Explicit completion may make eligible facts visible normally.
- `CatalogReports` uses authoritative bounded queries and `DirectoryReport`. `BloodEventReports` demonstrates detailed sections and shared PDF rendering. `DirectoryReport` embeds local Cairo, blocks remote assets, supplies RTL/page headers/footers. `DirectorySpreadsheet` uses explicit text types, measured wrapping and continuation sheets. Extend these patterns for dossier/visit report sections without replacing their design. Current report exporters write export audit entries: implement new dossier exports as explicit POST actions if recording audit, preserving the no-GET-writes requirement.
- No completed-visit reopening/correction contract is implemented. Ordinary draft writes must keep rejecting complete visits; this phase should document completed visits as read-only.

## Verification

See [Phase 3 operating contract](patient-dossiers-phase-three.md) for the actual commands, isolated database, results and remaining limitations. The pre-migration assessment itself used source inspection only.
