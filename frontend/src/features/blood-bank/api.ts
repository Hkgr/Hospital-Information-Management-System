export { useClinicRequest as useBloodRequest, useDebounced } from "../clinics/api";
export type { Page } from "../clinics/api";
export type Kind = "donor" | "recipient";
export type Choice = { id: number; name_ar: string; code?: string };
export type Screen = { analyte: string; screening_test_id: number | null; status: string; result: string | null };
export type Capabilities = { create: boolean; update: boolean; donations_create: boolean; donations_update: boolean; patients_search: boolean };
export type Row = { id: number; kind: Kind; code: string; name: string; blood_group: string | null; rh: string | null; clinic_name: string | null; doctor_name: string | null; component_name?: string | null; updated_at: string | null };
export type Profile = Row & { facility_id: number; person_mode: string; patient_id: number | null; patient_code: string | null; person: Record<string, string | number | null>; beneficiary_entity: string | null; clinic_id: number | null; responsible_staff_id: number | null; blood_component_id: number | null; screenings: Screen[]; lock_version: number };
export type Donation = { id: number; donor_id: number; donation_code: string; donated_on: string; blood_group: string; rh: string; units: string; status: string; lock_version: number; voided_at: string | null };
export type Options = { facility: Choice; capabilities: Capabilities; can_add_doctor: boolean; blood_components: Choice[]; screening_tests: (Choice & { blood_bank_analyte: string })[]; governorates: Choice[] };
export const kindName = (kind: Kind) => kind === "donor" ? "متبرع" : "مستفيد";
export const personLabels = { first_name: "الاسم الأول", family_name: "اسم العائلة", father_name: "اسم الأب", mother_name: "اسم الأم", birth_date: "تاريخ الميلاد", birth_date_accuracy: "دقة تاريخ الميلاد", gender: "الجنس", phone: "الهاتف", alt_phone: "هاتف بديل", governorate_id: "المحافظة", city_id: "المدينة", address_line: "عنوان السكن", displacement_status: "حالة النزوح" };
export const statuses: Record<string, string> = { not_requested: "لم يُطلب", requested: "مطلوب", pending: "بانتظار النتيجة", complete: "مكتمل", cancelled: "ملغى" };
export const results: Record<string, string> = { negative: "سلبي / غير تفاعلي", positive: "إيجابي / تفاعلي", indeterminate: "غير حاسم" };
export const choices: Record<string, Record<string, string>> = { gender: { unknown: "غير معروف", male: "ذكر", female: "أنثى" }, birth_date_accuracy: { unknown: "غير معروف", exact: "دقيق", year_only: "السنة فقط", estimated: "تقديري" }, displacement_status: { unknown: "غير معروف", resident: "مقيم", idp: "نازح" }, rh: { positive: "موجب (+)", negative: "سالب (−)" } };
export function bloodLabel(group: string | null, rh: string | null) { return `${group || "ABO غير معروف"} · ${rh ? choices.rh[rh] : "Rh غير معروف"}`; }
