"use client";

import { useState } from "react";
import { useClinicRequest, useDebounced, type Clinic, type Doctor, type Page } from "./api";
import Modal from "./Modal";
import styles from "./clinics.module.css";

export function DoctorList({ clinic }: { clinic: Clinic }) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const debounced = useDebounced(search);
  const result = useClinicRequest<Page<Doctor>>(`clinics/${clinic.id}/doctors?${new URLSearchParams({ facility_id: String(clinic.facility_id), search: debounced, page: String(page) })}`, true);
  return <div className={styles.doctorList}>
    <label className={styles.search}>بحث في أطباء العيادة<input type="search" value={search} placeholder="اسم الطبيب أو كوده" onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
    {result.error ? <p role="alert">{result.error} <button type="button" onClick={result.retry}>إعادة المحاولة</button></p>
      : !result.data || search !== debounced ? <p role="status">جارٍ تحميل الأطباء…</p>
      : <><div className={styles.tableScroll} tabIndex={0} role="region" aria-label="جدول أطباء العيادة"><table><thead><tr><th>كود الطبيب</th><th>الاسم</th><th>التخصص</th><th>بداية الارتباط</th></tr></thead><tbody>
        {result.data.data.map(doctor => <tr key={doctor.id}><td dir="auto">{doctor.code}</td><td>{doctor.name}</td><td>{doctor.specialties.map(s => s.name_ar).join("، ") || "غير محدد"}</td><td>{doctor.starts_on}</td></tr>)}
        {!result.data.data.length && <tr><td colSpan={4}>لا يوجد أطباء مطابقون مرتبطون حاليًا.</td></tr>}
      </tbody></table></div><div className={styles.pagination}><span>{result.data.meta.total} طبيب</span><button disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button><span>{page} / {result.data.meta.last_page}</span><button disabled={page >= result.data.meta.last_page} onClick={() => setPage(page + 1)}>التالي</button></div></>}
  </div>;
}

export default function ClinicDoctors({ clinic, onClose }: { clinic: Clinic; onClose: () => void }) {
  return <Modal title={`أطباء ${clinic.name_ar}`} onClose={onClose}><DoctorList clinic={clinic} /></Modal>;
}
