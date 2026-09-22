"use client";

import { useState } from "react";
import { LuSearch } from "react-icons/lu";
import { Pagination } from "./Controls";
import { DirectoryTable } from "./DirectoryPrimitives";
import { useClinicRequest, useDebounced, type Page } from "../clinics/api";
import styles from "../clinics/clinics.module.css";

export type DirectoryPatient = { id: number; patient_code: string; patient_name: string; visit_count: number; last_visit_on: string | null };

export default function DirectoryPatients({ path, facilityId, definition, visitLabel = "عدد الزيارات", dateLabel = "آخر زيارة" }: { path: string; facilityId: number; definition: string; visitLabel?: string; dateLabel?: string }) {
  const [search, setSearch] = useState("");
  const committed = useDebounced(search);
  const [page, setPage] = useState("1");
  const [perPage, setPerPage] = useState("20");
  const query = new URLSearchParams({ facility_id: String(facilityId), search: committed, page, per_page: perPage });
  const result = useClinicRequest<Page<DirectoryPatient>>(`${path}?${query}`, true, true);
  return <div className={styles.doctorList}>
    <p className={styles.hint}>{definition}</p>
    <label className={styles.search}><span><LuSearch aria-hidden="true" />البحث عن مريض</span><input type="search" value={search} placeholder="اسم المريض أو كوده…" onChange={e => { setSearch(e.target.value); setPage("1"); }} /></label>
    {result.loading && <p className={styles.status} role="status">{result.data ? "جارٍ تحديث النتائج؛ المعروض نتائج سابقة مؤقتًا." : "جارٍ تحميل جدول المرضى…"}</p>}
    {result.error && <p role="alert" className={styles.error}>{result.error} {result.data && "المعروض نتائج سابقة."} <button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button></p>}
    {result.data && <>
      <div className={styles.resultSummary}><strong>{result.data.meta.total} مريض مطابق</strong><span>كامل النتائج المطابقة لاحتساب التقرير</span></div>
      <DirectoryTable label="جدول المرضى" busy={result.loading || search !== committed} headers={["كود المريض", "اسم المريض", visitLabel, dateLabel]}>
        {result.data.data.map(patient => <tr key={patient.id}><td><bdi>{patient.patient_code}</bdi></td><td><strong>{patient.patient_name}</strong></td><td>{patient.visit_count}</td><td><bdi>{patient.last_visit_on ?? "—"}</bdi></td></tr>)}
        {!result.data.data.length && <tr><td colSpan={4}><div className={styles.status}>{committed ? "لا توجد نتائج مطابقة للبحث." : "لا يوجد مرضى مطابقون لاحتساب هذا الجدول."}</div></td></tr>}
      </DirectoryTable>
      <Pagination meta={result.data.meta} onPage={value => setPage(String(value))} onPageSize={value => { setPerPage(value); setPage("1"); }} />
    </>}
    <p className={styles.hint}>تُعرض هوية المريض الأساسية فقط. ملف المريض غير متاح كرابط في النظام حاليًا.</p>
  </div>;
}
