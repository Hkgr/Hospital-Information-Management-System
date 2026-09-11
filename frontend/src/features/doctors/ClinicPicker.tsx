"use client";

import { useState } from "react";
import { useDirectoryRequest, useDebounced, type ClinicLink, type Page } from "./api";
import { Pagination } from "../directory/Controls";
import styles from "../clinics/clinics.module.css";

export default function ClinicPicker({ facilityId, doctorId, changes, onChange }: { facilityId: number; doctorId?: number; changes: Record<number, boolean>; onChange: (clinic: ClinicLink, desired: boolean) => void }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const debounced = useDebounced(search);
  const query = new URLSearchParams({ facility_id: String(facilityId), search: debounced, page: String(page) });
  if (doctorId) query.set("doctor_id", String(doctorId));
  const result = useDirectoryRequest<Page<ClinicLink>>(`doctors/options/clinics?${query}`, true);
  return <fieldset className={styles.picker}><legend>عيادات المنشأة</legend><p className={styles.hint}>يمكن الحفظ دون عيادات. تُرسل اختياراتك الصريحة فقط؛ تبقى الارتباطات المخفية وارتباطات المنشآت الأخرى محفوظة.</p>
    <input type="search" aria-label="البحث لاختيار العيادات" placeholder="اسم العيادة أو كودها" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} />
    {result.error ? <p role="alert">{result.error} <button type="button" onClick={result.retry}>إعادة المحاولة</button></p> : !result.data || search !== debounced ? <p role="status">جارٍ البحث عن العيادات…</p> : <>
      <div className={styles.choices}>{result.data.data.map(clinic => <label key={clinic.id} className={styles.doctorChoice}><input type="checkbox" checked={changes[clinic.id] ?? clinic.is_linked} onChange={e => onChange(clinic, e.target.checked)} /><span><strong>{clinic.name_ar}</strong><small><bdi>{clinic.code}</bdi></small></span></label>)}</div>
      {!result.data.data.length && <p>لا توجد عيادات مطابقة.</p>}<Pagination meta={result.data.meta} onPage={setPage} />
    </>}</fieldset>;
}
