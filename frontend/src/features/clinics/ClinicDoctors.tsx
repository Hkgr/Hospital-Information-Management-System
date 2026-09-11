"use client";

import { useState } from "react";
import Link from "next/link";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { useClinicRequest, useDebounced, type Clinic, type Doctor, type Page } from "./api";
import Modal from "./Modal";
import styles from "./clinics.module.css";

export function DoctorList({ clinic }: { clinic: Clinic }) {
  const { access } = useIdentity();
  const canView = access.some(entry => entry.facility.id === clinic.facility_id && entry.permissions.includes("doctors.view"));
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const debounced = useDebounced(search);
  const result = useClinicRequest<Page<Doctor>>(`clinics/${clinic.id}/doctors?${new URLSearchParams({ facility_id: String(clinic.facility_id), search: debounced, page: String(page) })}`, true, true);
  return <div className={styles.doctorList}>
    <p className={styles.hint}>الأطباء الفعالون من الأنواع المعتمدة، ذوو ارتباط سارٍ. لا تظهر الفترات المنتهية أو المستقبلية. {!clinic.is_active && "العيادة غير فعالة؛ عرض ارتباطاتها لا يعيد تفعيلها."}</p>
    <label className={styles.search}>بحث في أطباء العيادة<input type="search" value={search} placeholder="اسم الطبيب أو كوده" onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
    {result.error ? <p role="alert">{result.error} <button type="button" onClick={result.retry}>إعادة المحاولة</button></p>
      : !result.data ? <p role="status">جارٍ تحميل الأطباء…</p>
      : <>{(result.loading || search !== debounced) && <p role="status" className={styles.hint}>جارٍ تحديث النتائج…</p>}<div className={styles.tableScroll} tabIndex={0} role="region" aria-label="جدول أطباء العيادة" aria-busy={result.loading || search !== debounced}><table><thead><tr><th>كود الطبيب</th><th>الاسم</th><th>التخصص</th><th>بداية الارتباط</th></tr></thead><tbody>
        {result.data.data.map(doctor => <tr key={doctor.id}><td>{canView ? <Link prefetch={false} className={styles.code} href={`/doctors/${doctor.id}?facility_id=${clinic.facility_id}`}><bdi>{doctor.code}</bdi></Link> : <bdi>{doctor.code}</bdi>}</td><td>{doctor.name}</td><td>{doctor.specialties.map(s => s.name_ar).join("، ") || "غير محدد"}</td><td><bdi>{doctor.starts_on}</bdi></td></tr>)}
        {!result.data.data.length && <tr><td colSpan={4}>{debounced ? "لا توجد نتائج مطابقة في الارتباطات الحالية." : "لا يوجد أطباء فعالون مرتبطون حاليًا من الأنواع المعتمدة."}</td></tr>}
      </tbody></table></div><div className={styles.pagination}><span>{result.data.meta.total} طبيب</span><button disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button><span>{page} / {result.data.meta.last_page}</span><button disabled={page >= result.data.meta.last_page} onClick={() => setPage(page + 1)}>التالي</button></div></>}
  </div>;
}

export default function ClinicDoctors({ clinic, onClose }: { clinic: Clinic; onClose: () => void }) {
  return <Modal title={`أطباء ${clinic.name_ar}`} onClose={onClose}><DoctorList clinic={clinic} /></Modal>;
}
