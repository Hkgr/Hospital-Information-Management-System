"use client";
import { type Choice, type Options, personLabels, choices, useBloodRequest } from "./api";
import { type Person } from "./events";
import AddressFields from "./AddressFields";
import Picker from "./Picker";
import styles from "../clinics/clinics.module.css";

export type Draft = Record<string, string>;
export function personDraft(person?: Person): Draft {
  const d: Draft = { person_mode: person?.patient_id ? "patient" : "direct", blood_group: person?.blood_group ?? "", rh: person?.rh ?? "" };
  for (const key of Object.keys(personLabels)) d[key] = String((!person?.patient_id ? person?.person[key] : null) ?? (["gender", "birth_date_accuracy", "displacement_status"].includes(key) ? "unknown" : ""));
  d.governorate_text = String(person?.person.governorate_text ?? ""); d.city_text = String(person?.person.city_text ?? "");
  d.governorate_mode = d.governorate_text ? "foreign" : "directory"; d.city_mode = d.city_text ? "manual" : "directory";
  return d;
}
export function personPayload(d: Draft, patient: Choice | null) {
  const p: Record<string, unknown> = { person_mode: d.person_mode, blood_group: d.blood_group || null, rh: d.rh || null };
  if (d.person_mode === "patient") p.patient_id = patient?.id;
  else {
    for (const key of Object.keys(personLabels)) p[key] = d[key] || null;
    p.governorate_id = d.governorate_mode === "directory" ? d.governorate_id || null : null;
    p.governorate_text = d.governorate_mode === "foreign" ? d.governorate_text || null : null;
    p.city_id = d.governorate_mode === "directory" && d.city_mode === "directory" ? d.city_id || null : null;
    p.city_text = d.governorate_mode === "foreign" || (d.governorate_id && d.city_mode === "manual") ? d.city_text || null : null;
  }
  return p;
}
export function BloodFields({ draft, change }: { draft: Draft; change: (key: string, value: string) => void }) {
  return <><label>زمرة ABO<select aria-label="زمرة ABO" value={draft.blood_group} onChange={e => change("blood_group", e.target.value)}><option value="">غير معروفة</option>{["A", "B", "AB", "O"].map(g => <option key={g}>{g}</option>)}</select></label><label>عامل Rh<select aria-label="عامل Rh" value={draft.rh} onChange={e => change("rh", e.target.value)}><option value="">غير معروف</option>{Object.entries(choices.rh).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select></label></>;
}
export default function PersonFields({ draft, change, patient, onPatient, base, facilityId, options, fieldError }: { draft: Draft; change: (key: string, value: string) => void; patient: Choice | null; onPatient: (p: Choice) => void; base?: Person; facilityId: number; options: Options; fieldError: (key: string) => React.ReactNode }) {
  const linked = draft.person_mode === "patient";
  const current = useBloodRequest<Record<string, string | number | null>>(linked && patient && patient.id !== base?.patient_id ? `blood-bank/patients/${patient.id}?facility_id=${facilityId}` : null);
  const cities = useBloodRequest<Choice[]>(!linked && draft.governorate_mode === "directory" && draft.governorate_id ? `blood-bank/cities?facility_id=${facilityId}&governorate_id=${draft.governorate_id}` : null);
  return <>
    {!base && <label className={styles.full}>مصدر بيانات الشخص<select value={draft.person_mode} onChange={e => change("person_mode", e.target.value)}><option value="direct">إدخال البيانات مباشرة</option><option value="patient" disabled={!options.capabilities.patients_search}>اختيار مريض مسجل في المشفى</option></select></label>}
    {linked ? <div className={styles.full}>{!base && <Picker label="المريض المسجل" path={`blood-bank/patients?facility_id=${facilityId}`} selected={patient} onSelect={onPatient} />}{fieldError("patient_id")}
      {current.loading && <p role="status">جارٍ جلب بيانات المريض…</p>}{current.error && <p role="alert">تعذّر جلب المريض؛ الاختيار محفوظ. <button type="button" onClick={current.retry}>إعادة المحاولة</button></p>}
      {(base || current.data) && <><p className={styles.hint}>تُقرأ البيانات الحالية من ملف المريض دون نسخها أو تعديلها هنا.</p><dl className={styles.facts}>{Object.entries(personLabels).filter(([k]) => !k.endsWith("_id")).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{choices[key]?.[String((base?.person ?? current.data)?.[key])] ?? (base?.person ?? current.data)?.[key] ?? "غير مسجل"}</dd></div>)}</dl></>}
    </div> : <>
      {Object.entries(personLabels).filter(([key]) => !["governorate_id", "city_id", "address_line", "displacement_status"].includes(key)).map(([key, label]) => <label key={key}>{label}{["first_name", "family_name"].includes(key) ? " *" : ""}{choices[key] ? <select aria-label={label} value={draft[key]} onChange={e => change(key, e.target.value)}>{Object.entries(choices[key]).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select> : <input aria-label={label} type={key === "birth_date" ? "date" : "text"} required={["first_name", "family_name"].includes(key)} maxLength={key.includes("phone") ? 30 : key === "mother_name" ? 120 : 80} value={draft[key]} onChange={e => change(key, e.target.value)} />}{fieldError(key)}</label>)}
      <AddressFields draft={draft} profile={base} options={options} cities={cities} change={change} fieldError={fieldError} />
      <label className={styles.full}>عنوان السكن<input aria-label="عنوان السكن" maxLength={255} value={draft.address_line} onChange={e => change("address_line", e.target.value)} />{fieldError("address_line")}</label>
      <label>حالة النزوح<select value={draft.displacement_status} onChange={e => change("displacement_status", e.target.value)}>{Object.entries(choices.displacement_status).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select></label>
    </>}
  </>;
}
