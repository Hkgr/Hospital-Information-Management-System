"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import Modal from "../clinics/Modal";
import ClinicPicker from "./ClinicPicker";
import DoctorConflict, { doctorFields, loadDoctorSnapshot, type DoctorSnapshot } from "./DoctorConflict";
import type { ClinicLink, Doctor, Options } from "./api";
import styles from "../clinics/clinics.module.css";

export default function DoctorEditor({ doctor, facilityId, options, linksOnly = false, onClose, onSaved, onReloaded }: { doctor?: Doctor; facilityId: number; options: Options; linksOnly?: boolean; onClose: () => void; onSaved: () => void; onReloaded: () => void }) {
  const [base, setBase] = useState(doctor); const [fields, setFields] = useState(doctorFields(doctor));
  const [changes, setChanges] = useState<Record<number, boolean>>({}); const [touched, setTouched] = useState<Record<number, ClinicLink>>({});
  const [conflict, setConflict] = useState(false); const [snapshot, setSnapshot] = useState<DoctorSnapshot | null>(null);
  const [busy, setBusy] = useState(false); const [fetching, setFetching] = useState(false); const [error, setError] = useState<AuthError | null>(null); const [reloadError, setReloadError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const fieldError = (key: string) => error?.fields[key] && <small className={styles.fieldError} id={`doctor-error-${key}`}>{error.fields[key]}</small>;
  async function reload() {
    if (!base || pending.current) return; pending.current = true; setFetching(true); setReloadError(""); setSnapshot(null);
    const active = new AbortController(); controller.current = active;
    try { const next = await loadDoctorSnapshot(base.id, facilityId, touched, active.signal, onReloaded); if (!active.signal.aborted) setSnapshot(next); }
    catch (reason) { if (!active.signal.aborted) setReloadError(reason instanceof Error ? reason.message : "تعذّر جلب أحدث نسخة؛ مسودتك محفوظة."); }
    finally { if (!active.signal.aborted) { pending.current = false; setFetching(false); } }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current || conflict) return; pending.current = true; setBusy(true); setError(null);
    const active = new AbortController(); controller.current = active;
    const deltas = { clinic_add_ids: Object.keys(changes).filter(id => changes[Number(id)]).map(Number), ...(base ? { lock_version: base.lock_version, clinic_remove_ids: Object.keys(changes).filter(id => !changes[Number(id)]).map(Number) } : {}) };
    try {
      await apiRequest<Doctor>(`doctors${base ? `/${base.id}${linksOnly ? "/clinics" : ""}` : ""}`, { method: base ? "PUT" : "POST", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, ...(!linksOnly ? { ...fields, staff_type_id: Number(fields.staff_type_id) } : {}), ...deltas }) });
      if (!active.signal.aborted) onSaved();
    } catch (reason) {
      if (!active.signal.aborted) { setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. حاول مجددًا.")); if (reason instanceof AuthError && reason.code === "DOCTOR_VERSION_CONFLICT") { setConflict(true); setSnapshot(null); setReloadError(""); } }
    } finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  const specialties = [...options.specialties, ...(base?.specialties.filter(s => !options.specialties.some(o => o.id === s.id)) ?? [])];
  const input = (key: "code" | "name" | "license_no" | "phone", label: string, max: number, required = false) => <label>{label}{required ? " *" : ""}<input autoFocus={key === "code"} required={required} maxLength={max} dir={key === "name" ? "auto" : "ltr"} value={fields[key]} onChange={e => setFields({ ...fields, [key]: e.target.value })} aria-invalid={!!error?.fields[key]} aria-describedby={error?.fields[key] ? `doctor-error-${key}` : undefined} />{fieldError(key)}</label>;
  return <Modal title={linksOnly ? "إدارة عيادات الطبيب" : doctor ? "تعديل الطبيب" : "إضافة طبيب جديد"} onClose={onClose} busy={busy} size={linksOnly ? "regular" : "wide"}>
    <form onSubmit={save} className={styles.form}>
      <p className={styles.scopeNote}>{linksOnly ? "تعدّل ارتباطات هذه المنشأة فقط. إزالة ارتباط لا تعطل الطبيب." : "بيانات الطبيب مشتركة بين المنشآت. تعديلها أو تعطيل الطبيب يسري عالميًا؛ اختيارات العيادات تخص المنشأة الحالية فقط."}</p>
      {error && <p role="alert" className={styles.error}>{conflict ? "تغيّرت بيانات الطبيب أو ارتباطاته. مسودتك واختيارات العيادات محفوظة. اجلب أحدث نسخة للمراجعة." : error.message}</p>}
      {conflict && <div><button type="button" className={styles.secondary} disabled={fetching} onClick={() => void reload()}>{fetching ? "جارٍ جلب أحدث نسخة…" : "جلب أحدث نسخة"}</button>{reloadError && <p role="alert" className={styles.error}>{reloadError}</p>}</div>}
      {snapshot && base && <DoctorConflict snapshot={snapshot} original={base} draft={fields} changes={changes} touched={touched} options={options} linksOnly={linksOnly} onAccept={(nextFields, nextChanges) => {
        setBase(snapshot.doctor); setFields(nextFields); setChanges(nextChanges); setTouched(Object.fromEntries(Object.keys(nextChanges).map(id => [id, snapshot.choices[Number(id)]!]))); setSnapshot(null); setConflict(false); setError(null);
      }} />}
      <fieldset disabled={busy || conflict} className={styles.fields}>
        {!linksOnly && <><div className={styles.sectionHeading}><span>01</span><div><h3>بيانات الدليل الطبي</h3><p>الحقول المعلّمة * مطلوبة.</p></div></div>
          {input("code", "كود الطبيب", 40, true)}{input("name", "الاسم الكامل", 200, true)}
          <label className={styles.full}>التوصيف المهني<textarea aria-label="التوصيف المهني" rows={3} maxLength={10000} value={fields.description} onChange={e => setFields({ ...fields, description: e.target.value })} />{fieldError("description")}</label>
          <label>نوع الطبيب *<select required value={fields.staff_type_id} onChange={e => setFields({ ...fields, staff_type_id: e.target.value })}><option value="">اختر نوع الطبيب</option>{base && !options.staff_types.some(t => t.id === base.staff_type.id) && <option value={base.staff_type.id}>{base.staff_type.name_ar} · النوع الحالي</option>}{options.staff_types.map(t => <option key={t.id} value={t.id}>{t.name_ar}</option>)}</select>{fieldError("staff_type_id")}</label>
          <label>الحالة<select value={String(fields.is_active)} onChange={e => setFields({ ...fields, is_active: e.target.value === "true" })}><option value="true">فعال</option><option value="false">غير فعال عالميًا</option></select></label>
          <fieldset className={`${styles.picker} ${styles.full}`}><legend>التخصصات {options.specialties.length ? "*" : ""}</legend>{specialties.length ? <div className={styles.specialtyChoices}>{specialties.map(s => <label key={s.id}><input type="checkbox" checked={fields.specialty_ids.includes(s.id)} onChange={e => setFields({ ...fields, specialty_ids: (e.target.checked ? [...fields.specialty_ids, s.id] : fields.specialty_ids.filter(id => id !== s.id)).sort((a,b) => a-b) })} />{s.name_ar}{s.is_active === false ? " · غير فعال" : ""}</label>)}</div> : <p className={styles.hint}>لا توجد تخصصات متاحة في الدليل الحالي.</p>}{fieldError("specialty_ids")}</fieldset>
          {input("license_no", "رقم الترخيص", 60)}{input("phone", "الهاتف", 30)}
        </>}
        {options.capabilities.link && <div className={styles.full}><div className={styles.sectionHeading}><span>{linksOnly ? "01" : "02"}</span><div><h3>الارتباطات الحالية</h3><p>ضمن المنشأة المحددة، مع حفظ التاريخ.</p></div></div><ClinicPicker key={base?.lock_version ?? "new"} doctorId={base?.id} facilityId={facilityId} changes={changes} onChange={(clinic, desired) => {
          setChanges(previous => { const next = { ...previous }; if (desired === clinic.is_linked) delete next[clinic.id]; else next[clinic.id] = desired; return next; });
          setTouched(previous => { const next = { ...previous }; if (desired === clinic.is_linked) delete next[clinic.id]; else next[clinic.id] = clinic; return next; });
        }} />{fieldError("clinic_add_ids")}{fieldError("clinic_remove_ids")}</div>}
      </fieldset>
      <div className={styles.modalActions}><button type="submit" className={styles.primary} disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : linksOnly ? "حفظ الارتباطات" : "حفظ الطبيب"}</button><button type="button" className={styles.secondary} onClick={onClose} disabled={busy}>إلغاء</button></div>
    </form>
  </Modal>;
}
