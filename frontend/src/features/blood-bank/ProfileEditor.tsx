"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import Picker from "./Picker";
import AddressFields from "./AddressFields";
import ScreeningFields from "./ScreeningFields";
import ConflictReview from "./ConflictReview";
import InlineDoctor from "./InlineDoctor";
import { type Choice, type Kind, type Options, type Profile, choices, kindName, personLabels, statuses, useBloodRequest } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";

type Draft = Record<string, string>;
function draftOf(profile?: Profile): Draft {
  const draft: Draft = { identity: profile?.patient_id ? `patient:${profile.patient_id}` : "direct", beneficiary_entity: profile?.beneficiary_entity ?? "", blood_group: profile?.blood_group ?? "", rh: profile?.rh ?? "", clinic_id: String(profile?.clinic_id ?? ""), responsible_staff_id: String(profile?.responsible_staff_id ?? ""), blood_component_id: String(profile?.blood_component_id ?? "") };
  for (const key of Object.keys(personLabels)) draft[key] = String((!profile?.patient_id ? profile?.person[key] : null) ?? (["gender", "birth_date_accuracy", "displacement_status"].includes(key) ? "unknown" : ""));
  for (const a of ["HBsAg", "HCV", "HIV"]) { const screen = profile?.screenings.find(s => s.analyte === a); draft[a] = screen ? screen.status : ""; }
  draft.governorate_text = String(profile?.person.governorate_text ?? ""); draft.city_text = String(profile?.person.city_text ?? "");
  draft.governorate_mode = draft.governorate_text ? "foreign" : "directory"; draft.city_mode = draft.city_text ? "manual" : "directory";
  return draft;
}
const labels = { ...personLabels, governorate_text: "المحافظة خارج سوريا", city_text: "المدينة اليدوية", governorate_mode: "مصدر المحافظة", city_mode: "مصدر المدينة", identity: "مصدر بيانات المستفيد", beneficiary_entity: "جهة المستفيد", blood_group: "زمرة ABO", rh: "عامل Rh", clinic_id: "العيادة", responsible_staff_id: "الطبيب المسؤول", blood_component_id: "نوع المكوّن", HBsAg: "فحص HBsAg", HCV: "فحص HCV", HIV: "فحص HIV" };

