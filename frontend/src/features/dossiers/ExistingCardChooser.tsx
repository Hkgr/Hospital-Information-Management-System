"use client";

import Link from "next/link";
import { useState } from "react";
import { useClinicRequest, useDebounced, type Page } from "../clinics/api";
import { Pagination } from "../directory/Controls";
import type { DossierRow } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

export default function ExistingCardChooser({ facility }: { facility: number }) {
  const [search, setSearch] = useState(""), [page, setPage] = useState(1);
  const committed = useDebounced(search);
  const result = useClinicRequest<Page<DossierRow>>(`dossiers?facility_id=${facility}&search=${encodeURIComponent(committed)}&page=${page}&per_page=10`, true);
  return <section className={layout.section}><h2>تسجيل زيارة لمريض موجود</h2><p>اختر بطاقة المريض. لن تُنشأ هوية أخرى، ولن تُحفظ زيارة بمجرد الاختيار.</p>
    <label>البحث عن بطاقة المريض<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="اسم المريض أو كود البطاقة" /></label>
    {result.loading || search !== committed ? <p role="status">جارٍ البحث…</p> : result.error ? <p role="alert">{result.error}<button type="button" onClick={result.retry}>إعادة المحاولة</button></p> : result.data && <><div className={layout.patientChoices}>{result.data.data.map(row => <div className={layout.summary} key={row.id}><div><strong>{row.patient_name}</strong><p><bdi>{row.code}</bdi> · {row.status === "draft" ? "بطاقة مسودة" : "بطاقة فعالة"}</p></div>{row.workflow.subsequent_create ? <Link className={styles.primary} href={`/patient-cards/new?facility_id=${facility}&card=${row.id}&visit=new&return_to=visits`}>تسجيل زيارة لهذا المريض</Link> : row.workflow.visit.action ? <Link className={styles.secondary} href={`/patient-cards/new?facility_id=${facility}&card=${row.id}&section=2&return_to=visits`}>استكمال الزيارة الأولى</Link> : <span>لا تتوفر صلاحية تسجيل أو استكمال زيارة لهذه البطاقة.</span>}</div>)}</div>{!result.data.data.length && <p>لا توجد بطاقة مطابقة ضمن هذا المشفى.</p>}<Pagination meta={result.data.meta} onPage={setPage} /></>}
    <Link className={styles.secondary} href={`/visits?facility_id=${facility}`}>العودة إلى الزيارات</Link>
  </section>;
}
