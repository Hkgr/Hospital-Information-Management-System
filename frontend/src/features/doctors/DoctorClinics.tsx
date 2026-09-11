"use client";

import Link from "next/link";
import { useState } from "react";
import { useDirectoryRequest, useDebounced, type ClinicLink, type Doctor, type Page } from "./api";
import Modal from "../clinics/Modal";
import { Pagination } from "../directory/Controls";
import styles from "../clinics/clinics.module.css";

export function ClinicList({ doctor, facilityId }: { doctor: Doctor; facilityId: number }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const debounced = useDebounced(search);
  const query = new URLSearchParams({ facility_id: String(facilityId), search: debounced, page: String(page) });
  const result = useDirectoryRequest<Page<ClinicLink>>(`doctors/${doctor.id}/clinics?${query}`, true, true);
  return <div className={styles.doctorList}><p className={styles.hint}>العيادات الفعالة ذات الارتباط الساري في المنشأة الحالية؛ ينتهي الارتباط عند بداية يوم نهايته.</p><label className={styles.search}>البحث في عيادات الطبيب<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label>
    {result.error && <p role="alert">{result.error} <button onClick={result.retry}>إعادة المحاولة</button></p>}
    {!result.data ? !result.error && <p role="status">جارٍ تحميل العيادات…</p> : <>
      {(result.loading || search !== debounced) && <p role="status">جارٍ تحديث العيادات…</p>}
      <strong>{result.data.meta.total} عيادة مطابقة</strong><div className={styles.tableScroll} tabIndex={0} role="region" aria-label="عيادات الطبيب"><table><thead><tr><th>كود العيادة</th><th>الاسم</th><th>بداية الارتباط</th></tr></thead><tbody>{result.data.data.map(c => <tr key={c.id}><td>{c.can_view ? <Link href={`/clinics/${c.id}?facility_id=${facilityId}`} className={styles.code}><bdi>{c.code}</bdi></Link> : <bdi>{c.code}</bdi>}</td><td>{c.name_ar}</td><td><bdi>{c.starts_on}</bdi></td></tr>)}{!result.data.data.length && <tr><td colSpan={3}>لا توجد عيادات مطابقة.</td></tr>}</tbody></table></div><Pagination meta={result.data.meta} onPage={setPage} />
    </>}</div>;
}

export default function DoctorClinics({ doctor, facilityId, onClose }: { doctor: Doctor; facilityId: number; onClose: () => void }) {
  return <Modal title={`عيادات ${doctor.name}`} onClose={onClose}><ClinicList doctor={doctor} facilityId={facilityId} /></Modal>;
}
