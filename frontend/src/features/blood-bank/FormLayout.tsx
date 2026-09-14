import type { ReactNode } from "react";
import { LuUserRound } from "react-icons/lu";
import type { Person } from "./events";
import { bloodLabel } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";

export function FormSection({ number, title, hint, children }: { number: string; title: string; hint: string; children: ReactNode }) {
  return <section className={layout.section} aria-label={title}>
    <div className={styles.sectionHeading}><span>{number}</span><div><h3>{title}</h3><p>{hint}</p></div></div>
    <div className={styles.fields}>{children}</div>
  </section>;
}

export function PersonSummary({ person, hint = "تعديل زمرة الواقعة لا يغيّر ملف هذا الشخص." }: { person: Person; hint?: string }) {
  return <aside className={layout.personSummary} aria-label="ملخص الشخص المحدد">
    <span className={layout.personIcon}><LuUserRound aria-hidden="true" /></span>
    <div><small>الشخص المحدد</small><strong>{person.name} · <bdi>{person.code}</bdi></strong>
      <div className={layout.summaryFacts}><span>الزمرة الحالية: {bloodLabel(person.blood_group, person.rh)}</span><span>الهاتف: <bdi>{person.person.phone || "غير مسجل"}</bdi></span>{person.patient_code && <span>المريض: <bdi>{person.patient_code}</bdi></span>}</div>
      <p>{hint}</p>
    </div>
  </aside>;
}

export function FormActions({ busy, disabled, label, hint = "راجع البيانات قبل الحفظ", onClose }: { busy: boolean; disabled: boolean; label: string; hint?: string; onClose: () => void }) {
  return <div className={`${styles.modalActions} ${layout.formActions}`}>
    <span className={layout.saveHint}>{busy ? "جارٍ حفظ البيانات…" : hint}</span>
    <button type="submit" className={styles.primary} disabled={disabled}>{busy ? "جارٍ الحفظ…" : label}</button>
    <button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button>
  </div>;
}
