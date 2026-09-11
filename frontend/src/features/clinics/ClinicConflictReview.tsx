"use client";

import { useState } from "react";
import { apiRequest } from "@/features/auth/api";
import type { Clinic, Doctor, Page } from "./api";
import styles from "./clinics.module.css";

export const fieldLabels = { code: "كود العيادة", name_ar: "اسم العيادة", description: "التوصيف", specialty_id: "التخصص", is_active: "الحالة" };
export function clinicFields(clinic?: Clinic) {
  return { code: clinic?.code ?? "", name_ar: clinic?.name_ar ?? "", description: clinic?.description ?? "", specialty_id: clinic?.specialty ? String(clinic.specialty.id) : "", is_active: clinic?.is_active ?? true };
}
type Fields = ReturnType<typeof clinicFields>;
type Field = keyof Fields;
export type ClinicSnapshot = { clinic: Clinic; doctors: Doctor[]; choices: Record<number, Doctor | undefined> };

// Read every page needed for the review; never infer all associations from a preview
// or replace clinic_staff with the currently visible doctor search results.
async function readPages(path: string, signal: AbortSignal, findId?: number): Promise<Doctor[]> {
  const rows: Doctor[] = [];
  for (let page = 1; ; page++) {
    const result = await apiRequest<Page<Doctor>>(`${path}&per_page=100&page=${page}`, { signal }, "envelope");
    rows.push(...result.data);
    if (page >= result.meta.last_page || (findId !== undefined && rows.some(row => row.id === findId))) return rows;
  }
}

export async function loadClinicSnapshot(clinicId: number, facilityId: number, changedDoctors: Record<number, Doctor>, signal: AbortSignal, onLoaded: () => void): Promise<ClinicSnapshot> {
  const path = `clinics/${clinicId}?facility_id=${facilityId}`;
  const clinic = await apiRequest<Clinic>(path, { signal });
  if (signal.aborted) throw new DOMException("Cancelled", "AbortError");
  onLoaded();
  const doctors = await readPages(`clinics/${clinicId}/doctors?facility_id=${facilityId}`, signal);
  const choices: ClinicSnapshot["choices"] = {};
  // Only re-check explicitly touched doctors, including ones no longer in the picker.
  // Missing/ineligible choices cannot safely be re-applied and remain unchanged.
  const touched = Object.values(changedDoctors);
  for (let start = 0; start < touched.length; start += 5) {
    await Promise.all(touched.slice(start, start + 5).map(async doctor => {
      const query = new URLSearchParams({ facility_id: String(facilityId), clinic_id: String(clinicId), search: doctor.code });
      choices[doctor.id] = (await readPages(`clinics/options/doctors?${query}`, signal, doctor.id)).find(row => row.id === doctor.id);
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
  const [selected, setSelected] = useState<Partial<Record<Field, boolean>>>({});
  const [selectedDoctors, setSelectedDoctors] = useState<Record<number, boolean>>({});
  const latest = clinicFields(snapshot.clinic), initial = clinicFields(original);
  const display = (key: Field, value: Fields[Field], clinic?: Clinic) => key === "is_active" ? (value ? "فعالة" : "غير فعالة")
    : key === "specialty_id" ? (clinic?.specialty && String(clinic.specialty.id) === value ? clinic.specialty.name_ar : value || "دون تخصص") : String(value || "—");
  function accept() {
    const fields = { ...latest };
    for (const key of Object.keys(fieldLabels) as Field[]) {
      if (selected[key]) Object.assign(fields, { [key]: draft[key] });
    }
    const rebased: Record<number, boolean> = {};
    for (const [id, desired] of Object.entries(changes)) {
      const current = snapshot.choices[Number(id)];
      if (selectedDoctors[Number(id)] && current && desired !== current.is_linked) rebased[Number(id)] = desired;
    }
    onAccept(fields, rebased);
  }
  return <section className={styles.conflictReview} aria-label="مراجعة تعارض التعديل">
    <h3>مراجعة أحدث نسخة مع مسودتك</h3>
    <p className={styles.hint}>تُستخدم أحدث البيانات افتراضيًا. اختر فقط ما تريد تطبيقه من مسودتك، ثم راجع النموذج قبل الضغط على حفظ. لا تُحفظ أي تغييرات تلقائيًا.</p>
    {(Object.keys(fieldLabels) as Field[]).map(key => <div className={styles.reviewField} key={key}>
      <strong>{fieldLabels[key]}</strong>
      <dl><div><dt>عند بدء التعديل</dt><dd>{display(key, initial[key], original)}</dd></div><div><dt>أحدث نسخة</dt><dd>{display(key, latest[key], snapshot.clinic)}</dd></div><div><dt>مسودتك</dt><dd>{display(key, draft[key], original)}</dd></div></dl>
      {draft[key] !== latest[key] && <label><input type="checkbox" checked={!!selected[key]} onChange={e => setSelected({ ...selected, [key]: e.target.checked })} />تطبيق مسودتي: {fieldLabels[key]}</label>}
    </div>)}
    <div className={styles.reviewField}><strong>الأطباء المرتبطون حاليًا</strong><ul className={styles.choices}>{snapshot.doctors.map(doctor => <li key={doctor.id}>{doctor.name} · {doctor.code}</li>)}</ul>{!snapshot.doctors.length && <p>لا يوجد أطباء حاليون.</p>}</div>
    {Object.entries(changes).map(([key, desired]) => {
      const id = Number(key), current = snapshot.choices[id], doctor = changedDoctors[id];
      return <div key={id} className={styles.reviewField}>
        <strong>{doctor.name} · {doctor.code}</strong>
        <p>أحدث نسخة: {current ? (current.is_linked ? "مرتبط" : "غير مرتبط") : "غير متاح للاختيار"} · مسودتك: {desired ? "إضافة الارتباط" : "إزالة الارتباط"}</p>
        {current && current.is_linked !== desired ? <label><input type="checkbox" checked={!!selectedDoctors[id]} onChange={e => setSelectedDoctors({ ...selectedDoctors, [id]: e.target.checked })} />تطبيق اختياري للطبيب: {doctor.name}</label>
          : <p className={styles.hint}>{current ? "اختيارك يطابق الحالة الحالية؛ لا يلزم إرسال تغيير." : "لن يُرسل تغيير لهذا الطبيب. تبقى ارتباطاته وتاريخه كما هي."}</p>}
      </div>;
    })}
    <button type="button" className={styles.secondary} onClick={accept}>اعتماد الاختيارات للمراجعة</button>
  </section>;
}
