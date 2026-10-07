"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import styles from "../clinics/clinics.module.css";

export type AssignmentPeriod = { id: number; code: string; name: string; starts_on: string; ends_on: string | null; clinic_lock_version: number; staff_lock_version: number };

export default function AssignmentDatesEditor({ period, kind, parent, facility, onClose, onSaved }: { period: AssignmentPeriod; kind: "clinics" | "doctors"; parent: number; facility: number; onClose: () => void; onSaved: () => void }) {
  const [start, setStart] = useState(period.starts_on), [end, setEnd] = useState(period.ends_on ?? ""), [reason, setReason] = useState("");
  const [error, setError] = useState<AuthError | null>(null), [busy, setBusy] = useState(false), [conflict, setConflict] = useState(false);
  const pending = useRef<AbortController | null>(null);
  useEffect(() => () => pending.current?.abort(), []);
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (pending.current || conflict) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError(null);
    try {
      await apiRequest(`${kind}/${parent}/assignments/${period.id}`, { method: "PUT", signal: controller.signal, body: JSON.stringify({ facility_id: facility, starts_on: start, ends_on: end || null, reason, clinic_lock_version: period.clinic_lock_version, staff_lock_version: period.staff_lock_version }) });
      if (!controller.signal.aborted) { window.dispatchEvent(new Event("hospital-clinical-assignments-changed")); onSaved(); }
    } catch (e) { if (!controller.signal.aborted) { const failure = e instanceof AuthError ? e : new AuthError(0, "FAILED", "تعذّر حفظ تواريخ الارتباط. بقيت مسودتك."); setError(failure); setConflict(failure.status === 409); } }
    finally { if (!controller.signal.aborted) { pending.current = null; setBusy(false); } }
  }
  return <Modal title={`تواريخ الارتباط · ${period.name}`} onClose={onClose} busy={busy}><form className={styles.form} onSubmit={save}>
    <p className={styles.hint}>البداية مشمولة، والنهاية غير مشمولة. ترك النهاية فارغة يجعل الفترة مفتوحة؛ راجع الأثر قبل الحفظ. لا يتغير الطبيب أو العيادة أو الوقائع السابقة.</p>
    {error && <p role="alert" className={styles.error}>{error.message}{conflict && " أغلق الحوار واجلب أحدث سجل ثم راجع المسودة؛ لا يُعاد الحفظ تلقائيًا."}</p>}
    <fieldset className={styles.fields} disabled={busy || conflict}><label>بداية الارتباط *<input autoFocus type="date" required value={start} onChange={e => setStart(e.target.value)} />{error?.fields.starts_on && <small role="alert">{error.fields.starts_on}</small>}</label><label>نهاية الارتباط (غير مشمولة)<input type="date" value={end} onChange={e => setEnd(e.target.value)} />{error?.fields.ends_on && <small role="alert">{error.fields.ends_on}</small>}</label><label className={styles.full}>سبب تصحيح التواريخ *<textarea required minLength={3} maxLength={255} value={reason} onChange={e => setReason(e.target.value)} /></label></fieldset>
    <div className={styles.modalActions}><button className={styles.primary} disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : "حفظ تواريخ الارتباط"}</button><button className={styles.secondary} type="button" disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
