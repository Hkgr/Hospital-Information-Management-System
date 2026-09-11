"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import { useClinicRequest, type Clinic, type Specialty } from "./api";
import Modal from "./Modal";
import DoctorPicker from "./DoctorPicker";
import styles from "./clinics.module.css";

export default function ClinicEditor({ clinic, facilityId, onClose, onSaved }: { clinic?: Clinic; facilityId: number; onClose: () => void; onSaved: () => void }) {
  const [fields, setFields] = useState({ code: clinic?.code ?? "", name_ar: clinic?.name_ar ?? "", description: clinic?.description ?? "", specialty_id: clinic?.specialty?.id ? String(clinic.specialty.id) : "", is_active: clinic?.is_active ?? true });
  const [changes, setChanges] = useState<Record<number, boolean>>({});
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const [error, setError] = useState<AuthError | null>(null);
  const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const specialties = useClinicRequest<Specialty[]>(`clinics/options/specialties?facility_id=${facilityId}`);
  const fieldError = (key: string) => error?.fields[key] ? <small id={`clinic-error-${key}`} className={styles.fieldError}>{error.fields[key]}</small> : null;
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current) return;
    pending.current = true; setBusy(true); setError(null);
    const active = new AbortController(); controller.current = active;
    try {
      await apiRequest<Clinic>(`clinics${clinic ? `/${clinic.id}` : ""}`, { method: clinic ? "PUT" : "POST", signal: active.signal, body: JSON.stringify({
        ...fields, facility_id: facilityId, specialty_id: fields.specialty_id ? Number(fields.specialty_id) : null,
        ...(clinic ? { lock_version: clinic.lock_version, doctor_remove_ids: Object.keys(changes).filter(id => !changes[Number(id)]).map(Number) } : {}),
        doctor_add_ids: Object.keys(changes).filter(id => changes[Number(id)]).map(Number),
      }) });
      if (!active.signal.aborted) onSaved();
    } catch (reason) {
      if (!active.signal.aborted) setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. حاول مجددًا."));
    } finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={clinic ? "تعديل العيادة" : "إضافة عيادة جديدة"} onClose={onClose} busy={busy}>
    <form onSubmit={save} className={styles.form}>
      <p className={styles.hint}>بيانات العيادة وارتباطاتها ضمن المنشأة المحددة. الحقول المعلّمة * مطلوبة.</p>
      {error && <p role="alert" className={styles.error}>{error.message}</p>}
      <fieldset disabled={busy} className={styles.fields}>
        <label>كود العيادة *<input autoFocus required maxLength={40} dir="auto" value={fields.code} onChange={e => setFields({ ...fields, code: e.target.value })} aria-invalid={!!error?.fields.code} aria-describedby={error?.fields.code ? "clinic-error-code" : undefined} />{fieldError("code")}</label>
        <label>اسم العيادة *<input required maxLength={200} value={fields.name_ar} onChange={e => setFields({ ...fields, name_ar: e.target.value })} aria-invalid={!!error?.fields.name_ar} aria-describedby={error?.fields.name_ar ? "clinic-error-name_ar" : undefined} />{fieldError("name_ar")}</label>
        <label className={styles.full}>التوصيف<textarea rows={3} maxLength={10000} value={fields.description} onChange={e => setFields({ ...fields, description: e.target.value })} />{fieldError("description")}</label>
        <label>التخصص<select value={fields.specialty_id} onChange={e => setFields({ ...fields, specialty_id: e.target.value })}><option value="">دون تخصص</option>{clinic?.specialty && !specialties.data?.some(s => s.id === clinic.specialty?.id) && <option value={clinic.specialty.id}>{clinic.specialty.name_ar}</option>}{specialties.data?.map(s => <option key={s.id} value={s.id}>{s.name_ar}</option>)}</select>{fieldError("specialty_id")}</label>
        <label>الحالة<select value={String(fields.is_active)} onChange={e => setFields({ ...fields, is_active: e.target.value === "true" })}><option value="true">فعالة</option><option value="false">غير فعالة</option></select></label>
        {specialties.error && <p role="alert" className={styles.full}>{specialties.error} <button type="button" onClick={specialties.retry}>إعادة تحميل التخصصات</button></p>}
        <div className={styles.full}><DoctorPicker clinicId={clinic?.id} facilityId={facilityId} changes={changes} onChange={(id, selected, original) => setChanges(previous => { const next = { ...previous }; if (selected === original) delete next[id]; else next[id] = selected; return next; })} />{fieldError("doctor_add_ids")}{fieldError("doctor_remove_ids")}</div>
      </fieldset>
      <div className={styles.modalActions}><button className={styles.primary} type="submit" disabled={busy}>{busy ? "جارٍ الحفظ…" : "حفظ العيادة"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
    </form>
  </Modal>;
}
