"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest, type Page } from "../clinics/api";
import { DirectoryRowActions, DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination } from "../directory/Controls";
import { LuHospital } from "react-icons/lu";
import { actions, categories, formatAuditTime, type AuditEvent } from "./audit";
import styles from "../clinics/clinics.module.css";
import audit from "./audit.module.css";

type History = Page<AuditEvent> & { filters: { categories: Record<string, string>; entities: Record<string, string>; actions: Record<string, string> }; timezone: string };

function Kind({ kind, label }: { kind: string; label: string }) {
  return <span className={audit.kind} data-kind={kind}>{label}</span>;
}

export default function AuditLogScreen() {
  const { access } = useIdentity();
  const params = useSearchParams();
  const pathname = usePathname();
  const router = useRouter();
  const { entry, allowed } = directoryFacility(access, "audit.view", params.get("facility_id"));
  if (!entry) return <section className={styles.status}><h2>السجل غير متاح</h2><p role="alert">{allowed.length ? "معرّف المنشأة غير صالح ضمن المنشآت المتاحة لك." : "لا منشأة مرتبطة بهذا الدخول لعرض سجل الحركة."}</p></section>;
  const facility = entry.facility.id;
  const q = new URLSearchParams({ facility_id: String(facility) });
  for (const key of ["from", "to", "category", "entity", "action", "page", "per_page"]) {
    const value = params.get(key); if (value) q.set(key, value);
  }
  const history = useClinicRequest<History>(`audit?${q}`, true);
  function filter(key: string, value: string) {
    const next = new URLSearchParams(params.toString());
    next.set("facility_id", String(facility));
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    window.history.replaceState(null, "", `${pathname}?${next}`);
  }
  const open = (id: number) => router.push(`/audit/${id}?facility_id=${facility}`);
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>
    {allowed.length > 1 && <label className={styles.filters}>المنشأة<select aria-label="المنشأة" value={String(facility)} onChange={e => filter("facility_id", e.target.value)}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select></label>}
    <section className={styles.panel} aria-labelledby="system-log-heading">
      <div className={styles.toolbar}><div><h2 id="system-log-heading">سجل الحركة</h2><p className={styles.hint}>جدول الحركات المسجلة. انقر الصف لفتح صفحة الحركة.</p></div></div>
      <div className={styles.filters}>
        <label>من تاريخ<input type="date" value={q.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label>
        <label>إلى تاريخ<input type="date" value={q.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
        {([["category", "التصنيف", history.data?.filters.categories ?? categories], ["entity", "النوع", history.data?.filters.entities ?? {}], ["action", "الإجراء", history.data?.filters.actions ?? actions]] as const).map(([key, label, choices]) => <label key={key}>{label}<select aria-label={label} value={q.get(key) ?? ""} onChange={e => filter(key, e.target.value)}><option value="">الكل</option>{Object.entries(choices).map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>)}
      </div>
      {history.loading && <p role="status" className={styles.status}>جارٍ تحميل سجل الحركة…</p>}
      {history.error && <div className={styles.status}><p role="alert">{history.error}</p><button className={styles.secondary} onClick={history.retry}>إعادة تحميل السجل</button></div>}
      {history.data && <><p className={styles.resultSummary}>{history.data.meta.total} حركة مطابقة · التوقيت: <bdi>{history.data.timezone}</bdi></p>
        <DirectoryTable label="جدول سجل الحركة" busy={history.loading} headers={["الوقت", "المستخدم", "النوع", "الإجراء", "التصنيف", ""]}>
          {history.data.data.map(event => {
            const href = `/audit/${event.id}?facility_id=${facility}`;
            return <tr key={event.id} className={audit.clickable} tabIndex={0} onClick={() => open(event.id)} onKeyDown={e => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); open(event.id); } }}>
              <td className={styles.identifierCell}><Link className={styles.code} href={href} onClick={e => e.stopPropagation()}><time dateTime={event.occurred_at}>{formatAuditTime(event.occurred_at, history.data!.timezone)}</time></Link></td>
              <td>{event.actor.name}</td>
              <td><Kind kind={event.entity} label={event.entity_label} /></td>
              <td><Kind kind={event.action} label={event.action_label} /></td>
              <td><Kind kind={event.category} label={event.category_label} /></td>
              <td onClick={e => e.stopPropagation()}><DirectoryRowActions name={`الحركة ${event.id}`} href={href} /></td>
            </tr>;
          })}
          {!history.data.data.length && <tr><td colSpan={6}><div className={styles.status}>لا توجد حركات مسجلة تطابق هذه الفلاتر.</div></td></tr>}
        </DirectoryTable>
        <Pagination meta={history.data.meta} onPage={page => filter("page", String(page))} onPageSize={size => filter("per_page", size)} />
      </>}
    </section>
  </div>;
}
