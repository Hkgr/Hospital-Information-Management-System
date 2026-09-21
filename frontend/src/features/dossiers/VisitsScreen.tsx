"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest, type Page } from "../clinics/api";
import useClinicSearch from "../clinics/useClinicSearch";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination } from "../directory/Controls";
import type { WizardOptions } from "./wizard";
import styles from "../clinics/clinics.module.css";

type Row = { id: number; dossier_id: number; visit_no: string; visit_date: string; status: string; patient_code: string; patient_name: string };
export default function VisitsScreen() {
  const { access, user } = useIdentity();
  const params = useSearchParams();
  const { entry } = directoryFacility(access, "dossiers.view", params.get("facility_id"));
  if (!entry) return <p role="alert">تعذّر تحديد مشفى مصرح لك باستعراض زياراته.</p>;
  return <Listing key={`${user.id}:${entry.facility.id}`} facility={entry.facility.id} name={entry.facility.name_ar} />;
}
function Listing({ facility, name }: { facility: number; name: string }) {
  const params = useSearchParams(), pathname = usePathname();
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facility);
  const q = new URLSearchParams({ facility_id: String(facility) });
  for (const key of ["status", "from", "to", "sort", "direction", "page", "per_page"]) { const v = params.get(key); if (v) q.set(key, v); }
  if (committed) q.set("search", committed);
  const list = useClinicRequest<Page<Row> & { totals: { visits: number } }>(`dossiers/visits?${q}`, true, true);
  const options = useClinicRequest<WizardOptions>(`dossiers/options?facility_id=${facility}`);
  function filter(key: string, value: string) { cancel(); const next = new URLSearchParams(q); if (value) next.set(key, value); else next.delete(key); if (key !== "page") next.delete("page"); window.history.replaceState(null, "", `${pathname}?${next}`); }
  return <div className={styles.screen}>
    <div className={styles.context}><span>المشفى</span><strong>{name}</strong></div>
    <header className={styles.heading}><div><h2>الزيارات</h2><p>زيارة جديدة لبطاقة موجودة، أو استكمال زيارة محفوظة.</p></div>{options.data?.capabilities.visits_create && <Link className={styles.primary} href={`/patient-cards/new?facility_id=${facility}&intent=visit&return_to=visits`}>تسجيل زيارة</Link>}</header>
    <section className={styles.panel}>
      <div className={styles.filters}>
        <label className={styles.search}>البحث في الزيارات<input type="search" value={search} onChange={e => change(e.target.value)} placeholder="اسم المريض أو كود البطاقة أو رقم الزيارة" /></label>
        <label>حالة الزيارة<select value={q.get("status") ?? "all"} onChange={e => filter("status", e.target.value)}><option value="all">الكل</option><option value="draft">مسودة</option><option value="complete">مكتملة</option></select></label>
        <label>من تاريخ<input type="date" value={q.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label>
        <label>إلى تاريخ<input type="date" value={q.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
        <label>الترتيب<select value={q.get("sort") ?? "visit_date"} onChange={e => filter("sort", e.target.value)}><option value="visit_date">تاريخ الزيارة</option><option value="visit_no">رقم الزيارة</option><option value="status">الحالة</option></select></label>
        <label>الاتجاه<select value={q.get("direction") ?? "desc"} onChange={e => filter("direction", e.target.value)}><option value="desc">الأحدث أولًا</option><option value="asc">الأقدم أولًا</option></select></label>
      </div>
      {(list.loading || searching) && <p role="status">{list.data ? "جارٍ تحديث الزيارات؛ تظهر النتائج السابقة مؤقتًا." : "جارٍ تحميل الزيارات…"}</p>}
      {list.error && <p role="alert">{list.error}<button className={styles.secondary} onClick={list.retry}>إعادة المحاولة</button></p>}
      {list.data && <><p>{list.data.totals.visits} زيارة مطابقة</p><DirectoryTable label="جدول الزيارات" headers={["المريض", "كود البطاقة", "تاريخ الزيارة", "الحالة", "الإجراءات"]} busy={list.loading || searching}>{list.data.data.map(row => <tr key={row.id}><td>{row.patient_name}</td><td><bdi>{row.patient_code}</bdi></td><td>{row.visit_date}</td><td><span className={row.status === "draft" ? styles.badge : styles.active}>{row.status === "draft" ? "مسودة" : "مكتملة"}</span></td><td><Link className={styles.secondary} href={`/patient-cards/new?facility_id=${facility}&card=${row.dossier_id}&visit=${row.id}&section=2&return_to=visits`}>{row.status === "draft" ? "استكمال الزيارة" : "فتح الزيارة"}</Link></td></tr>)}</DirectoryTable>{!list.data.data.length && <p>لا توجد زيارات مطابقة.</p>}<Pagination meta={list.data.meta} onPage={p => filter("page", String(p))} onPageSize={s => filter("per_page", s)} /></>}
    </section>
  </div>;
}