export default function ProfileEditor({ kind, profile, facilityId, options, onClose, onSaved, onRefresh }: { kind: Kind; profile?: Profile; facilityId: number; options: Options; onClose: () => void; onSaved: (profile: Profile) => void; onRefresh: () => void }) {
  const [base, setBase] = useState(profile); const [draft, setDraft] = useState(() => draftOf(profile));
  const [patientMode, setPatientMode] = useState(profile?.person_mode === "patient");
  const [chosenPatient, setChosenPatient] = useState<Choice | null>(profile?.patient_id ? { id: profile.patient_id, name_ar: profile.name, code: profile.patient_code ?? "" } : null);
  const [clinic, setClinic] = useState<Choice | null>(profile?.clinic_id ? { id: profile.clinic_id, name_ar: profile.clinic_name ?? "العيادة الحالية" } : null);
  const [doctor, setDoctor] = useState<Choice | null>(profile?.responsible_staff_id ? { id: profile.responsible_staff_id, name_ar: profile.doctor_name ?? "الطبيب الحالي" } : null);
  const [inline, setInline] = useState(false); const [busy, setBusy] = useState(false); const [error, setError] = useState<AuthError | null>(null);
  const [conflict, setConflict] = useState(false); const [latest, setLatest] = useState<Profile | null>(null); const [reloadError, setReloadError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null); const retry = useRef<{ body: string; id: string } | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const change = (key: string, value: string) => setDraft(previous => ({ ...previous, [key]: value }));
  const patientId = patientMode ? chosenPatient?.id : null;
  const patient = useBloodRequest<Record<string, string | number | null>>(patientId && patientId !== base?.patient_id ? `blood-bank/patients/${patientId}?facility_id=${facilityId}` : null);
  const person = patientId === base?.patient_id ? base?.person : patient.data;
  const governorate = patientMode ? person?.governorate_id : draft.governorate_mode === "directory" ? draft.governorate_id : null;
  const cities = useBloodRequest<Choice[]>(governorate ? `blood-bank/cities?facility_id=${facilityId}&governorate_id=${governorate}` : null);
  const fieldError = (key: string) => error?.fields[key] && <small className={styles.fieldError}>{error.fields[key]}</small>;
  async function reload() {
    if (!base || pending.current) return; pending.current = true; setBusy(true); setReloadError(""); setLatest(null);
    const active = new AbortController(); controller.current = active;
    try { const value = await apiRequest<Profile>(`blood-bank/${kind}/${base.id}?facility_id=${facilityId}`, { signal: active.signal }); if (!active.signal.aborted) { setLatest(value); onRefresh(); } }
    catch { if (!active.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ مسودتك محفوظة. أعد المحاولة."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current || conflict || (patientMode && (!patientId || !person || patient.loading || patient.error))) return;
    const payload: Record<string, unknown> = { facility_id: facilityId, ...base ? { lock_version: base.lock_version } : { kind }, person_mode: patientMode ? "patient" : "direct", clinic_id: Number(draft.clinic_id), responsible_staff_id: Number(draft.responsible_staff_id), blood_component_id: draft.blood_component_id ? Number(draft.blood_component_id) : null, blood_group: draft.blood_group || null, rh: draft.rh || null, screenings: ["HBsAg", "HCV", "HIV"].filter(a => draft[a]).map(analyte => ({ analyte, status: draft[analyte] })) };
    if (kind === "recipient") payload.beneficiary_entity = draft.beneficiary_entity || null;
    if (patientMode) payload.patient_id = patientId;
    else {
      for (const key of Object.keys(personLabels)) payload[key] = draft[key] || null;
      payload.governorate_id = draft.governorate_mode === "directory" ? draft.governorate_id || null : null;
      payload.governorate_text = draft.governorate_mode === "foreign" ? draft.governorate_text || null : null;
      payload.city_id = draft.governorate_mode === "directory" && draft.city_mode === "directory" ? draft.city_id || null : null;
      payload.city_text = draft.governorate_mode === "foreign" || (draft.governorate_id && draft.city_mode === "manual") ? draft.city_text || null : null;
    }
    const body = JSON.stringify(payload); if (retry.current?.body !== body) retry.current = { body, id: crypto.randomUUID() };
    pending.current = true; setBusy(true); setError(null); const active = new AbortController(); controller.current = active;
    try { const saved = await apiRequest<Profile>(`blood-bank${base ? `/${kind}/${base.id}` : ""}`, { method: base ? "PUT" : "POST", body: JSON.stringify({ ...payload, request_id: retry.current.id }), signal: active.signal }); if (!active.signal.aborted) onSaved(saved); }
    catch (reason) { if (!active.signal.aborted) { const err = reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. مسودتك محفوظة؛ يمكنك إعادة المحاولة."); setError(err); if (err.code === "BLOOD_BANK_VERSION_CONFLICT") { setConflict(true); setLatest(null); } } }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  const format = (key: string, value: string) => {
    if (["HBsAg", "HCV", "HIV"].includes(key)) return statuses[value] ?? "لم يُضف الفحص";
    if (key === "identity") return value === "direct" ? "بيانات مباشرة" : `مريض مسجل (${value.split(":")[1]})`;
    if (key === "clinic_id") return [clinic, latest?.clinic_id ? { id: latest.clinic_id, name_ar: latest.clinic_name } : null].find(c => String(c?.id) === value)?.name_ar ?? value;
    if (key === "responsible_staff_id") return [doctor, latest?.responsible_staff_id ? { id: latest.responsible_staff_id, name_ar: latest.doctor_name } : null].find(c => String(c?.id) === value)?.name_ar ?? value;
    return choices[key]?.[value] ?? value;
  };
  return <><Modal title={`${base ? "تعديل" : "إضافة"} ${kindName(kind)}`} onClose={onClose} busy={busy} size="wide"><form className={`${styles.form} ${layout.profileForm}`} onSubmit={save}>
    <p className={styles.scopeNote}>هذا ملف تسجيل فقط؛ لا ينشئ تبرعًا أو نقل دم. الكود يُنشأ تلقائيًا عند الحفظ.</p>
    {error && <p className={styles.error} role="alert">{conflict ? "تغيّرت بيانات الملف. مسودتك محفوظة؛ اجلب أحدث نسخة للمراجعة." : error.message}</p>}
    {conflict && <><button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة</button>{reloadError && <p role="alert">{reloadError}</p>}</>}
    {latest && <ConflictReview key={latest.lock_version} latest={draftOf(latest)} draft={{ ...draft, identity: patientMode ? `patient:${patientId ?? ""}` : "direct" }} labels={labels} format={format} onAccept={value => { setDraft(value); setBase(latest); setPatientMode(value.identity !== "direct"); if (value.identity !== "direct") { const id = Number(value.identity.split(":")[1]); setChosenPatient(id === latest.patient_id ? { id, name_ar: latest.name, code: latest.patient_code ?? "" } : chosenPatient); } setClinic(String(latest.clinic_id) === value.clinic_id ? { id: latest.clinic_id!, name_ar: latest.clinic_name ?? "" } : clinic); setDoctor(String(latest.responsible_staff_id) === value.responsible_staff_id ? { id: latest.responsible_staff_id!, name_ar: latest.doctor_name ?? "" } : doctor); setConflict(false); setLatest(null); setError(null); retry.current = null; }} />}
    <fieldset className={styles.fields} disabled={busy || conflict}>
      <div className={styles.sectionHeading}><span>01</span><h3>البيانات الشخصية</h3></div>
      {kind === "recipient" && <label className={styles.full}>هل المستفيد مريض مسجل في المشفى؟<select value={patientMode ? "yes" : "no"} onChange={e => setPatientMode(e.target.value === "yes")}><option value="no">لا، إدخال البيانات مباشرة</option><option value="yes" disabled={!options.capabilities.patients_search && !base?.patient_id}>نعم، اختيار مريض مسجل</option></select>{!options.capabilities.patients_search && <small>ربط مريض جديد يحتاج تفويض البحث في سجل المشفى.</small>}</label>}
      {patientMode ? <div className={styles.full}>{options.capabilities.patients_search && <Picker label="المريض المسجل" path={`blood-bank/patients?facility_id=${facilityId}`} selected={chosenPatient} onSelect={setChosenPatient} />}
        {patient.loading && <p role="status">جارٍ جلب بيانات المريض…</p>}{patient.error && <p role="alert">تعذّر جلب بيانات المريض. <button type="button" onClick={patient.retry}>إعادة المحاولة</button></p>}
        {person && <dl className={styles.facts}>{Object.entries(personLabels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{key === "governorate_id" ? String((patientId === base?.patient_id ? base?.governorate_name : person.governorate_name) ?? options.governorates.find(g => g.id === person[key])?.name_ar ?? "غير محدد") : key === "city_id" ? String((patientId === base?.patient_id ? base?.city_name : person.city_name) ?? cities.data?.find(c => c.id === person[key])?.name_ar ?? "غير محدد") : choices[key]?.[String(person[key])] ?? String(person[key] ?? "غير محدد")}</dd></div>)}</dl>}{fieldError("patient_id")}</div>
        : <>{Object.entries(personLabels).filter(([key]) => !["governorate_id", "city_id", "address_line", "displacement_status"].includes(key)).map(([key, label]) => <label key={key} className={key === "address_line" ? styles.full : undefined}>{label}{["first_name", "family_name"].includes(key) ? " *" : ""}
          {choices[key] ? <select aria-label={label} value={draft[key]} onChange={e => change(key, e.target.value)}>{Object.entries(choices[key]).map(([value, name]) => <option key={value} value={value}>{name}</option>)}</select>
            : <input aria-label={label} type={key === "birth_date" ? "date" : "text"} required={["first_name", "family_name"].includes(key)} maxLength={key === "address_line" ? 255 : key.includes("phone") ? 30 : key === "mother_name" ? 120 : 80} value={draft[key]} onChange={e => change(key, e.target.value)} />}{fieldError(key)}</label>)}</>}
      {!patientMode && <><div className={styles.sectionHeading}><span>02</span><h3>العنوان</h3></div>
        <AddressFields draft={draft} profile={base} options={options} cities={cities} change={change} fieldError={fieldError} />
        <label className={styles.full}>عنوان السكن<input aria-label="عنوان السكن" maxLength={255} value={draft.address_line} onChange={e => change("address_line", e.target.value)} />{fieldError("address_line")}</label>
        <label>حالة النزوح<select aria-label="حالة النزوح" value={draft.displacement_status} onChange={e => change("displacement_status", e.target.value)}>{Object.entries(choices.displacement_status).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select>{fieldError("displacement_status")}</label>
      </>}
      {kind === "recipient" && <label className={styles.full}>جهة المستفيد<input aria-label="جهة المستفيد" maxLength={200} value={draft.beneficiary_entity} onChange={e => change("beneficiary_entity", e.target.value)} />{fieldError("beneficiary_entity")}</label>}
      <div className={styles.sectionHeading}><span>03</span><h3>بيانات الدم</h3></div>
      <label>زمرة ABO<select aria-label="زمرة ABO" value={draft.blood_group} onChange={e => change("blood_group", e.target.value)}><option value="">غير معروفة</option>{["A", "B", "AB", "O"].map(g => <option key={g}>{g}</option>)}</select></label>
      <label>عامل Rh<select aria-label="عامل Rh" value={draft.rh} onChange={e => change("rh", e.target.value)}><option value="">غير معروف</option>{Object.entries(choices.rh).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select></label>
      <fieldset className={`${styles.picker} ${styles.full}`}><legend>نوع المكوّن</legend><div className={styles.actions}>
        {[{ id: 0, name_ar: "غير محدد" }, ...options.blood_components, ...(base?.blood_component_id && !options.blood_components.some(c => c.id === base.blood_component_id) ? [{ id: base.blood_component_id, name_ar: `${base.component_name ?? "المكوّن المسجل"} (قيمة سابقة)` }] : [])].map(c => <label className={styles.doctorChoice} key={c.id}><input type="radio" name="blood-component" checked={draft.blood_component_id === (c.id ? String(c.id) : "")} onChange={() => change("blood_component_id", c.id ? String(c.id) : "")} />{c.name_ar}</label>)}
      </div>{fieldError("blood_component_id")}</fieldset>
      <div className={styles.sectionHeading}><span>04</span><h3>العيادة والطبيب</h3></div>
      <div className={styles.full}><Picker label="العيادة" path={`blood-bank/clinics?facility_id=${facilityId}`} selected={clinic} onSelect={value => { setClinic(value); change("clinic_id", String(value.id)); if (value.id !== clinic?.id) { setDoctor(null); change("responsible_staff_id", ""); } }} />{fieldError("clinic_id")}</div>
      {clinic && <div className={styles.full}><Picker key={clinic.id} label="الطبيب المسؤول" emptyMessage="لا يوجد أطباء مؤهلون مرتبطون حاليًا بهذه العيادة يطابقون البحث." path={`blood-bank/doctors?facility_id=${facilityId}&clinic_id=${clinic.id}`} selected={doctor} onSelect={value => { setDoctor(value); change("responsible_staff_id", String(value.id)); }} />{fieldError("responsible_staff_id")}{options.can_add_doctor && <button type="button" className={styles.secondary} onClick={() => setInline(true)}>إضافة طبيب</button>}</div>}
      <div className={styles.sectionHeading}><span>05</span><h3>الفحوصات</h3></div>
      <ScreeningFields draft={draft} profile={base} change={change} fieldError={fieldError} />
    </fieldset><div className={styles.modalActions}><button className={styles.primary} disabled={busy || conflict || !draft.clinic_id || !draft.responsible_staff_id || (patientMode && (!person || patient.loading || !!patient.error))}>حفظ الملف</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>{inline && clinic && <InlineDoctor facilityId={facilityId} clinic={clinic} onClose={() => setInline(false)} onSelected={value => { setDoctor(value); change("responsible_staff_id", String(value.id)); setInline(false); }} />}</>;
}
