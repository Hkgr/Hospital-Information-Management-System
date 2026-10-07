"use client";

import Link from "next/link";
import styles from "../clinics/clinics.module.css";

export default function CardEditActions({ facility, dossier, visit, status, caps }: { facility: number; dossier: number; visit?: number; status?: string; caps: Record<string, boolean> }) {
  const actions = [
    ["medical_update", "تعديل المعلومات الطبية", 1], ["diagnoses_update", "تعديل التشخيصات", 2],
    ["services_update", "تعديل الخدمات", 3], ["procedures_update", "تعديل الإجراءات", 3],
    ["prescriptions_update", "تعديل الوصفات", 4], ["outcomes_update", "تعديل نتيجة الزيارة", 4],
    ["pathology_update", "تعديل تقرير التشريح المرضي", 6], ["treatment_update", "تعديل الخطة العلاجية", 7],
    ["treatment_schedule_update", "تعديل المواعيد والجرعات العلاجية", 7], ["treatment_administration_correct", "تصحيح الإعطاء", 8],
    ["treatment_dispensing_correct", "تصحيح الصرف", 8],
  ] as const;
  return <section aria-label="تعديل البيانات المحفوظة"><div className={styles.actions}>{actions.filter(([cap, , section]) => caps[cap] && ((section === 1 && !visit) || (visit && (status === "draft" || section >= 7)))).map(([cap, label, section]) => <Link key={cap} className={styles.secondary} href={`/patient-cards/new?facility_id=${facility}&card=${dossier}${visit && section !== 1 ? `&visit=${visit}` : ""}&section=${section}`}>{label}</Link>)}</div>{visit && status === "complete" && <p className={styles.hint}>الزيارة مكتملة: التشخيصات والخدمات والوصفات محفوظة للقراءة. التصحيح المنضبط للإعطاء أو الصرف يبقى متاحًا بحسب الصلاحية، ولا تعيد صلاحية التعديل فتح الزيارة تلقائيًا.</p>}</section>;
}
