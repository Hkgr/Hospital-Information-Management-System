"use client";

import { useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest } from "../clinics/api";
import { DirectoryBack, DirectoryTable } from "../directory/DirectoryPrimitives";
import { LongText } from "../directory/Controls";
import { LuHospital } from "react-icons/lu";
import { formatAuditTime, type AuditEvent } from "./audit";
import styles from "../clinics/clinics.module.css";
import audit from "./audit.module.css";

function Kind({ kind, label }: { kind: string; label: string }) {
  return <span className={audit.kind} data-kind={kind}>{label}</span>;
}

export default function AuditEventScreen({ id }: { id: string }) {
  const { access } = useIdentity();
  const params = useSearchParams();
  const { entry, allowed } = directoryFacility(access, null, params.get("facility_id"));
  if (!entry || !/^[1-9]\d*$/.test(id)) return <section className={styles.status}><h2>السجل غير متاح</h2><p role="alert">{allowed.length ? "معرّف الحركة أو المنشأة غير صالح." : "لا منشأة مرتبطة بهذا الدخول لعرض سجل الحركة."}</p></section>;
  const facility = entry.facility.id;
  const event = useClinicRequest<AuditEvent>(`audit/${id}?facility_id=${facility}`);
  const back = `/audit?facility_id=${facility}`;
  if (event.error) return <div className={styles.status}><p role="alert">{event.error}</p><button className={styles.secondary} onClick={event.retry}>إعادة المحاولة</button><DirectoryBack href={back}>العودة إلى السجل</DirectoryBack></div>;
  if (!event.data) return <p role="status" className={styles.status}>جارٍ تحميل الحركة…</p>;
  const row = event.data;
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>
    <DirectoryBack href={back}>العودة إلى سجل الحركة</DirectoryBack>
    <div className={styles.heading}><div>
      <p className={styles.eyebrow}>حركة #{row.id}</p>
      <h2>{row.entity_label}</h2>
      <p className={audit.kinds}><Kind kind={row.category} label={row.category_label} /><Kind kind={row.action} label={row.action_label} /></p>
    </div></div>
    <section className={styles.detailPanel}>
      <dl className={styles.facts}>
        <div><dt>الوقت</dt><dd><time dateTime={row.occurred_at}>{formatAuditTime(row.occurred_at, entry.facility.timezone)}</time></dd></div>
        <div><dt>المستخدم</dt><dd>{row.actor.name}</dd></div>
        <div><dt>النوع</dt><dd>{row.entity_label}</dd></div>
        <div><dt>الإجراء</dt><dd>{row.action_label}</dd></div>
      </dl>
      {row.reason && <p><strong>السبب المسجل: </strong>{row.reason}</p>}
      {row.changes.length ? <DirectoryTable label={`تفاصيل الحركة ${row.id}`} headers={["الحقل", "القيمة السابقة", "القيمة الجديدة"]}>
        {row.changes.map(change => <tr key={change.field}><th scope="row">{change.label}</th><td>{change.before_recorded ? <LongText text={change.before} /> : "لم تُحفظ قيمة سابقة"}</td><td><LongText text={change.after} /></td></tr>)}
      </DirectoryTable> : <p className={styles.hint}>الإجراء مسجل دون قيم تفصيلية متاحة للعرض.</p>}
    </section>
  </div>;
}
