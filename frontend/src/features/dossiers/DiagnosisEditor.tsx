"use client";
import { useCreationRequest, CreationRecovery } from "../directory/useCreationRequest";
import { useEffect, useRef, useState } from "react";
import { AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import ClinicDoctorPicker from "./ClinicDoctorPicker";
import Picker from "./DossierPicker";
import type { Choice } from "../blood-bank/api";
import type { DiagnosisDraft } from "./wizard";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

export default function DiagnosisEditor({ row, index, facility, date, canCreate, change, remove, error }: { row: DiagnosisDraft; index: number; facility: number; date: string; canCreate: boolean; change: (row: DiagnosisDraft) => void; remove: () => void; error: (key: string) => React.ReactNode }) {
  const [adding, setAdding] = useState(false); const [revision, setRevision] = useState(0);
  const base = `dossiers/options`; const scope = `facility_id=${facility}`;
  return <section className={layout.diagnosis} aria-label={`التشخيص ${index + 1}`}>
    <div className={layout.diagnosisHeader}><h4>التشخيص {index + 1}{row.id && " · محفوظ"}</h4><button type="button" className={`${styles.secondary} ${row.remove ? "" : styles.dangerText}`} onClick={() => row.id ? change({ ...row, remove: !row.remove }) : remove()}>{row.remove ? "التراجع عن الإزالة" : "إزالة التشخيص"}</button></div>
    {row.remove ? <label>سبب إزالة التشخيص *<input name={`diagnoses.${index}.void_reason`} value={row.void_reason ?? ""} onChange={e => change({ ...row, void_reason: e.target.value })} />{error(`diagnoses.${index}.void_reason`)}</label> : <div className={styles.fields}>
      <div><Picker key={revision} name={`diagnoses.${index}.diagnosis_id`} label={`التشخيص من الدليل ${index + 1}`} path={`${base}/diagnoses?${scope}`} selected={row.diagnosis} onSelect={diagnosis => change({ ...row, diagnosis })} />{error(`diagnoses.${index}.diagnosis_id`)}{canCreate && <button type="button" className={styles.secondary} onClick={() => setAdding(true)}>إضافة تشخيص إلى الدليل</button>}</div>
      <label>تاريخ التشخيص (اختياري)<input name={`diagnoses.${index}.diagnosed_on`} type="date" value={row.diagnosed_on} onChange={e => change({ ...row, diagnosed_on: e.target.value })} /><small className={styles.hint}>اتركه فارغًا إذا كان غير معروف.</small>{error(`diagnoses.${index}.diagnosed_on`)}</label>
      <div><Picker name={`diagnoses.${index}.clinic_id`} label={`العيادة للتشخيص ${index + 1}`} path={`${base}/clinics?${scope}`} selected={row.clinic} onSelect={clinic => change({ ...row, clinic, doctor: clinic.id === row.clinic?.id ? row.doctor : null })} />{error(`diagnoses.${index}.clinic_id`)}</div>
      <div><ClinicDoctorPicker saved={!!row.id} facility={facility} clinic={row.clinic} date={date} name={`diagnoses.${index}.diagnosing_staff_id`} label={`الطبيب المسؤول عن التشخيص ${index + 1}`} selected={row.doctor} onSelect={doctor=>change({...row,doctor})}/>{error(`diagnoses.${index}.diagnosing_staff_id`)}</div>
    </div>}
    {adding && <NewDirectoryEntry facility={facility} onClose={() => setAdding(false)} onSaved={diagnosis => { change({ ...row, diagnosis }); setRevision(x => x + 1); setAdding(false); }} />}
  </section>;
}
export function NewDirectoryEntry({ facility, onClose, onSaved, kind = "diagnosis" }: { kind?: "diagnosis" | "medication"; facility: number; onClose: () => void; onSaved: (choice: Choice) => void }) {
  const creation = useCreationRequest();
  const noun = kind === "medication" ? "الدواء" : "التشخيص";
  const [name, setName] = useState(""); const [error, setError] = useState<AuthError | null>(null); const [busy, setBusy] = useState(false);
  const pending = useRef<AbortController | null>(null);
  useEffect(() => () => pending.current?.abort(), []);
  async function save() {
    if (pending.current) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError(null);
    const fields = JSON.stringify({ facility_id: facility, name_ar: name });
    try { const row = await creation.request<Choice>(kind === "medication" ? "dossiers/medications" : "dossiers/diagnoses", { method: "POST", signal: controller.signal, body: fields }); if (!controller.signal.aborted) onSaved(row); }
    catch (e) { if (!controller.signal.aborted) setError(e as AuthError); }
    finally { pending.current = null; if (!controller.signal.aborted) setBusy(false); }
  }
  return <Modal title={`إضافة ${noun} إلى الدليل المشترك`} size="compact" busy={busy} onClose={onClose}><div className={styles.form}><CreationRecovery creation={creation} onSaved={onSaved} /><p className={styles.hint}>إضافة تعريف إلى الدليل؛ لا تحفظ بطاقة المريض أو الزيارة تلقائيًا. يُمنح الكود تلقائيًا عند الحفظ.</p><label>اسم {noun} *<input aria-label={`اسم ${noun} الجديد`} value={name} maxLength={200} onChange={e => setName(e.target.value)} />{error?.fields.name_ar && <small role="alert">{error.fields.name_ar}</small>}</label>{error && <p role="alert">{error.message}</p>}<div className={styles.actions}><button type="button" className={styles.primary} disabled={busy} onClick={() => void save()}>حفظ {noun}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div></div></Modal>;
}
