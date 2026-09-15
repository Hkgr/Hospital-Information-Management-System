"use client";

import { usePathname, useSearchParams } from "next/navigation";
import { useClinicRequest, type Page } from "../clinics/api";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { LongText, Pagination } from "../directory/Controls";
import styles from "../clinics/clinics.module.css";

type AuditEvent = {
  id: number; occurred_at: string; actor: { id: number | null; name: string };
  visit: { id: number; visit_no: string; visit_date: string } | null;
  entity: string; entity_label: string; action: string; action_label: string; reason: string | null;
  changes: { field: string; label: string; before: string | null; after: string; before_recorded: boolean }[];
};
type History = Page<AuditEvent> & { filters: { entities: Record<string, string>; actions: Record<string, string> }; timezone: string };
// Static contract labels keep filters usable during loading/error without retaining previous entries.
const entities = { patient_dossier: "بطاقة المريض", patient: "بيانات الشخص", dossier_medical: "المعلومات الطبية والورمية", dossier_visit: "الزيارة", visit_diagnosis: "التشخيص", visit_services: "الخدمة", visit_procedures: "الإجراء", visit_prescriptions: "الوصفة", visit_prescription_items: "بند الوصفة", visit_outcomes: "النتيجة والإحالة" };
const actions = { created: "إنشاء", updated: "تعديل", activated: "تفعيل", completed: "إكمال", reviewed: "مراجعة", voided: "إلغاء", started: "بدء رفع", uploaded: "رفع", cancelled: "إلغاء رفع" };

export default function DossierAuditHistory({ dossier, facility, attachments }: { dossier: string; facility: number; attachments: boolean }) {
  const params = useSearchParams(), pathname = usePathname();
  const q = new URLSearchParams({ facility_id: String(facility) });
  for (const key of ["from", "to", "entity", "action", "visit_id", "page", "per_page"]) {
    const value = params.get(`audit_${key}`); if (value) q.set(key, value);
  }
  // The shared hook keys every result by path, token, session, and retry; stale pages never render.
  const history = useClinicRequest<History>(`dossiers/${dossier}/audit?${q}`, true);
  const entityChoices = history.data?.filters.entities ?? { ...entities, ...(attachments ? { dossier_upload: "رفع المرفق", visit_attachment: "بيانات المرفق" } : {}) };
  function filter(key: string, value: string) {
    const next = new URLSearchParams(params.toString());
    next.set("facility_id", String(facility));
    if (value) next.set(`audit_${key}`, value); else next.delete(`audit_${key}`);
    if (key !== "page") next.delete("audit_page");
    window.history.replaceState(null, "", `${pathname}?${next}`);
  }
  return <section id="dossier-change-history" className={styles.panel} style={{ scrollMarginBlockStart: "6rem" }} aria-labelledby="audit-heading">
    <div className={styles.toolbar}><div><h2 id="audit-heading">سجل التغييرات</h2><p className={styles.hint}>التغييرات المسجلة، من الأحدث إلى الأقدم. القيم السابقة تظهر حين تكون محفوظة؛ أسماء الأدلة الحالية موضّحة صراحة.</p></div></div>
    <div className={styles.filters}>
      <label>تاريخ التغيير من<input type="date" value={q.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label>
      <label>تاريخ التغيير إلى<input type="date" value={q.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
      {([ ["entity", "القسم", entityChoices], ["action", "نوع التغيير", history.data?.filters.actions ?? actions] ] as const).map(([key, label, choices]) => <label key={key}>{label}<select aria-label={label} value={q.get(key) ?? ""} onChange={e => filter(key, e.target.value)}><option value="">الكل</option>{Object.entries(choices).map(([value, text]) => <option key={value} value={value}>{text}</option>)}</select></label>)}
    </div>
    {q.has("visit_id") ? <p className={styles.hint}>المعروض تغييرات الزيارة المختارة فقط. <button className={styles.secondary} onClick={() => filter("visit_id", "")}>عرض تغييرات جميع الزيارات</button></p> : <p className={styles.hint}>لتصفية السجل بحسب زيارة، اختر «تغييرات هذه الزيارة» من أحد أحداثها.</p>}
    {history.loading && <p role="status" className={styles.status}>جارٍ تحميل سجل التغييرات…</p>}
    {history.error && <div className={styles.status}><p role="alert">{history.error}</p><button className={styles.secondary} onClick={history.retry}>إعادة تحميل سجل التغييرات</button></div>}
    {history.data && <><p className={styles.resultSummary}>{history.data.meta.total} تغيير مطابق · التوقيت: <bdi>{history.data.timezone}</bdi></p>
      {!history.data.data.length ? <p className={styles.status}>لا توجد تغييرات مسجلة تطابق هذه الفلاتر.</p> : history.data.data.map(event => <article key={event.id} className={styles.detailPanel} aria-label={`${event.entity_label} — ${event.action_label}`}>
        <div className={styles.toolbar}><h3>{event.entity_label} <span className={event.action === "voided" ? styles.badge : styles.active}>{event.action_label}</span></h3><time dateTime={event.occurred_at}>{new Intl.DateTimeFormat("ar-SY", { dateStyle: "medium", timeStyle: "short", timeZone: history.data!.timezone }).format(new Date(event.occurred_at))}</time></div>
        <p>بواسطة {event.actor.name}</p>
        {event.visit && <div className={styles.toolbar}><p>الزيارة <bdi>{event.visit.visit_no}</bdi> · {event.visit.visit_date}</p>{!q.has("visit_id") && <button className={styles.secondary} onClick={() => filter("visit_id", String(event.visit!.id))}>تغييرات هذه الزيارة</button>}</div>}
        {!!event.changes.length ? <DirectoryTable label={`تفاصيل التغيير ${event.id}`} headers={["الحقل", "القيمة السابقة", "القيمة الجديدة"]}>{event.changes.map(change => <tr key={change.field}><th scope="row">{change.label}</th><td>{change.before_recorded ? <LongText text={change.before} /> : "لم تُحفظ قيمة سابقة"}</td><td><LongText text={change.after} /></td></tr>)}</DirectoryTable> : <p className={styles.hint}>الإجراء مسجل دون قيم تفصيلية متاحة للعرض.</p>}
        {event.reason && <p><strong>السبب المسجل: </strong>{event.reason}</p>}
      </article>)}
      <Pagination meta={history.data.meta} onPage={page => filter("page", String(page))} onPageSize={size => filter("per_page", size)} />
    </>}
  </section>;
}
