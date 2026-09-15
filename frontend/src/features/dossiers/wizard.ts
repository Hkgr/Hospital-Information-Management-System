import type { Choice } from "../blood-bank/api";

export type Fields = Record<string, string>;
export type DiagnosisDraft = { key: string; id?: number; lock_version?: number; diagnosis: Choice | null; clinic: Choice | null; doctor: Choice | null; diagnosed_on: string; remove?: boolean; void_reason?: string };
export type WorkflowActions = { sections?: boolean[]; subsequent_create?: boolean; personal_update: boolean; medical_update: boolean; resume_section: number | null; visit: { id: number | null; action: "create" | "update" | null; label: string | null } };
export type WizardOptions = { capabilities: Record<string, boolean>; creation: { allowed: boolean; reason: string | null }; today: string; governorates: Choice[]; visit_types: Choice[] };
export type Snapshot = {
  id: number; code: string; status: "draft" | "active"; opening_date: string; lock_version: number;
  workflow: WorkflowActions;
  clinical?: import("./clinical").ClinicalData;
  patient: { id: number; patient_code: string; lock_version: number } & Record<string, string | number | null>;
  medical: { is_oncology: boolean | number; history: string[]; treatment: string[] } & Record<string, unknown>;
  progress: { section: string; state: "not_started" | "in_progress" | "saved" | "needs_review"; last_saved_at: string | null; lock_version: number }[];
  visit: ({ id: number; status: string; lock_version: number; diagnoses: { id: number; lock_version: number; diagnosis_id: number; clinic_id: number | null; diagnosing_staff_id: number; diagnosis_name: string; clinic_name: string | null; doctor_name: string; diagnosed_on: string | null }[] } & Record<string, unknown>) | null;
};
export const personalLabels: Fields = { code: "كود المريض", opening_date: "تاريخ فتح الملف في هذا المشفى", first_name: "الاسم الأول", family_name: "اسم العائلة", father_name: "اسم الأب", mother_name: "اسم الأم", birth_date: "تاريخ الميلاد", birth_date_accuracy: "دقة الميلاد", gender: "الجنس", phone: "الهاتف", alt_phone: "هاتف بديل", governorate_id: "المحافظة", city_id: "المدينة", address_line: "عنوان السكن", displacement_status: "حالة النزوح" };
export const medicalLabels: Fields = { disability_text: "معلومات الإعاقة", clinical_history: "القصة المرضية المختصرة", is_oncology: "مريض ورمي", history: "أنواع السوابق", treatment: "أنواع العلاج السابق", previous_examinations: "الفحوص السابقة", medication_source: "مصدر الدواء", other_organization: "اسم الجهة الأخرى" };
export const visitLabels: Fields = { visit_date: "تاريخ الزيارة الفعلي", visit_type_id: "نوع الزيارة", is_referred: "محال من مشفى آخر", referring_hospital: "المشفى المحيل", referral_date: "تاريخ الإحالة", referral_reason: "سبب الإحالة" };
const strings = (labels: Fields, value: Record<string, unknown> = {}): Fields => Object.fromEntries(Object.keys(labels).map(k => [k, String(value[k] ?? "")]));
export function personalFields(s?: Snapshot): Fields { return { ...strings(personalLabels, s ? { ...s.patient, code: s.code, opening_date: s.opening_date } : {}), birth_date_accuracy: String(s?.patient.birth_date_accuracy ?? "unknown"), gender: String(s?.patient.gender ?? "unknown"), displacement_status: String(s?.patient.displacement_status ?? "unknown") }; }
export function medicalFields(s?: Snapshot): Fields { return { ...strings(medicalLabels, s?.medical), is_oncology: s ? (s.medical.is_oncology ? "yes" : "no") : "", history: JSON.stringify(s?.medical.history ?? []), treatment: JSON.stringify(s?.medical.treatment ?? []) }; }
export function visitFields(s?: Snapshot): Fields { return { ...strings(visitLabels, s?.visit ?? {}), is_referred: s?.visit?.is_referred ? "yes" : "no" }; }
export function diagnosisFields(s?: Snapshot): DiagnosisDraft[] {
  return s?.visit?.diagnoses.map(r => ({ key: String(r.id), id: r.id, lock_version: r.lock_version, diagnosis: { id: r.diagnosis_id, name_ar: r.diagnosis_name }, clinic: r.clinic_id ? { id: r.clinic_id, name_ar: r.clinic_name ?? "العيادة المحفوظة" } : null, doctor: { id: r.diagnosing_staff_id, name_ar: r.doctor_name }, diagnosed_on: r.diagnosed_on ?? "" })) ?? [];
}
export function diagnosisPayload(row: DiagnosisDraft) { return { ...(row.id ? { id: row.id, lock_version: row.lock_version } : {}), diagnosis_id: row.diagnosis?.id, clinic_id: row.clinic?.id, diagnosing_staff_id: row.doctor?.id, diagnosed_on: row.diagnosed_on || null, ...(row.remove ? { remove: true, void_reason: row.void_reason } : {}) }; }
