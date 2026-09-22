"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest, type Page } from "../clinics/api";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { LongText, Pagination } from "../directory/Controls";
import { LuHospital } from "react-icons/lu";
import styles from "../clinics/clinics.module.css";

type AuditEvent = {
  id: number; occurred_at: string; actor: { id: number | null; name: string };
  category: string; category_label: string; entity: string; entity_label: string;
  action: string; action_label: string; reason: string | null;
  changes: { field: string; label: string; before: string | null; after: string; before_recorded: boolean }[];
};
type History = Page<AuditEvent> & { filters: { categories: Record<string, string>; entities: Record<string, string>; actions: Record<string, string> }; timezone: string };

const categories = { patient_card: "بطاقة المريض", treatment: "العلاج والأدوية", directory: "الدليل", stock: "المخزون", blood_bank: "بنك الدم", accounts: "الحسابات", technical: "خطأ تقني", other: "أخرى" };
const actions = { created: "إنشاء", updated: "تعديل", login: "تسجيل دخول", logout: "تسجيل خروج", failed: "خطأ تقني", voided: "إلغاء" };

export default function AuditLogScreen() {
  const { access } = useIdentity();
  const params = useSearchParams();
  const pathname = usePathname();
  const { entry, allowed } = directoryFacility(access, null, params.get("facility_id"));
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
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>
    {allowed.length > 1 && <label className={styles.filters}>المنشأة<select aria-label="المنشأة" value={String(facility)} onChange={e => filter("facility_id", e.target.value)}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select></label>}
    <section className={styles.panel} aria-labelledby="system-log-heading">
      <div className={styles.toolbar}><div><h2 id="system-log-heading">سجل الحركة</h2><p className={styles.hint}>كل إنشاء وتعديل وإلغاء وتسجيل دخول أو خروج، مع الأخطاء التقنية. الوقت بتوقيت المنشأة ومع اسم المستخدم.</p></div></div>
      <div className={styles.filters}>
        <label>من تاريخ<input type="date" value={q.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label>
        <label>إلى تاريخ<input type="date" value={q.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
        {([["category", "التصنيف", history.data?.filters.categories ?? categories], ["entity", "النوع", history.data?.filters.entities ?? {}], ["action", "الإجراء", history.data?.filters.actions ?? actions]] as const).map(([key, label, choices]) => <label key={key}>{label}<select aria-label={label} value={q.get(key) ?? ""} onChange={e => filter(key, e.target.value)}><option value="">الكل</option>{Object.entries(choices).map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>)}
      </div>
      {history.loading && <p role="status" className={styles.status}>جارٍ تحميل سجل الحركة…</p>}
      {history.error && <div className={styles.status}><p role="alert">{history.error}</p><button className={styles.secondary} onClick={history.retry}>إعادة تحميل السجل</button></div>}
      {history.data && <><p className={styles.resultSummary}>{history.data.meta.total} حركة مطابقة · التوقيت: <bdi>{history.data.timezone}</bdi></p>
        {!history.data.data.length ? <p className={styles.status}>لا توجد حركات مسجلة تطابق هذه الفلاتر.</p> : history.data.data.map(event => <article key={event.id} className={styles.detailPanel} aria-label={`${event.category_label} — ${event.action_label}`}>
          <div className={styles.toolbar}><h3>{event.entity_label} <span className={event.category === "technical" || event.action === "failed" || event.action === "voided" ? styles.badge : styles.active}>{event.category_label} · {event.action_label}</span></h3><time dateTime={event.occurred_at}>{new Intl.DateTimeFormat("ar-SY", { dateStyle: "medium", timeStyle: "short", timeZone: history.data!.timezone }).format(new Date(event.occurred_at))}</time></div>
          <p>بواسطة {event.actor.name}</p>
          {!!event.changes.length ? <DirectoryTable label={`تفاصيل الحركة ${event.id}`} headers={["الحقل", "القيمة السابقة", "القيمة الجديدة"]}>{event.changes.map(change => <tr key={change.field}><th scope="row">{change.label}</th><td>{change.before_recorded ? <LongText text={change.before} /> : "لم تُحفظ قيمة سابقة"}</td><td><LongText text={change.after} /></td></tr>)}</DirectoryTable> : <p className={styles.hint}>الإجراء مسجل دون قيم تفصيلية متاحة للعرض.</p>}
          {event.reason && <p><strong>السبب المسجل: </strong>{event.reason}</p>}
        </article>)}
        <Pagination meta={history.data.meta} onPage={page => filter("page", String(page))} onPageSize={size => filter("per_page", size)} />
      </>}
    </section>
  </div>;
}
