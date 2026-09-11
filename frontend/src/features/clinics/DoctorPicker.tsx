"use client";

import { useState } from "react";
import { useClinicRequest, useDebounced, type Doctor, type Page } from "./api";
import styles from "./clinics.module.css";

export default function DoctorPicker({ facilityId, clinicId, changes, onChange }: { facilityId: number; clinicId?: number; changes: Record<number, boolean>; onChange: (id: number, selected: boolean, original: boolean, doctor: Doctor) => void }) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const debounced = useDebounced(search);
  const query = new URLSearchParams({ facility_id: String(facilityId), search: debounced, page: String(page) });
  if (clinicId) query.set("clinic_id", String(clinicId));
  const result = useClinicRequest<Page<Doctor>>(`clinics/options/doctors?${query}`, true);
  return <fieldset className={styles.picker}>
    <legend>الأطباء المرتبطون</legend>
    <p className={styles.hint}>اختر من الأطباء الموجودين. يبقى كل ارتباط لم تغيّره محفوظًا، حتى إن لم يظهر في البحث.</p>
    <input aria-label="البحث لاختيار الأطباء" type="search" placeholder="اسم الطبيب أو كوده" value={search} onChange={event => { setSearch(event.target.value); setPage(1); }} />
    {result.error ? <p role="alert">{result.error} <button type="button" onClick={result.retry}>إعادة المحاولة</button></p>
      : !result.data || search !== debounced ? <p role="status">جارٍ البحث عن الأطباء…</p>
      : <>
        {result.data.doctor_types_configured === false && <p role="status">اختيار الأطباء غير متاح حتى ضبط أنواع الأطباء المعتمدة من مسؤول النظام.</p>}
        {!result.data.data.length && <p className={styles.hint}>لا يوجد أطباء مطابقون.</p>}
        <div className={styles.choices}>{result.data.data.map(doctor => <label key={doctor.id} className={styles.doctorChoice}>
          <input type="checkbox" checked={changes[doctor.id] ?? doctor.is_linked} onChange={event => onChange(doctor.id, event.target.checked, doctor.is_linked, doctor)} />
          <span><strong>{doctor.name}</strong><small>{doctor.code} · {doctor.specialties.map(s => s.name_ar).join("، ") || "دون تخصص مسجل"}</small></span>
        </label>)}</div>
        <div className={styles.pagination}><button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button><span>{page} / {result.data.meta.last_page}</span><button type="button" disabled={page >= result.data.meta.last_page} onClick={() => setPage(page + 1)}>التالي</button></div>
      </>}
  </fieldset>;
}
