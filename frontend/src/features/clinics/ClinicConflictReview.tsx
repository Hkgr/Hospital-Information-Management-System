"use client";

import { apiRequest } from "@/features/auth/api";
import type { Clinic, Doctor } from "./api";
import ConflictReview, { readPages } from "../directory/ConflictReview";

export const fieldLabels = { code: "كود العيادة", name_ar: "اسم العيادة", description: "التوصيف", specialty_id: "التخصص", is_active: "الحالة" };
export function clinicFields(clinic?: Clinic) {
  return { code: clinic?.code ?? "", name_ar: clinic?.name_ar ?? "", description: clinic?.description ?? "", specialty_id: clinic?.specialty ? String(clinic.specialty.id) : "", is_active: clinic?.is_active ?? true };
}
type Fields = ReturnType<typeof clinicFields>;
export type ClinicSnapshot = { clinic: Clinic; doctors: Doctor[]; choices: Record<number, Doctor | undefined> };

export async function loadClinicSnapshot(clinicId: number, facilityId: number, changedDoctors: Record<number, Doctor>, signal: AbortSignal, onLoaded: () => void): Promise<ClinicSnapshot> {
  const path = `clinics/${clinicId}?facility_id=${facilityId}`;
  const clinic = await apiRequest<Clinic>(path, { signal });
  if (signal.aborted) throw new DOMException("Cancelled", "AbortError");
  onLoaded();
  const doctors = await readPages<Doctor>(`clinics/${clinicId}/doctors?facility_id=${facilityId}`, signal);
  const choices: ClinicSnapshot["choices"] = {};
  // Only re-check explicitly touched doctors, including ones no longer in the picker.
  // Missing/ineligible choices cannot safely be re-applied and remain unchanged.
  const touched = Object.values(changedDoctors);
  for (let start = 0; start < touched.length; start += 5) {
    await Promise.all(touched.slice(start, start + 5).map(async doctor => {
      const query = new URLSearchParams({ facility_id: String(facilityId), clinic_id: String(clinicId), search: doctor.code });
      choices[doctor.id] = (await readPages<Doctor>(`clinics/options/doctors?${query}`, signal, doctor.id)).find(row => row.id === doctor.id);
    }));
  }
  const verified = await apiRequest<Clinic>(path, { signal });
  if (signal.aborted) throw new DOMException("Cancelled", "AbortError");
  if (verified.lock_version !== clinic.lock_version) {
    onLoaded();
    throw new Error("تغيرت العيادة أثناء جلب البيانات. مسودتك محفوظة؛ اجلب أحدث نسخة مجددًا.");
  }
  return { clinic: verified, doctors, choices };
}

export default function ClinicConflictReview({ snapshot, original, draft, changes, changedDoctors, onAccept }: {
  snapshot: ClinicSnapshot; original: Clinic; draft: Fields; changes: Record<number, boolean>; changedDoctors: Record<number, Doctor>;
  onAccept: (fields: Fields, changes: Record<number, boolean>) => void;
}) {
  const latest = clinicFields(snapshot.clinic), initial = clinicFields(original);
  return <ConflictReview labels={fieldLabels} initial={initial} latest={latest} draft={draft}
    display={(key, value, source) => {
      const clinic = source === "latest" ? snapshot.clinic : original;
      return key === "is_active" ? (value ? "فعالة" : "غير فعالة") : key === "specialty_id" ? (clinic.specialty && String(clinic.specialty.id) === value ? clinic.specialty.name_ar : String(value || "دون تخصص")) : String(value || "—");
    }} currentLinks={snapshot.doctors} linkName={doctor => doctor.name} linkTitle="الأطباء" changes={changes} touched={changedDoctors} choices={snapshot.choices} onAccept={onAccept} />;
}
