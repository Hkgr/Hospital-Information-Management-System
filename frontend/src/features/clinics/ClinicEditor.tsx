"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import { useClinicRequest, type Clinic, type Doctor, type Specialty } from "./api";
import ClinicConflictReview, { clinicFields, loadClinicSnapshot, type ClinicSnapshot } from "./ClinicConflictReview";
import Modal from "./Modal";
import DoctorPicker from "./DoctorPicker";
import styles from "./clinics.module.css";

export default function ClinicEditor({ clinic, facilityId, onClose, onSaved, onReloaded }: { clinic?: Clinic; facilityId: number; onClose: () => void; onSaved: () => void; onReloaded: () => void }) {
  const [baseClinic, setBaseClinic] = useState(clinic);
  const [fields, setFields] = useState(clinicFields(clinic));
  const [changes, setChanges] = useState<Record<number, boolean>>({});
  const [changedDoctors, setChangedDoctors] = useState<Record<number, Doctor>>({});
  const [conflict, setConflict] = useState(false);
  const [snapshot, setSnapshot] = useState<ClinicSnapshot | null>(null);
  const [fetching, setFetching] = useState(false);
  const [reloadError, setReloadError] = useState("");
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const [error, setError] = useState<AuthError | null>(null);
  const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const specialties = useClinicRequest<Specialty[]>(`clinics/options/specialties?facility_id=${facilityId}`);
  const fieldError = (key: string) => error?.fields[key] ? <small id={`clinic-error-${key}`} className={styles.fieldError}>{error.fields[key]}</small> : null;
  async function reload() {
    if (!baseClinic || pending.current) return;
    pending.current = true; setFetching(true); setReloadError(""); setSnapshot(null);
    const active = new AbortController(); controller.current = active;
    try {
      const latest = await loadClinicSnapshot(baseClinic.id, facilityId, changedDoctors, active.signal, onReloaded);
      if (!active.signal.aborted) setSnapshot(latest);
    } catch (reason) {
      if (!active.signal.aborted) setReloadError(reason instanceof Error ? reason.message : "تعذّر جلب أحدث نسخة. أعد المحاولة؛ مسودتك محفوظة.");
    } finally { if (!active.signal.aborted) { pending.current = false; setFetching(false); } }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current || conflict) return;
    pending.current = true; setBusy(true); setError(null);
    const active = new AbortController(); controller.current = active;
    try {
      await apiRequest<Clinic>(`clinics${clinic ? `/${clinic.id}` : ""}`, { method: clinic ? "PUT" : "POST", signal: active.signal, body: JSON.stringify({
        ...fields, facility_id: facilityId, specialty_id: fields.specialty_id ? Number(fields.specialty_id) : null,
        ...(baseClinic ? { lock_version: baseClinic.lock_version, doctor_remove_ids: Object.keys(changes).filter(id => !changes[Number(id)]).map(Number) } : {}),
        doctor_add_ids: Object.keys(changes).filter(id => changes[Number(id)]).map(Number),
      }) });
      if (!active.signal.aborted) onSaved();
    } catch (reason) {
      if (!active.signal.aborted) {
        setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. حاول مجددًا."));
        if (reason instanceof AuthError && reason.code === "CLINIC_VERSION_CONFLICT") { setConflict(true); setSnapshot(null); setReloadError(""); }
      }
    } finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={clinic ? "تعديل العيادة" : "إضافة عيادة جديدة"} onClose={onClose} busy={busy}>
    <form onSubmit={save} className={styles.form}>
      <p className={styles.hint}>بيانات العيادة وارتباطاتها ضمن المنشأة المحددة. الحقول المعلّمة * مطلوبة.</p>
      {error && <p role="alert" className={styles.error}>{conflict ? "عدّل مستخدم آخر هذه العيادة. مسودتك واختيارات الأطباء محفوظة. اجلب أحدث نسخة لمراجعة ما تريد تطبيقه." : error.message}</p>}
      {conflict && <div><button type="button" className={styles.secondary} disabled={fetching} onClick={() => void reload()}>{fetching ? "جارٍ جلب أحدث نسخة…" : "جلب أحدث نسخة"}</button>{reloadError && <p role="alert" className={styles.error}>{reloadError}</p>}</div>}
      {snapshot && baseClinic && <ClinicConflictReview snapshot={snapshot} original={baseClinic} draft={fields} changes={changes} changedDoctors={changedDoctors} onAccept={(nextFields, nextChanges) => {
        setBaseClinic(snapshot.clinic); setFields(nextFields); setChanges(nextChanges);
        setChangedDoctors(Object.fromEntries(Object.keys(nextChanges).map(id => [id, snapshot.choices[Number(id)]!])));
        setSnapshot(null); setConflict(false); setError(null); setReloadError("");
      }} />}
      <fieldset disabled={busy || conflict} className={styles.fields}>
        <label>كود العيادة *<input autoFocus required maxLength={40} dir="auto" value={fields.code} onChange={e => setFields({ ...fields, code: e.target.value })} aria-invalid={!!error?.fields.code} aria-describedby={error?.fields.code ? "clinic-error-code" : undefined} />{fieldError("code")}</label>
        <label>اسم العيادة *<input required maxLength={200} value={fields.name_ar} onChange={e => setFields({ ...fields, name_ar: e.target.value })} aria-invalid={!!error?.fields.name_ar} aria-describedby={error?.fields.name_ar ? "clinic-error-name_ar" : undefined} />{fieldError("name_ar")}</label>
        <label className={styles.full}>التوصيف<textarea rows={3} maxLength={10000} value={fields.description} onChange={e => setFields({ ...fields, description: e.target.value })} />{fieldError("description")}</label>
        <label>التخصص<select value={fields.specialty_id} onChange={e => setFields({ ...fields, specialty_id: e.target.value })}><option value="">دون تخصص</option>{baseClinic?.specialty && !specialties.data?.some(s => s.id === baseClinic.specialty?.id) && <option value={baseClinic.specialty.id}>{baseClinic.specialty.name_ar}</option>}{specialties.data?.map(s => <option key={s.id} value={s.id}>{s.name_ar}</option>)}</select>{fieldError("specialty_id")}</label>
        <label>الحالة<select value={String(fields.is_active)} onChange={e => setFields({ ...fields, is_active: e.target.value === "true" })}><option value="true">فعالة</option><option value="false">غير فعالة</option></select></label>
        {specialties.error && <p role="alert" className={styles.full}>{specialties.error} <button type="button" onClick={specialties.retry}>إعادة تحميل التخصصات</button></p>}
        <div className={styles.full}><DoctorPicker key={baseClinic?.lock_version ?? "new"} clinicId={clinic?.id} facilityId={facilityId} changes={changes} onChange={(id, selected, original, doctor) => {
          setChanges(previous => { const next = { ...previous }; if (selected === original) delete next[id]; else next[id] = selected; return next; });
          setChangedDoctors(previous => { const next = { ...previous }; if (selected === original) delete next[id]; else next[id] = doctor; return next; });
        }} />{fieldError("doctor_add_ids")}{fieldError("doctor_remove_ids")}</div>
      </fieldset>
      <div className={styles.modalActions}><button className={styles.primary} type="submit" disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : "حفظ العيادة"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
    </form>
  </Modal>;
}
