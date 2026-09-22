import type { Choice } from "../blood-bank/api";
import type { Snapshot } from "./wizard";

export type SavedRow = { id?: number; lock_version?: number; remove?: boolean; void_reason?: string };
export type Context = { clinic: Choice | null; doctor: Choice | null };
export type Occurrence = SavedRow & Context & { key: string; catalog: Choice | null; note: string };
export type Medication = SavedRow & { key: string; medication: Choice | null; note: string; display_order: number };
export type PrescriptionKind = "unlinked" | "dose_linked" | "outside";
export type MedicationPane = PrescriptionKind | "outcome";
export type Prescription = SavedRow & Context & { kind: PrescriptionKind; prescribed_on: string; note: string; funding_source_id: number | null; unavailable_reason: string; items: Medication[] };
export type Outcome = SavedRow & Context & { code: string; outcome_on: string; note: string; referral_target: string; outgoing_referral_date: string; outgoing_referral_reason: string };
export type ClinicalDraft = { services: Occurrence[]; procedures: Occurrence[]; prescriptions: Record<PrescriptionKind, Prescription | null>; outcome: Outcome | null };
export type EventData = Required<Pick<SavedRow,"id"|"lock_version">> & { performed_on: string; quantity: number; catalog_id: number; code: string; name_ar: string; clinic_id: number | null; doctor_id: number | null; clinic_name: string | null; doctor_name: string | null; note: string | null };
export type SavedPrescription = Required<Pick<SavedRow,"id"|"lock_version">> & { kind?: PrescriptionKind; prescribing_clinic_id: number; prescribing_staff_id: number; clinic_name: string; doctor_name: string; prescribed_on: string; note: string | null; funding_source_id?: number | null; funding_name?: string | null; unavailable_reason?: string | null; items: (Required<Pick<SavedRow,"id"|"lock_version">> & { medication_id: number; code: string; name_ar: string; note: string | null; display_order: number })[] };
export type ClinicalData = { services: EventData[]; procedures: EventData[]; prescription: SavedPrescription | null; prescriptions?: SavedPrescription[]; outcome: (Required<Pick<SavedRow,"id"|"lock_version">> & { clinic_id: number; doctor_id: number; clinic_name: string; doctor_name: string; name_ar: string; code: string; outcome_on: string; note: string | null; referral_target: string | null; outgoing_referral_date: string | null; outgoing_referral_reason: string | null }) | null; attachment_count: number | null };
const choice = (id: number | null, name: string | null): Choice | null => id ? { id, name_ar: name ?? "الاختيار المحفوظ" } : null;
export const prescriptionKinds: PrescriptionKind[] = ["unlinked", "dose_linked", "outside"];
export const prescriptionKindLabels: Record<PrescriptionKind, string> = {
  unlinked: "أدوية مصروفة من المشفى (غير مرتبطة بالجرعة)",
  dose_linked: "أدوية مرتبطة بالجرعة",
  outside: "أدوية خارج المشفى",
};
export const medicationPanes: { key: MedicationPane; label: string }[] = [
  { key: "unlinked", label: prescriptionKindLabels.unlinked },
  { key: "dose_linked", label: prescriptionKindLabels.dose_linked },
  { key: "outside", label: prescriptionKindLabels.outside },
  { key: "outcome", label: "النتيجة" },
];
function mapPrescription(p: SavedPrescription, fallback: PrescriptionKind): Prescription {
  const kind = p.kind && prescriptionKinds.includes(p.kind) ? p.kind : fallback;
  return {
    id: p.id, lock_version: p.lock_version, kind, clinic: choice(p.prescribing_clinic_id, p.clinic_name), doctor: choice(p.prescribing_staff_id, p.doctor_name),
    prescribed_on: p.prescribed_on, note: p.note ?? "", funding_source_id: p.funding_source_id ?? null, unavailable_reason: p.unavailable_reason ?? "",
    items: p.items.map(i => ({ key: String(i.id), id: i.id, lock_version: i.lock_version, medication: { id: i.medication_id, name_ar: i.name_ar, code: i.code }, note: i.note ?? "", display_order: i.display_order })),
  };
}
export function emptyPrescriptions(): Record<PrescriptionKind, Prescription | null> {
  return { unlinked: null, dose_linked: null, outside: null };
}
export function clinicalDraft(s?: Snapshot): ClinicalDraft {
  const d=s?.clinical;
  const occurrences=(rows: EventData[] = []): Occurrence[]=>rows.map(r=>({ key:String(r.id),id:r.id,lock_version:r.lock_version,catalog:{id:r.catalog_id,name_ar:r.name_ar,code:r.code},clinic:choice(r.clinic_id,r.clinic_name),doctor:choice(r.doctor_id,r.doctor_name),note:r.note??"" }));
  const prescriptions = emptyPrescriptions();
  const rows = d?.prescriptions?.length ? d.prescriptions : d?.prescription ? [d.prescription] : [];
  for (const row of rows) {
    const mapped = mapPrescription(row, "unlinked");
    prescriptions[mapped.kind] = mapped;
  }
  const o=d?.outcome;
  return { services:occurrences(d?.services),procedures:occurrences(d?.procedures),prescriptions,outcome:o?{id:o.id,lock_version:o.lock_version,clinic:choice(o.clinic_id,o.clinic_name),doctor:choice(o.doctor_id,o.doctor_name),code:o.code,outcome_on:o.outcome_on,note:o.note??"",referral_target:o.referral_target??"",outgoing_referral_date:o.outgoing_referral_date??"",outgoing_referral_reason:o.outgoing_referral_reason??""}:null };
}
const saved=(r:SavedRow)=>({...r.id?{id:r.id,lock_version:r.lock_version}:{},...r.remove?{remove:true,void_reason:r.void_reason}:{}});
function serializePrescription(p: Prescription) {
  return {
    ...saved(p), kind: p.kind, prescribing_clinic_id: p.clinic?.id, prescribing_staff_id: p.doctor?.id, prescribed_on: p.prescribed_on, note: p.note || null,
    ...(p.kind === "dose_linked" ? { funding_source_id: p.funding_source_id } : {}),
    ...(p.kind === "outside" ? { unavailable_reason: p.unavailable_reason || null } : {}),
    items: p.items.map(i => ({ ...saved(i), medication_id: i.medication?.id, note: i.note || null, display_order: i.display_order })),
  };
}
export function clinicalPayload(d: ClinicalDraft, section: number, pane: MedicationPane = "unlinked") {
  if (section === 3) return Object.fromEntries((["services","procedures"] as const).map(k => [k, d[k].map(r => ({ ...saved(r), catalog_id: r.catalog?.id, clinic_id: r.clinic?.id, doctor_id: r.doctor?.id, note: r.note || null }))]));
  const o = d.outcome;
  if (pane === "outcome") {
    return { prescription: null, outcome: o ? { ...saved(o), clinic_id: o.clinic?.id, doctor_id: o.doctor?.id, code: o.code, outcome_on: o.outcome_on, note: o.note || null, referral_target: o.code === "DOS-REFER" ? o.referral_target : null, outgoing_referral_date: o.code === "DOS-REFER" ? o.outgoing_referral_date : null, outgoing_referral_reason: o.code === "DOS-REFER" ? o.outgoing_referral_reason : null } : null };
  }
  return { prescription: d.prescriptions[pane] ? serializePrescription(d.prescriptions[pane]!) : null, outcome: null };
}
export const outcomeLabels: Record<string, string> = { "DOS-RX": "تخريج مع وصفة", "DOS-NORX": "تخريج بدون وصفة", "DOS-STUDY": "تخريج للدراسة", "DOS-REFER": "إحالة لمشفى آخر", "DOS-DEATH": "وفاة" };
