"use client";

import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuCheck, LuHospital, LuPlus, LuSave } from "react-icons/lu";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest, AuthError } from "../auth/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack } from "../directory/DirectoryPrimitives";
import { useClinicRequest } from "../clinics/api";
import Picker from "../blood-bank/Picker";
import ConflictReview from "../blood-bank/ConflictReview";
import { type Choice, choices } from "../blood-bank/api";
import { historyLabels, treatmentLabels, sourceLabels } from "./api";
import { type Fields, type Snapshot, type WizardOptions, type DiagnosisDraft, personalFields, medicalFields, visitFields, diagnosisFields, diagnosisPayload, personalLabels, medicalLabels, visitLabels } from "./wizard";
import DiagnosisEditor from "./DiagnosisEditor";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

const steps = ["البيانات الشخصية", "المعلومات الطبية والورمية", "الزيارة والتشخيصات", "الخدمات والإجراءات", "الأدوية والنتيجة", "المرفقات والمراجعة"];
const codes = ["personal", "medical", "visit"];
export default function DossierWizard({ id }: { id?: string }) {
  const { access, user } = useIdentity(); const params = useSearchParams();
  const { entry } = directoryFacility(access, "dossiers.view", params.get("facility_id"));
  if (!entry || (id && !/^[1-9]\d*$/.test(id))) return <section className={styles.status}><h2>تعذّر فتح الإضبارة</h2><p role="alert">اختر رابطًا صحيحًا لمشفى تملك صلاحية إضباراته، أو راجع مسؤول الصلاحيات.</p></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div><Loader key={`${user.id}:${entry.facility.id}:${id ?? "new"}`} facility={entry.facility.id} id={id} /></div>;
}
function Loader({ facility, id }: { facility: number; id?: string }) {
  const options = useClinicRequest<WizardOptions>(`dossiers/options?facility_id=${facility}`);
  const record = useClinicRequest<Snapshot>(id ? `dossiers/${id}/progress?facility_id=${facility}` : null);
  if (options.error || record.error) return <p role="alert">{options.error ?? record.error} <button className={styles.secondary} onClick={() => { options.retry(); record.retry(); }}>إعادة المحاولة</button></p>;
  if (!options.data || (id && !record.data)) return <p role="status">جارٍ تحميل الإضبارة والخيارات…</p>;
  if (!id && !options.data.creation.allowed) return <p role="alert">{options.data.creation.reason}</p>;
  return <WizardForm facility={facility} options={options.data} initial={record.data} />;
}
function WizardForm({ facility, options, initial }: { facility: number; options: WizardOptions; initial?: Snapshot }) {
  const params = useSearchParams(); const router = useRouter();
  const [base, setBase] = useState(initial); const [step, setStep] = useState(() => {
    if (!initial) return 0;
    const requested = params.get("section");
    return requested && /^[0-2]$/.test(requested) ? Number(requested) : initial.workflow.resume_section ?? 0;
  });
  const [sectionVersions, setSectionVersions] = useState([initial?.lock_version ?? 0, initial?.lock_version ?? 0]);
  const [patientVersion, setPatientVersion] = useState(initial?.patient.lock_version ?? 0);
  const [personal, setPersonal] = useState(() => personalFields(initial)); const [medical, setMedical] = useState(() => medicalFields(initial)); const [visit, setVisit] = useState(() => visitFields(initial)); const [diagnoses, setDiagnoses] = useState(() => diagnosisFields(initial));
  const [mode, setMode] = useState(options.capabilities.patients_search ? "existing" : "new"); const [patient, setPatient] = useState<(Choice & { dossier_id?: number | null }) | null>(null);
  const [busy, setBusy] = useState(false); const [saved, setSaved] = useState(""); const [dirty, setDirty] = useState<number[]>([]);
  const [error, setError] = useState<AuthError | null>(null); const [review, setReview] = useState<Snapshot | null>(null); const [reloadError, setReloadError] = useState("");
  const pending = useRef<AbortController | null>(null); const reservation = useRef<{ body: string; id: string } | null>(null); const form = useRef<HTMLFormElement>(null); const heading = useRef<HTMLHeadingElement>(null);
  const focusError = useRef(false);
  const caps = options.capabilities;
  useEffect(() => () => pending.current?.abort(), []);
  useEffect(() => { heading.current?.focus({ preventScroll: true }); }, [step]);
  useEffect(() => {
    if (!error || !focusError.current) return;
    focusError.current = false;
    const key = Object.keys(error.fields)[0];
    const el = key ? form.current?.querySelector<HTMLElement>(`[name="${CSS.escape(key)}"]`) : null;
    (el ?? form.current?.querySelector<HTMLElement>('[role="alert"]'))?.focus(); el?.scrollIntoView({ block: "center", behavior: "instant" });
  }, [error]);
  useEffect(() => {
    if (!dirty.length) return;
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); };
    window.addEventListener("beforeunload", warn); return () => window.removeEventListener("beforeunload", warn);
  }, [dirty.length]);
  const touch = () => { setDirty(v => v.includes(step) ? v : [...v, step]); setSaved(""); };
  const fieldError = (key: string) => error?.fields[key] ? <small className={layout.error} role="alert">{error.fields[key]}</small> : null;
  const changed = (setter: React.Dispatch<React.SetStateAction<Fields>>, key: string, value: string) => { touch(); setter(d => ({ ...d, [key]: value })); setError(previous => { if (previous?.status !== 422 || !previous.fields[key]) return previous; const fields = Object.fromEntries(Object.entries(previous.fields).filter(([k]) => k !== key)); return Object.keys(fields).length ? new AuthError(previous.status, previous.code, previous.message, fields) : null; }); };
  const go = (next: number) => { if (busy || (error?.status === 409) || review || (!base && next > 0) || next > 2) return; setStep(next); setError(null); };
  const back = new URLSearchParams(params.toString()); back.set("facility_id", String(facility)); back.delete("section");
  const listHref = `/dossiers?${back}`;
  const existingDossier = !base && mode === "existing" ? patient?.dossier_id ?? (error?.code === "DOSSIER_ALREADY_EXISTS" ? error.details.existing_dossier_id : null) : null;
  const chooseAnother = () => { setPatient(null); setError(null); reservation.current = null; touch(); };
  const allowed = step === 0 ? (base ? base.workflow.personal_update : options.creation.allowed && !existingDossier && (mode === "new" ? caps.patients_create : caps.patients_search)) : step === 1 ? base?.workflow.medical_update : !!base?.workflow.visit.action;
  const input = (fields: Fields, setter: React.Dispatch<React.SetStateAction<Fields>>, labels: Fields, key: string, type = "text", required = false) => <label key={key}>{labels[key]}{required && " *"}<input name={key} aria-label={labels[key]} aria-invalid={!!error?.fields[key]} type={type} value={fields[key] ?? ""} maxLength={type === "text" ? (key === "code" ? 60 : 200) : undefined} onChange={e => changed(setter, key, e.target.value)} />{fieldError(key)}</label>;
  async function save(exit: boolean) {
    if (pending.current || !allowed || review || error?.status === 409) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError(null); setSaved("");
    let body: Record<string, unknown>; let path: string;
    if (step === 0) {
      body = { code: personal.code, opening_date: personal.opening_date };
      if (!base) { body.person_mode = mode; if (mode === "existing") body.patient_id = patient?.id; }
      if (base || mode === "new") for (const key of Object.keys(personalLabels).filter(k => !["code", "opening_date"].includes(k))) body[key] = personal[key] || null;
      if (base) { body.lock_version = sectionVersions[0]; body.patient_lock_version = patientVersion; }
      path = base ? `dossiers/${base.id}/personal` : "dossiers";
    } else if (step === 1) {
      body = { disability_text: medical.disability_text || null, clinical_history: medical.clinical_history || null, is_oncology: medical.is_oncology ? medical.is_oncology === "yes" : undefined, lock_version: sectionVersions[1], confirm_hide_oncology: medical.confirm_hide_oncology === "yes" };
      if (medical.is_oncology === "yes") Object.assign(body, { history: JSON.parse(medical.history), treatment: JSON.parse(medical.treatment), previous_examinations: medical.previous_examinations || null, medication_source: medical.medication_source || null, other_organization: medical.medication_source === "other_organization" ? medical.other_organization || null : null });
      path = `dossiers/${base!.id}/medical`;
    } else {
      body = { visit_date: visit.visit_date, visit_type_id: visit.visit_type_id || null, is_referred: visit.is_referred === "yes", diagnoses: diagnoses.map(diagnosisPayload) };
      if (visit.is_referred === "yes") Object.assign(body, { referring_hospital: visit.referring_hospital, referral_date: visit.referral_date, referral_reason: visit.referral_reason });
      if (base!.visit) body.lock_version = base!.visit.lock_version;
      path = `dossiers/${base!.id}/visits${base!.visit ? `/${base!.visit.id}` : ""}`;
    }
    body.facility_id = facility;
    const signature = JSON.stringify({ path, body });
    if (reservation.current?.body !== signature) reservation.current = { body: signature, id: crypto.randomUUID() };
    body.request_id = reservation.current.id;
    try {
      const result = await apiRequest<Snapshot>(path, { method: step === 0 && !base || step === 2 && !base?.visit ? "POST" : "PUT", body: JSON.stringify(body), signal: controller.signal });
      if (controller.signal.aborted) return;
      // Versions of other unsaved sections must not advance behind their drafts.
      const next = { ...result };
      if (base && step === 2) next.lock_version = base.lock_version;
      if (base && step !== 2) { next.visit = base.visit; next.workflow = { ...result.workflow, visit: base.workflow.visit }; }
      setBase(next); setDirty(d => d.filter(n => n !== step)); setSaved("تم حفظ القسم كمسودة."); reservation.current = null;
      if (step !== 2) setSectionVersions(versions => versions.map((v, i) => !base || i === step || v === body.lock_version ? result.lock_version : v));
      if (step === 0) setPatientVersion(result.patient.lock_version);
      if (step === 0) setPersonal(personalFields(result));
      if (step === 1) setMedical(medicalFields(result));
      if (step === 2) { setVisit(visitFields(result)); setDiagnoses(diagnosisFields(result)); }
      const url = `/dossiers/${result.id}/edit?${back}`;
      if (!base) window.history.replaceState(null, "", url);
      if (exit) router.push(listHref);
      else if (step < 2) setStep(step + 1);
    } catch (e) { if (!controller.signal.aborted) { focusError.current = true; setError(e instanceof AuthError ? e : new AuthError(0, "SAVE_FAILED", "تعذّر الحفظ؛ المسودة محفوظة في هذه الصفحة.")); } }
    finally { pending.current = null; if (!controller.signal.aborted) setBusy(false); }
  }
  async function reload() {
    if (!base || pending.current) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setReloadError("");
    try { const latest = await apiRequest<Snapshot>(`dossiers/${base.id}/progress?facility_id=${facility}`, { signal: controller.signal }); if (!controller.signal.aborted) setReview(latest); }
    catch { if (!controller.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ المسودة باقية. حاول مجددًا."); }
    finally { pending.current = null; if (!controller.signal.aborted) setBusy(false); }
  }
  const state = (index: number) => index > 2 ? "unavailable" : index === step && error ? "error" : dirty.includes(index) ? "in_progress" : base?.progress.find(p => p.section === codes[index])?.state ?? "not_started";
  return <div className={layout.wizard}>
    <DirectoryBack href={listHref}>العودة إلى الإضبارات</DirectoryBack>
    <header className={styles.heading}><div><h2>{base ? `استكمال الإضبارة ${base.code}` : "إضافة إضبارة"}</h2><p>احفظ كل قسم، وتابع عندما تكون جاهزًا.</p></div>{base && <span className={styles.badge}>{base.status === "draft" ? "مسودة" : "فعالة"}</span>}</header>
    <ol className={layout.progress} aria-label="مراحل الإضبارة">{steps.map((label, i) => <li key={label}><button type="button" aria-current={i === step ? "step" : undefined} data-state={state(i)} disabled={i > 2 || (!base && i > 0) || busy || !!review || error?.status === 409} onClick={() => go(i)}><span aria-hidden="true">{state(i) === "saved" ? <LuCheck /> : i + 1}</span><strong>{label}</strong><small>{i > 2 ? "المرحلة التالية" : i === step ? "القسم الحالي" : state(i) === "saved" ? "محفوظ" : state(i) === "in_progress" ? "قيد الاستكمال" : "لم يبدأ بعد"}</small></button></li>)}</ol>
    <div className={layout.summary}>{base ? <><div><strong>{base.patient.first_name} {base.patient.family_name}</strong><p>كود المريض <bdi>{base.patient.patient_code}</bdi> · الإضبارة <bdi>{base.code}</bdi></p></div><span className={styles.hint}>هوية المريض مرتبطة؛ لا يمكن استبدالها من المعالج.</span></> : <p>لم يُنشأ ملف بعد. يبدأ حفظ الإضبارة عند حفظ البيانات الشخصية بنجاح.</p>}</div>
    <form ref={form} className={`${styles.form} ${layout.form}`} noValidate onSubmit={e => { e.preventDefault(); void save(false); }}>
      {error && <div role="alert" tabIndex={-1} className={styles.conflict}><strong>{error.code === "DOSSIER_ALREADY_EXISTS" ? "للمريض إضبارة في هذا المشفى؛ مسودتك لم تُحفظ ولم تُفقد." : error.message}</strong>{error.status === 409 && error.code !== "DOSSIER_ALREADY_EXISTS" && <><p>لن تُستبدل البيانات تلقائيًا. اجلب النسخة الحالية لاختيار ما تريد تطبيقه من مسودتك.</p><button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة</button></>}{reloadError && <p>{reloadError}</p>}</div>}
      {review && <WizardConflict step={step} latest={review} personal={personal} medical={medical} visit={visit} diagnoses={diagnoses} onAccept={(fields, rows) => { if (step !== 2) setSectionVersions(v => v.map((version, i) => i === step ? review.lock_version : version)); if (step === 0) setPatientVersion(review.patient.lock_version); if (step === 0) setPersonal(fields); else if (step === 1) setMedical(fields); else { setVisit(fields); setDiagnoses(rows ?? []); } setBase(previous => ({ ...review, ...(step === 2 && previous ? { lock_version: previous.lock_version } : {}), ...(step !== 2 && previous ? { visit: previous.visit, workflow: { ...review.workflow, visit: previous.workflow.visit } } : {}) })); setReview(null); setError(null); reservation.current = null; touch(); }} />}
      <fieldset disabled={busy || !!review} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
        <section className={layout.section}><div><h3 ref={heading} tabIndex={-1}>{steps[step]}</h3><p className={styles.hint}>الحقول المعلّمة بنجمة مطلوبة. تبقى الأقسام اللاحقة محفوظة عند العودة.</p></div>
          {step === 0 && <>
            <div className={styles.fields}>{input(personal, setPersonal, personalLabels, "code", "text", true)}{input(personal, setPersonal, personalLabels, "opening_date", "date", true)}</div>
            {!base && <><fieldset className={styles.picker}><legend>بيانات المريض</legend><div className={layout.checkGroup}>{[["existing", "اختيار مريض موجود", caps.patients_search], ["new", "تسجيل مريض جديد", caps.patients_create]].map(([value, label, enabled]) => <label key={String(value)}><input type="radio" name="person_mode" disabled={!enabled} checked={mode === value} onChange={() => { setMode(String(value)); if (error?.code === "DOSSIER_ALREADY_EXISTS") setError(null); touch(); }} />{label}</label>)}</div></fieldset>{mode === "existing" && <><Picker name="patient_id" label="المريض الموجود" path={`dossiers/options/patients?facility_id=${facility}`} selected={patient} onSelect={p => { setPatient(p); if (error?.code === "DOSSIER_ALREADY_EXISTS") setError(null); touch(); }} />{fieldError("patient_id")}{existingDossier && <div className={styles.conflict}><p>للمريض إضبارة في هذا المشفى. لن يُرسل كود الإضبارة الجديدة أو تاريخها.</p><div className={styles.actions}><Link className={styles.primary} href={`/dossiers/${existingDossier}/edit?${back}`}>فتح الإضبارة الموجودة</Link><button type="button" className={styles.secondary} onClick={chooseAnother}>اختيار مريض آخر</button></div></div>}</>}</>}
            {(base || mode === "new") && <div className={styles.fields}>
              {["first_name", "family_name", "father_name", "mother_name"].map(k => input(personal, setPersonal, personalLabels, k, "text", ["first_name", "family_name"].includes(k)))}
              {input(personal, setPersonal, personalLabels, "birth_date", "date")}
              {["birth_date_accuracy", "gender"].map(key => <label key={key}>{personalLabels[key]}<select name={key} value={personal[key]} onChange={e => changed(setPersonal, key, e.target.value)}>{Object.entries(choices[key]).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select>{fieldError(key)}</label>)}
              <h4 className={layout.subheading}>التواصل والسكن</h4>{["phone", "alt_phone"].map(k => input(personal, setPersonal, personalLabels, k, "tel"))}
              <div><Picker name="governorate_id" label="المحافظة السورية" choices={options.governorates} selected={options.governorates.find(g => String(g.id) === personal.governorate_id)} onSelect={g => { touch(); setPersonal(p => ({ ...p, governorate_id: String(g.id), city_id: p.governorate_id === String(g.id) ? p.city_id : "" })); }} />{personal.governorate_id && <button type="button" className={styles.secondary} onClick={() => { touch(); setPersonal(p => ({ ...p, governorate_id: "", city_id: "" })); }}>إلغاء المحافظة والمدينة</button>}{fieldError("governorate_id")}</div>
              <div>{personal.governorate_id && <Picker key={personal.governorate_id} name="city_id" label="المدينة التابعة للمحافظة" path={`dossiers/options/cities?facility_id=${facility}&governorate_id=${personal.governorate_id}`} selected={personal.city_id ? { id: Number(personal.city_id), name_ar: "المدينة المحددة" } : null} onSelect={c => changed(setPersonal, "city_id", String(c.id))} />}{fieldError("city_id")}</div>
              <label className={styles.full}>عنوان السكن<input name="address_line" value={personal.address_line} onChange={e => changed(setPersonal, "address_line", e.target.value)} maxLength={255} /><small className={styles.hint}>للعنوان خارج سوريا، اكتب المحافظة والمدينة هنا واترك اختيارات الدليل فارغة.</small>{fieldError("address_line")}</label>
              <label>حالة النزوح<select name="displacement_status" value={personal.displacement_status} onChange={e => changed(setPersonal, "displacement_status", e.target.value)}>{Object.entries(choices.displacement_status).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select></label>
            </div>}
          </>}
          {step === 1 && <>
            <div className={styles.fields}><label>معلومات الإعاقة<textarea name="disability_text" value={medical.disability_text} onChange={e => changed(setMedical, "disability_text", e.target.value)} rows={4} />{fieldError("disability_text")}</label><label>القصة المرضية المختصرة<textarea name="clinical_history" value={medical.clinical_history} onChange={e => changed(setMedical, "clinical_history", e.target.value)} rows={4} />{fieldError("clinical_history")}</label></div>
            <label>هل المريض ورمي؟ *<select name="is_oncology" value={medical.is_oncology} onChange={e => changed(setMedical, "is_oncology", e.target.value)}><option value="">اختر نعم أو لا</option><option value="yes">نعم</option><option value="no">لا</option></select>{fieldError("is_oncology")}</label>
            {medical.is_oncology === "no" && !!base?.medical.is_oncology && <label className={layout.checkGroup}><input name="confirm_hide_oncology" type="checkbox" checked={medical.confirm_hide_oncology === "yes"} onChange={e => changed(setMedical, "confirm_hide_oncology", e.target.checked ? "yes" : "no")} />أؤكد الاحتفاظ بالبيانات الورمية تاريخيًا وإخفاءها من العرض الحالي.{fieldError("confirm_hide_oncology")}</label>}
            {medical.is_oncology === "yes" && <><div className={styles.fields}>{[["history", "أنواع السوابق", historyLabels], ["treatment", "أنواع العلاج السابق", treatmentLabels]].map(([key, title, labels]) => <fieldset key={String(key)} className={styles.picker}><legend>{String(title)}</legend><div className={layout.checkGroup}>{Object.entries(labels as Fields).map(([v, name]) => <label key={v}><input type="checkbox" checked={(JSON.parse(medical[String(key)]) as string[]).includes(v)} onChange={e => { const list = JSON.parse(medical[String(key)]) as string[]; changed(setMedical, String(key), JSON.stringify(e.target.checked ? [...list, v] : list.filter(x => x !== v))); }} />{name}</label>)}</div></fieldset>)}</div><label>الفحوص السابقة<textarea name="previous_examinations" rows={4} value={medical.previous_examinations} onChange={e => changed(setMedical, "previous_examinations", e.target.value)} />{fieldError("previous_examinations")}</label><div className={styles.fields}><label>مصدر الدواء العام<select name="medication_source" value={medical.medication_source} onChange={e => changed(setMedical, "medication_source", e.target.value)}><option value="">غير مسجل</option>{Object.entries(sourceLabels).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select></label>{medical.medication_source === "other_organization" && input(medical, setMedical, medicalLabels, "other_organization", "text", true)}</div></>}
          </>}
          {step === 2 && <>
            <p className={styles.hint}>تُحفظ زيارة فعلية مسودة بتاريخها، دون اشتراط فترة تقارير. يمكن حفظها قبل إضافة التشخيصات.</p>
            <div className={styles.fields}>{input(visit, setVisit, visitLabels, "visit_date", "date", true)}<label>نوع الزيارة *<select name="visit_type_id" value={visit.visit_type_id} onChange={e => changed(setVisit, "visit_type_id", e.target.value)}><option value="">اختر نوع الزيارة</option>{options.visit_types.map(t => <option key={t.id} value={t.id}>{t.name_ar}</option>)}{visit.visit_type_id && !options.visit_types.some(t => String(t.id) === visit.visit_type_id) && <option value={visit.visit_type_id}>نوع الزيارة المحفوظ سابقًا</option>}</select>{fieldError("visit_type_id")}</label></div>
            <label>هل المريض محال من مشفى آخر؟<select name="is_referred" value={visit.is_referred} onChange={e => changed(setVisit, "is_referred", e.target.value)}><option value="no">لا</option><option value="yes">نعم</option></select></label>
            {visit.is_referred === "yes" && <div className={styles.fields}>{input(visit, setVisit, visitLabels, "referring_hospital", "text", true)}{input(visit, setVisit, visitLabels, "referral_date", "date", true)}<label className={styles.full}>سبب الإحالة *<textarea name="referral_reason" rows={3} value={visit.referral_reason} onChange={e => changed(setVisit, "referral_reason", e.target.value)} />{fieldError("referral_reason")}</label></div>}
            <div><h4>التشخيصات</h4><p className={styles.hint}>لكل تشخيص عيادته وطبيبه؛ لا يوجد تشخيص رئيسي. الارتباط الجديد يجب أن يغطي تاريخ الزيارة.</p>{!diagnoses.length && <p>لم تُسجّل تشخيصات بعد؛ هذا القسم غير مكتمل.</p>}{fieldError("diagnoses")}</div>
            {diagnoses.map((row, index) => <DiagnosisEditor key={row.key} row={row} index={index} facility={facility} date={visit.visit_date} canCreate={!!caps.diagnoses_create} change={value => { touch(); setDiagnoses(items => items.map(d => d.key === row.key ? value : d)); }} remove={() => { touch(); setDiagnoses(items => items.filter(d => d.key !== row.key)); }} error={fieldError} />)}
            <div><button type="button" className={styles.secondary} onClick={() => { touch(); setDiagnoses(items => [...items, { key: `new-${crypto.randomUUID()}`, diagnosis: null, clinic: null, doctor: null, diagnosed_on: "" }]); }}><LuPlus aria-hidden="true" />إضافة تشخيص للزيارة</button></div>
          </>}
        </section>
      </fieldset>
      <div className={layout.actions}><span role="status">{busy ? "جارٍ الحفظ أو جلب البيانات…" : saved || (dirty.includes(step) ? "توجد تغييرات لم تُحفظ." : "الحفظ لا يفعّل الإضبارة ولا يُكمل الزيارة.")}</span>{step > 0 && <button type="button" className={styles.secondary} disabled={busy || !!review || error?.status === 409} onClick={() => go(step - 1)}>السابق</button>}<button type="button" className={styles.secondary} disabled={!allowed || busy || !!review || error?.status === 409} onClick={() => void save(true)}>حفظ كمسودة والخروج</button><button type="submit" className={styles.primary} disabled={!allowed || busy || !!review || error?.status === 409}><LuSave aria-hidden="true" />{step === 2 ? "حفظ الزيارة كمسودة" : "حفظ ومتابعة"}</button></div>
      {!allowed && !existingDossier && <p className={styles.hint}>لا تتوفر صلاحية حفظ هذا القسم أو أن حالته لا تسمح بالتعديل.</p>}
    </form>
  </div>;
}
function WizardConflict({ step, latest, personal, medical, visit, diagnoses, onAccept }: { step: number; latest: Snapshot; personal: Fields; medical: Fields; visit: Fields; diagnoses: DiagnosisDraft[]; onAccept: (fields: Fields, rows?: DiagnosisDraft[]) => void }) {
  const currentRows = diagnosisFields(latest);
  const rowValues = (rows: DiagnosisDraft[]) => Object.fromEntries(rows.map(r => [`row:${r.key}`, JSON.stringify({ ...r, lock_version: undefined })]));
  const latestFields = step === 0 ? personalFields(latest) : step === 1 ? medicalFields(latest) : { ...visitFields(latest), ...rowValues(currentRows) };
  const retainedDraft = diagnoses.filter(r => !r.id || currentRows.some(c => c.id === r.id));
  const draft = step === 0 ? { ...personal } : step === 1 ? { ...medical } : { ...visit, ...rowValues(retainedDraft) };
  const labels = step === 0 ? personalLabels : step === 1 ? medicalLabels : { ...visitLabels, ...Object.fromEntries([...currentRows, ...retainedDraft].map((r, index) => [`row:${r.key}`, `تشخيص: ${r.diagnosis?.name_ar ?? index + 1} · ${r.clinic?.name_ar ?? "عيادة غير محددة"}`])) };
  // Missing draft entries are not deletion instructions; keep current saved rows.
  for (const [key, value] of Object.entries(latestFields)) if (!(key in draft)) draft[key] = value;
  const format = (key: string, value: string) => {
    if (key.startsWith("row:")) { const r = JSON.parse(value) as DiagnosisDraft; return `${r.diagnosis?.name_ar ?? "غير محدد"} · ${r.clinic?.name_ar ?? "غير محددة"} · ${r.doctor?.name_ar ?? "غير محدد"} · ${r.diagnosed_on || "تاريخ غير معروف"}${r.remove ? ` · إزالة: ${r.void_reason ?? ""}` : ""}`; }
    if (["history", "treatment"].includes(key)) return (JSON.parse(value) as string[]).map(v => (key === "history" ? historyLabels : treatmentLabels)[v]).join("، ");
    return value === "yes" ? "نعم" : value === "no" ? "لا" : sourceLabels[value] ?? value;
  };
  return <><ConflictReview key={`${latest.lock_version}:${latest.visit?.lock_version}`} latest={latestFields} draft={draft} labels={labels} format={format} onAccept={value => { const rows = Object.entries(value).filter(([key, data]) => key.startsWith("row:") && data).map(([, data]) => { const r = JSON.parse(data) as DiagnosisDraft; return { ...r, lock_version: currentRows.find(c => c.id === r.id)?.lock_version }; }); onAccept(Object.fromEntries(Object.entries(value).filter(([k]) => !k.startsWith("row:"))), rows); }} />{step === 2 && diagnoses.some(r => r.id && !currentRows.some(c => c.id === r.id)) && <p role="status">أزيلت بعض التشخيصات في النسخة الحالية؛ لن تُعاد تلقائيًا. يمكنك مراجعتها وإضافتها صراحة بعد اعتماد المراجعة.</p>}</>;
}
