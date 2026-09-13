"use client";

import { useState } from "react";
import { LuSearch } from "react-icons/lu";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination } from "../directory/Controls";
import { type Item, type Page, itemPath, useCatalogRequest, useDebounced } from "./api";
import styles from "../clinics/clinics.module.css";

type Event = { key: string; patient_code: string; patient_name: string; performed_on: string | null; visit_no: string | null };
export default function CatalogEvents({ item, facilityId, revision }: { item: Item; facilityId: number; revision: number }) {
  const [search, setSearch] = useState(""); const committed = useDebounced(search);
  const [filters, setFilters] = useState({ from: "", to: "", sort: "performed_on", direction: "desc", page: "1", per_page: "20" });
  const query = new URLSearchParams({ facility_id: String(facilityId), search: committed });
  Object.entries(filters).forEach(([key, value]) => { if (value) query.set(key, value); });
  const result = useCatalogRequest<Page<Event> & { totals: { unique_patients: number; presentations: number } }>(`${itemPath(item)}/events?${query}`, true, true, revision);
  const change = (key: keyof typeof filters, value: string) => setFilters(previous => ({ ...previous, [key]: value, page: key === "page" ? value : "1" }));
  return <section className={styles.panel} aria-label="المرضى المستفيدون وتواريخ التقديم"><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث عن مستفيد</span><input type="search" value={search} onChange={e => { setSearch(e.target.value); change("page", "1"); }} placeholder="اسم المريض أو كوده…" /></label></div>
    <div className={styles.filters}><label>من تاريخ<input type="date" value={filters.from} onChange={e => change("from", e.target.value)} /></label><label>إلى تاريخ<input type="date" value={filters.to} onChange={e => change("to", e.target.value)} /></label><label>ترتيب التقديم<select value={filters.sort} onChange={e => change("sort", e.target.value)}><option value="performed_on">تاريخ التقديم</option><option value="patient_code">كود المريض</option><option value="patient_name">اسم المريض</option><option value="visit_no">رقم الزيارة</option></select></label><label>اتجاه التقديم<select value={filters.direction} onChange={e => change("direction", e.target.value)}><option value="desc">تنازلي</option><option value="asc">تصاعدي</option></select></label></div>
    {result.loading && <p className={styles.status} role="status">{result.data ? "جارٍ تحديث النتائج؛ المعروض نتائج سابقة مؤقتًا." : "جارٍ تحميل وقائع التقديم…"}</p>}
    {result.error && <p role="alert" className={styles.error}>{result.error} {result.data && "المعروض نتائج سابقة."} <button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button></p>}
    {result.data && <><div className={styles.resultSummary}><strong>عدد المرضى الفريدين: {result.data.totals.unique_patients}</strong><span>عدد مرات التقديم: {result.data.totals.presentations} · كامل النتائج المطابقة</span></div>
      <DirectoryTable label="جدول المرضى المستفيدين وتواريخ التقديم" busy={result.loading || search !== committed} headers={["كود المريض", "اسم المريض", "تاريخ التقديم", "رقم الزيارة"]}>{result.data.data.map(event => <tr key={event.key}><td><bdi>{event.patient_code}</bdi></td><td><strong>{event.patient_name}</strong></td><td><bdi>{event.performed_on ?? "تاريخ التقديم غير متوفر"}</bdi></td><td><bdi>{event.visit_no ?? "غير مرتبط بزيارة"}</bdi></td></tr>)}{!result.data.data.length && <tr><td colSpan={4}><div className={styles.status}>لا توجد وقائع تقديم مطابقة للفلاتر.</div></td></tr>}</DirectoryTable>
      <Pagination meta={result.data.meta} onPage={page => change("page", String(page))} onPageSize={value => change("per_page", value)} />
    </>}
    <p className={styles.hint}>تاريخ التقديم من الحدث نفسه. ملف المريض غير متاح كرابط في النظام حاليًا.</p>
  </section>;
}
