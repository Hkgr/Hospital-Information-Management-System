"use client";

import { apiRequest } from "@/features/auth/api";
import ConflictReview, { readPages, readChoices, type ChoiceSnapshot } from "../directory/ConflictReview";
import type { ClinicLink, Doctor, Options } from "./api";

export const fieldLabels = { code: "كود الطبيب", name: "الاسم الكامل", description: "التوصيف المهني", staff_type_id: "نوع الطبيب", specialty_ids: "التخصصات", license_no: "رقم الترخيص", phone: "الهاتف", is_active: "الحالة" };
export function doctorFields(doctor?: Doctor) {
  return { code: doctor?.code ?? "", name: doctor?.name ?? "", description: doctor?.description ?? "", staff_type_id: doctor?.staff_type.id ? String(doctor.staff_type.id) : "", specialty_ids: doctor?.specialties.map(s => s.id).sort((a,b) => a-b) ?? [], license_no: doctor?.license_no ?? "", phone: doctor?.phone ?? "", is_active: doctor?.is_active ?? true };
}
export type DoctorSnapshot = { doctor: Doctor; clinics: ClinicLink[] } & ChoiceSnapshot<ClinicLink>;
export async function loadDoctorSnapshot(id: number, facilityId: number, touched: Record<number, ClinicLink>, signal: AbortSignal, onLoaded: () => void): Promise<DoctorSnapshot> {
  const path = `doctors/${id}?facility_id=${facilityId}`;
  const doctor = await apiRequest<Doctor>(path, { signal });
  if (signal.aborted) throw new DOMException("Cancelled", "AbortError");
  onLoaded();
  const clinics = await readPages<ClinicLink>(`doctors/${id}/clinics?facility_id=${facilityId}`, signal);
  const lookup = await readChoices<ClinicLink>(`doctors/options/clinics?facility_id=${facilityId}&doctor_id=${id}`, Object.values(touched).map(item => item.id), signal);
  const verified = await apiRequest<Doctor>(path, { signal });
  if (signal.aborted) throw new DOMException("Cancelled", "AbortError");
  if (verified.lock_version !== doctor.lock_version) { onLoaded(); throw new Error("تغير الطبيب أثناء جلب البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة مجددًا."); }
  return { doctor: verified, clinics, ...lookup };
}

export default function DoctorConflict({ snapshot, original, draft, changes, touched, options, linksOnly, onAccept }: { snapshot: DoctorSnapshot; original: Doctor; draft: ReturnType<typeof doctorFields>; changes: Record<number, boolean>; touched: Record<number, ClinicLink>; options: Options; linksOnly: boolean; onAccept: (fields: ReturnType<typeof doctorFields>, changes: Record<number, boolean>) => void }) {
  const specialties = [...options.specialties, ...original.specialties, ...snapshot.doctor.specialties];
  const types = [...options.staff_types, original.staff_type, snapshot.doctor.staff_type];
  return <ConflictReview labels={linksOnly ? {} as typeof fieldLabels : fieldLabels} initial={doctorFields(original)} latest={doctorFields(snapshot.doctor)} draft={draft}
    display={(key, value) => key === "is_active" ? (value ? "فعال" : "غير فعال") : key === "specialty_ids" ? (value as number[]).map(id => specialties.find(s => s.id === id)?.name_ar ?? "غير متاح").join("، ") || "دون تخصص" : key === "staff_type_id" ? types.find(t => String(t.id) === value)?.name_ar ?? "غير محدد" : String(value || "—")}
    currentLinks={snapshot.clinics} linkName={item => item.name_ar} linkTitle="العيادات" changes={changes} touched={touched} choices={snapshot.choices} unavailable={snapshot.unavailable} onAccept={onAccept} />;
}
