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
import Picker from "./DossierPicker";
import ConflictReview from "../blood-bank/ConflictReview";
import { type Choice } from "../blood-bank/api";
import { historyLabels, treatmentLabels, sourceLabels } from "./api";
import { type Fields, type Snapshot, type WizardOptions, type DiagnosisDraft, personalFields, medicalFields, visitFields, diagnosisFields, diagnosisPayload, personalLabels, personalChoices, medicalLabels, visitLabels } from "./wizard";
import ExistingCardChooser from "./ExistingCardChooser";
import WorkspaceClinical from "./WorkspaceClinical";
import DiagnosisEditor from "./DiagnosisEditor";
import ClinicalEditor from "./ClinicalEditor";
import ClinicalConflict from "./ClinicalConflict";
import FinalReview from "./FinalReview";
import { clinicalDraft, clinicalPayload } from "./clinical";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

const steps = ["البيانات الشخصية", "المعلومات الطبية والورمية", "الزيارة والتشخيصات", "الخدمات والإجراءات", "أدوية الزيارة والنتيجة", "المرفقات ثم المراجعة", "التقييم والتشريح المرضي", "الخطة ومواعيد العلاج", "تسجيل الجرعات"];
const codes = ["personal", "medical", "visit", "clinical", "medications", "attachments"];
export default function DossierWizard({ id: legacyId }: { id?: string }) {
  const { access, user } = useIdentity(); const params = useSearchParams();
  const id = legacyId ?? params.get("card") ?? undefined;
  const selectedVisit = params.get("visit");
  const { entry } = directoryFacility(access, "dossiers.view", params.get("facility_id"));
  if (!entry || (id && !/^[1-9]\d*$/.test(id)) || (selectedVisit && (selectedVisit !== "new" && !/^[1-9]\d*$/.test(selectedVisit) || !id))) return <section className={styles.status}><h2>تعذّر فتح بطاقة المريض</h2><p role="alert">اختر رابطًا صحيحًا لمشفى تملك صلاحية بطاقات مرضاه، أو راجع مسؤول الصلاحيات.</p></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div><Loader key={`${user.id}:${entry.facility.id}:${id ?? "new"}`} facility={entry.facility.id} id={id} /></div>;
}
function Loader({ facility, id }: { facility: number; id?: string }) {
  const query=useSearchParams(); const selected=query.get("visit");
  const route=selected === "new" ? "visits/new" : selected && /^[1-9]\d*$/.test(selected) ? `visits/${selected}/progress` : "progress";
  const options = useClinicRequest<WizardOptions>(`dossiers/options?facility_id=${facility}`);
  const record = useClinicRequest<Snapshot>(id ? `dossiers/${id}/${route}?facility_id=${facility}` : null);
  if (options.error || record.error) return <p role="alert">{options.error ?? record.error} <button className={styles.secondary} onClick={() => { options.retry(); record.retry(); }}>إعادة المحاولة</button></p>;
  if (!options.data || (id && !record.data)) return <p role="status">جارٍ تحميل بطاقة المريض والخيارات…</p>;
  if (!id && query.get("intent") !== "visit" && !options.data.creation.allowed) return <p role="alert">{options.data.creation.reason}</p>;
  if (!id && query.get("intent") === "visit") return <ExistingCardChooser facility={facility} />;
  return <WizardForm key={`${id??"new"}:${selected??"initial"}`} facility={facility} options={options.data} initial={record.data} />;
}
function WizardForm({ facility, options, initial }: { facility: number; options: WizardOptions; initial?: Snapshot }) {
  const params = useSearchParams(); const router = useRouter();
  const [base, setBase] = useState(initial); const [step, setStep] = useState(() => {
    if (!initial) return 0;
    const requested = params.get("section");
    if (!initial.visit && requested && Number(requested) > 2) return 2;
    if (initial.visit?.dossier_visit_kind==="subsequent" || params.get("visit")==="new") return requested && /^[2-8]$/.test(requested)?Number(requested):2;
    return requested && /^[0-8]$/.test(requested) ? Number(requested) : initial.workflow.resume_section ?? 0;
  });
  const [sectionVersions, setSectionVersions] = useState([initial?.lock_version ?? 0, initial?.lock_version ?? 0, ...Array<number>(4).fill(initial?.visit?.lock_version ?? 0)]);
  const [clinical,setClinical]=useState(()=>clinicalDraft(initial));
  const [medicationPane,setMedicationPane]=useState<"prescription"|"unlinked"|"outside">("prescription");
  const [reviewPane,setReviewPane]=useState(false);
  const [tools,setTools]=useState<number[]>([]);
  const [clinicalRevision,setClinicalRevision]=useState(0);
  const [uploadsPending,setUploadsPending]=useState(false);
  const [reviewed,setReviewed]=useState(false);
  const subsequent=initial?.visit?.dossier_visit_kind === "subsequent" || (params.get("visit")==="new" && initial?.status==="active");
  const [patientVersion, setPatientVersion] = useState(initial?.patient.lock_version ?? 0);
  const [personal, setPersonal] = useState(() => personalFields(initial)); const [medical, setMedical] = useState(() => medicalFields(initial)); const [visit, setVisit] = useState(() => visitFields(initial)); const [diagnoses, setDiagnoses] = useState(() => diagnosisFields(initial));
  const [mode, setMode] = useState(options.capabilities.patients_create ? "new" : "existing"); const [patient, setPatient] = useState<(Choice & { dossier_id?: number | null }) | null>(null);
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
    for(let parent=el?.parentElement;parent;parent=parent.parentElement){if(parent instanceof HTMLDetailsElement)parent.open=true;}
    (el ?? form.current?.querySelector<HTMLElement>('[role="alert"]'))?.focus(); el?.scrollIntoView({ block: "center", behavior: "instant" });
  }, [error]);
  useEffect(() => {
    if (!dirty.length&&!uploadsPending) return;
    const warn = (e: BeforeUnloadEvent) => { e.preventDefault(); };
    const navigate=(e:MouseEvent)=>{const a=(e.target as Element)?.closest("a[href]");if(a&&!window.confirm("توجد تغييرات أو ملفات لم تُحفظ. هل تريد مغادرة المسودة؟")){e.preventDefault();e.stopPropagation();}};
    // Same-document Back/Forward does not dispatch beforeunload.
    const historyNavigation = (window as Window & { navigation?: EventTarget }).navigation;
    const traverse = (event: Event) => {
      if ((event as Event & { navigationType?: string }).navigationType === "traverse" && event.cancelable &&
        !window.confirm("توجد تغييرات أو ملفات لم تُحفظ. هل تريد مغادرة المسودة؟")) event.preventDefault();
    };
    historyNavigation?.addEventListener("navigate", traverse);
    window.addEventListener("beforeunload", warn);document.addEventListener("click",navigate,true);
    return () => {historyNavigation?.removeEventListener("navigate", traverse);window.removeEventListener("beforeunload", warn);document.removeEventListener("click",navigate,true);};
  }, [dirty.length,uploadsPending]);
  const touch = () => { setDirty(v => v.includes(step) ? v : [...v, step]); setSaved(""); };
  const fieldError = (key: string) => error?.fields[key] ? <small className={layout.error} role="alert">{error.fields[key]}</small> : null;
  const changed = (setter: React.Dispatch<React.SetStateAction<Fields>>, key: string, value: string) => { touch(); setter(d => ({ ...d, [key]: value })); setError(previous => { if (previous?.status !== 422 || !previous.fields[key]) return previous; const fields = Object.fromEntries(Object.entries(previous.fields).filter(([k]) => k !== key)); return Object.keys(fields).length ? new AuthError(previous.status, previous.code, previous.message, fields) : null; }); };
  const go = (next: number) => { if (busy || (error?.status === 409) || review || (!base && next > 0) || next > 8 || (next>2&&!base?.visit) || (subsequent&&next<2)) return; setStep(next); if(next>5)setTools(v=>v.includes(next)?v:[...v,next]); setError(null); };
  const back = new URLSearchParams(params.toString()); back.set("facility_id", String(facility)); back.delete("section"); back.delete("visit"); back.delete("card"); back.delete("intent");
  const listHref = `${params.get("return_to")==="visits"?"/visits":"/patient-cards"}?${back}`;
  const existingDossier = !base && mode === "existing" ? patient?.dossier_id ?? (error?.code === "DOSSIER_ALREADY_EXISTS" ? error.details.existing_dossier_id : null) : null;
  const chooseAnother = () => { setPatient(null); setError(null); reservation.current = null; touch(); };
  const allowed = step === 0 ? (base ? base.workflow.personal_update : options.creation.allowed && !existingDossier && (mode === "new" ? caps.patients_create : caps.patients_search)) : step === 1 ? base?.workflow.medical_update : step===2 ? !!base?.workflow.visit.action : !!base?.workflow.sections?.[step];
  const input = (fields: Fields, setter: React.Dispatch<React.SetStateAction<Fields>>, labels: Fields, key: string, type = "text", required = false) => <label key={key}>{labels[key]}{required && " *"}<input name={key} aria-label={labels[key]} aria-invalid={!!error?.fields[key]} type={type} value={fields[key] ?? ""} maxLength={type === "text" ? (key === "code" ? 40 : key === "occupation" ? 120 : 200) : undefined} onChange={e => changed(setter, key, e.target.value)} />{fieldError(key)}</label>;
  async function save(exit: boolean) {
    if (step>5 || pending.current || !allowed || review || error?.status === 409 || uploadsPending || (step===5&&dirty.some(n=>n!==5))) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError(null); setSaved("");
    let body: Record<string, unknown>={}; let path: string="";
    if (step === 0) {
      body = { opening_date: personal.opening_date };
      if (base || mode === "new") body.code = personal.code;
      if (!base) Object.assign(body, { visit_date: visit.visit_date });
      if (!base) { body.person_mode = mode; if (mode === "existing") body.patient_id = patient?.id; }
      if (base || mode === "new") for (const key of Object.keys(personalLabels).filter(k => !["code", "opening_date"].includes(k))) body[key] = personal[key] || null;
      if (base) { body.lock_version = sectionVersions[0]; body.patient_lock_version = patientVersion; }
      path = base ? `dossiers/${base.id}/personal` : "dossiers";
    } else if (step === 1) {
      body = { disability_text: medical.disability_text || null, clinical_history: medical.clinical_history || null, is_oncology: medical.is_oncology ? medical.is_oncology === "yes" : undefined, lock_version: sectionVersions[1], confirm_hide_oncology: medical.confirm_hide_oncology === "yes" };
      if (medical.is_oncology === "yes") Object.assign(body, { history: JSON.parse(medical.history), treatment: JSON.parse(medical.treatment), previous_examinations: medical.previous_examinations || null, medication_source: medical.medication_source || null, other_organization: medical.medication_source === "other_organization" ? medical.other_organization || null : null });
      path = `dossiers/${base!.id}/medical`;
    } else if (step===2) {
      body = { visit_date: visit.visit_date, is_referred: visit.is_referred === "yes", diagnoses: diagnoses.map(diagnosisPayload) };
      if (visit.is_referred === "yes") Object.assign(body, { referring_hospital: visit.referring_hospital, referral_date: visit.referral_date, referral_reason: visit.referral_reason });
      if (base!.visit) body.lock_version = sectionVersions[2];
      path = `dossiers/${base!.id}/visits${base!.visit ? `/${base!.visit.id}` : subsequent ? "/subsequent" : ""}`;
    }
    if(step>=3){body={...(step===5?{confirmed:reviewed}:clinicalPayload(clinical,step)),lock_version:sectionVersions[step]};path=`dossiers/${base!.id}/visits/${base!.visit!.id}/${step===5?"review":codes[step]}`;}
    body.facility_id = facility;
    const signature = JSON.stringify({ path, body });
    if (reservation.current?.body !== signature) reservation.current = { body: signature, id: crypto.randomUUID() };
    body.request_id = reservation.current.id;
    try {
      const result = await apiRequest<Snapshot>(path, { method: step === 5 || step === 0 && !base || step === 2 && !base?.visit ? "POST" : "PUT", body: JSON.stringify(body), signal: controller.signal });
      if (controller.signal.aborted) return;
      acceptServer(result,step);
      setDirty(d => d.filter(n => n !== step)); setSaved("تم حفظ القسم كمسودة."); reservation.current = null;
      if (step === 0) setPatientVersion(result.patient.lock_version);
      if (step === 0) setPersonal(personalFields(result));
      if (step === 1) setMedical(medicalFields(result));
      if (step === 2) { setVisit(visitFields(result)); setDiagnoses(diagnosisFields(result)); }
      const url = `/patient-cards/new?${back}&card=${result.id}`;
      if (!base) window.history.replaceState(null, "", `${url}&section=1`);
      if(subsequent&&!base?.visit)window.history.replaceState(null,"",`${url}&visit=${result.visit!.id}&section=3`);
      if (exit&&!dirty.some(n=>n!==step)) router.push(listHref);
      else if(exit)setSaved("حُفظ هذا القسم؛ احفظ التغييرات في الأقسام الأخرى قبل الخروج.");
      else if (step < 5) setStep(step + 1);
    } catch (e) { if (!controller.signal.aborted) { focusError.current = true; setError(e instanceof AuthError ? e : new AuthError(0, "SAVE_FAILED", "تعذّر الحفظ؛ المسودة محفوظة في هذه الصفحة.")); } }
    finally { pending.current = null; if (!controller.signal.aborted) setBusy(false); }
  }
  function acceptServer(result:Snapshot,savedStep:number){
    setBase(result);
    setSectionVersions(versions=>versions.map((v,i)=>i===savedStep||!dirty.includes(i)?(i<2?result.lock_version:result.visit?.lock_version??0):v));
    if(savedStep===0||!dirty.includes(0))setPersonal(personalFields(result));
    if(savedStep===1||!dirty.includes(1))setMedical(medicalFields(result));
    if(savedStep===2||!dirty.includes(2)){setVisit(visitFields(result));setDiagnoses(diagnosisFields(result));}
    const latest=clinicalDraft(result);setClinical(old=>({...old,...(savedStep===3||!dirty.includes(3)?{services:latest.services,procedures:latest.procedures}:{}),...(savedStep===4||!dirty.includes(4)?{prescription:latest.prescription,outcome:latest.outcome}:{})}));
  }
  async function reload() {
    if (!base || pending.current) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setReloadError("");
    try { const latest = await apiRequest<Snapshot>(`dossiers/${base.id}/${base.visit?`visits/${base.visit.id}/progress`:subsequent?"visits/new":"progress"}?facility_id=${facility}`, { signal: controller.signal }); if (!controller.signal.aborted) setReview(latest); }
    catch { if (!controller.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ المسودة باقية. حاول مجددًا."); }
    finally { pending.current = null; if (!controller.signal.aborted) setBusy(false); }
  }
  const state = (index: number) => index === step && error ? "error" : dirty.includes(index) ? "in_progress" : base?.progress.find(p => p.section === codes[index])?.state ?? "not_started";
  return <div className={layout.wizard}>
    <DirectoryBack href={listHref}>{params.get("return_to")==="visits"?"العودة إلى الزيارات":"العودة إلى بطاقات المرضى"}</DirectoryBack>
    <header className={styles.heading}><div><h2>{base ? `مساحة عمل المريض · ${base.code}` : "تسجيل بطاقة المريض وزيارته الأولى"}</h2><p>بيانات البطاقة ثابتة للمريض؛ التشخيص والخدمات والعلاج تخص الزيارة المحددة.</p></div>{base && <span className={styles.badge}>{base.status === "draft" ? "مسودة" : "فعالة"}</span>}</header>
    {!base && <nav className={layout.entryModes} aria-label="عملية التسجيل"><span aria-current="page">بطاقة جديدة وزيارتها الأولى</span><Link href={`/patient-cards/new?facility_id=${facility}&intent=visit&return_to=visits`}>زيارة لبطاقة موجودة</Link></nav>}
    <label className={layout.mobileStages}>القسم الحالي<select value={step} onChange={e=>go(Number(e.target.value))}>{steps.map((label,i)=>(!(subsequent&&i<2)&&(i<6||!!base?.visit&&(i===6||!!caps.treatment_view&&(!!base.medical.is_oncology||step===i||i===8)))&&<option key={i} value={i} disabled={(!base&&i>0)||(i>2&&!base?.visit)||(i>5&&(dirty.length>0||uploadsPending))}>{label}</option>))}</select></label>
    <div className={layout.workbench}><nav className={layout.stageRail} aria-label="مراحل مساحة العمل">
    {[{title:"بطاقة المريض",indices:[0,1],tone:"card"},{title:"الزيارة الحالية",indices:[2,3,4],tone:"visit"},{title:"التقييم والعلاج",indices:[6,...(caps.treatment_view&&(base?.medical.is_oncology||step>6)?[7,8]:[])],tone:"treatment"},{title:"المراجعة والحفظ",indices:[5],tone:"review"}].map(group=><section key={group.tone} data-tone={group.tone}><h3>{group.title}</h3><ol className={layout.progress} aria-label={group.title}>{group.indices.filter(i=>!(subsequent&&i<2)&&(!(i>5)||!!base?.visit)).map(i=><li key={i}><button type="button" aria-current={i===step?"step":undefined} data-state={state(i)} disabled={(i>2&&!base?.visit)||(!base&&i>0)||busy||!!review||error?.status===409||(i>5&&(dirty.length>0||uploadsPending))} onClick={()=>go(i)}><span aria-hidden="true">{state(i)==="saved"?<LuCheck/>:i>5?"＋":i+1}</span><strong>{steps[i]}</strong><small>{i===step?"القسم الحالي":dirty.includes(i)?"تعديلات غير محفوظة":state(i)==="saved"?"محفوظ":i>5?"حسب حاجة المريض":"متاح للاستكمال"}</small></button></li>)}</ol></section>)}
    </nav><div className={layout.workContent}>
    <div className={layout.summary}>{base ? <><div><strong>{base.patient.first_name} {base.patient.family_name}</strong><p>كود المريض <bdi>{base.patient.patient_code}</bdi> </p></div><div><strong>{base.visit?`الزيارة: ${String(base.visit.visit_date)}`:"زيارة جديدة لم تُحفظ"}</strong><p>{base.visit?.status==="complete"?"زيارة مكتملة":"مسودة زيارة"} · {base.medical.is_oncology?"مريض ورمي":"رعاية عامة"}</p></div></> : <p>{mode === "existing" ? "اختر بطاقة المريض الموجودة؛ تسجيل زيارته في هذا المشفى لا ينشئ هوية أو كودًا آخر." : "لم تُحفظ بطاقة بعد. الحفظ الأول يسجّل المريض وزيارته الفعلية المسودة معًا؛ أدخل تاريخ الزيارة الفعلي قبل الحفظ."}</p>}</div>
    {step===4&&<div className={layout.sectionTabs} role="tablist" aria-label="أدوية هذه الزيارة">{([["prescription","الوصفة ونتيجة الزيارة"],["unlinked","صرف غير مرتبط بالجرعة"],["outside","صرف خارج المشفى"]] as const).filter(([key])=>key==="prescription"||caps.treatment_view).map(([key,label])=><button key={key} type="button" role="tab" aria-selected={medicationPane===key} disabled={key!=="prescription"&&(dirty.length>0||uploadsPending)} onClick={()=>{setMedicationPane(key);if(key!=="prescription")setTools(v=>v.includes(8)?v:[...v,8]);}}>{label}</button>)}</div>}
    <form ref={form} className={`${styles.form} ${layout.form}`} hidden={step>5||(step===4&&medicationPane!=="prescription")} noValidate onSubmit={e => { e.preventDefault(); void save(false); }}>
      {error && <div role="alert" tabIndex={-1} className={styles.conflict}><strong>{error.code === "DOSSIER_ALREADY_EXISTS" ? "للمريض بطاقة في هذا المشفى؛ مسودتك لم تُحفظ ولم تُفقد." : error.message}</strong>{error.status === 409 && error.code !== "DOSSIER_ALREADY_EXISTS" && <><p>لن تُستبدل البيانات تلقائيًا. اجلب النسخة الحالية لاختيار ما تريد تطبيقه من مسودتك.</p><button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة</button></>}{reloadError && <p>{reloadError}</p>}</div>}
      {review && step<3 && <WizardConflict step={step} latest={review} personal={personal} medical={medical} visit={visit} diagnoses={diagnoses} onAccept={(fields, rows) => { setSectionVersions(v => v.map((version, i) => i === step ? (step<2?review.lock_version:review.visit?.lock_version??0) : version)); if (step === 0) setPatientVersion(review.patient.lock_version); if (step === 0) setPersonal(fields); else if (step === 1) setMedical(fields); else { setVisit(fields); setDiagnoses(rows ?? []); } setBase(previous => ({ ...review, ...(step === 2 && previous ? { lock_version: previous.lock_version } : {}), ...(step !== 2 && previous ? { visit: previous.visit, workflow: { ...review.workflow, visit: previous.workflow.visit } } : {}) })); setReview(null); setError(null); reservation.current = null; touch(); }} />}
      {review && step>=3 && step<5 && <ClinicalConflict step={step} latest={review} draft={clinical} onAccept={value=>{setClinical(old=>({...old,...(step===3?{services:value.services,procedures:value.procedures}:{prescription:value.prescription,outcome:value.outcome})}));setSectionVersions(v=>v.map((version,i)=>i===step?review.visit!.lock_version:version));setBase(review);setReview(null);setError(null);reservation.current=null;touch();}}/>}
      {review && step===5 && <div className={styles.conflict}><p>راجع البيانات الحالية في ملخص المراجعة قبل تأكيدها مجددًا.</p><button type="button" className={styles.secondary} onClick={()=>{acceptServer(review,5);setReview(null);setError(null);setReviewed(false);reservation.current=null;}}>جلب البيانات الحالية لإعادة المراجعة</button></div>}
      <fieldset disabled={busy || !!review || (!!base && !allowed)} style={{ border: 0, padding: 0, margin: 0, minWidth: 0 }}>
        <section className={layout.section}><div><h3 ref={heading} tabIndex={-1}>{steps[step]}</h3><p className={styles.hint}>الحقول المعلّمة بنجمة مطلوبة. تبقى الأقسام اللاحقة محفوظة عند العودة.</p></div>
          {step === 0 && <>
            <div className={styles.fields}>{base ? <label>كود المريض<input name="code" value={personal.code} readOnly /><small>الكود الموحد ثابت.</small></label> : mode === "new" && input(personal, setPersonal, personalLabels, "code", "text", true)}{input(personal, setPersonal, personalLabels, "opening_date", "date", true)}</div>
            {!base && !existingDossier && <div className={styles.fields}><h4 className={layout.subheading}>أول زيارة مسجلة ضمن البطاقة</h4>{input(visit, setVisit, visitLabels, "visit_date", "date", true)}<p className={styles.hint}>تاريخ الواقعة الفعلي مطلوب؛ لا يُستنتج من تاريخ فتح الملف. لا يُحفظ أي تشخيص أو نتيجة تلقائيًا.</p></div>}
            {!base && <><fieldset className={styles.picker}><legend>بيانات المريض</legend><div className={layout.checkGroup}>{[["existing", "ربط بطاقة موجودة من مشفى آخر", caps.patients_search], ["new", "تسجيل بطاقة مريض جديدة", caps.patients_create]].map(([value, label, enabled]) => <label key={String(value)}><input type="radio" name="person_mode" disabled={!enabled} checked={mode === value} onChange={() => { setMode(String(value)); if (error?.code === "DOSSIER_ALREADY_EXISTS") setError(null); touch(); }} />{label}</label>)}</div></fieldset>{mode === "existing" && <><Picker name="patient_id" label="المريض الموجود" path={`dossiers/options/patients?facility_id=${facility}`} selected={patient} onSelect={p => { setPatient(p); if (error?.code === "DOSSIER_ALREADY_EXISTS") setError(null); touch(); }} />{fieldError("patient_id")}{existingDossier && <div className={styles.conflict}><p>للمريض ملف طبي في هذا المشفى. افتح بطاقته لاستكمال المسودة أو إضافة زيارة حسب الصلاحية.</p><div className={styles.actions}><Link className={styles.primary} href={`/patient-cards/new?${back}&card=${existingDossier}&section=2`}>فتح البطاقة / إضافة زيارة</Link><button type="button" className={styles.secondary} onClick={chooseAnother}>اختيار مريض آخر</button></div></div>}</>}</>}
            {(base || mode === "new") && <><div className={styles.fields}>
              {["first_name", "family_name"].map(k => input(personal, setPersonal, personalLabels, k, "text", ["first_name", "family_name"].includes(k)))}
              </div><details className={layout.optionalGroup}><summary>الأسرة والميلاد والجنس</summary><div className={styles.fields}>{["father_name","mother_name"].map(k=>input(personal,setPersonal,personalLabels,k))}{input(personal, setPersonal, personalLabels, "birth_date", "date")}
              {["birth_date_accuracy", "gender", "marital_status"].map(key => <label key={key}>{personalLabels[key]}<select name={key} value={personal[key]} onChange={e => changed(setPersonal, key, e.target.value)}>{Object.entries(personalChoices[key]).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select>{fieldError(key)}</label>)}
              </div></details><details className={layout.optionalGroup}><summary>التواصل والسكن</summary><div className={styles.fields}>{["phone", "alt_phone"].map(k => input(personal, setPersonal, personalLabels, k, "tel"))}
              <div><Picker name="governorate_id" label="المحافظة السورية" choices={options.governorates} selected={options.governorates.find(g => String(g.id) === personal.governorate_id)} onSelect={g => { touch(); setPersonal(p => ({ ...p, governorate_id: String(g.id), city_id: p.governorate_id === String(g.id) ? p.city_id : "" })); }} />{personal.governorate_id && <button type="button" className={styles.secondary} onClick={() => { touch(); setPersonal(p => ({ ...p, governorate_id: "", city_id: "" })); }}>إلغاء المحافظة والمدينة</button>}{fieldError("governorate_id")}</div>
              <div>{personal.governorate_id && <Picker key={personal.governorate_id} name="city_id" label="المدينة التابعة للمحافظة" path={`dossiers/options/cities?facility_id=${facility}&governorate_id=${personal.governorate_id}`} selected={personal.city_id ? { id: Number(personal.city_id), name_ar: "المدينة المحددة" } : null} onSelect={c => changed(setPersonal, "city_id", String(c.id))} />}{fieldError("city_id")}</div>
              <label className={styles.full}>عنوان السكن<input name="address_line" value={personal.address_line} onChange={e => changed(setPersonal, "address_line", e.target.value)} maxLength={255} /><small className={styles.hint}>للعنوان خارج سوريا، اكتب المحافظة والمدينة هنا واترك اختيارات الدليل فارغة.</small>{fieldError("address_line")}</label>
              <label>حالة النزوح<select name="displacement_status" value={personal.displacement_status} onChange={e => { const value = e.target.value; touch(); setPersonal(p => ({ ...p, displacement_status: value, permanent_address: value === "idp" ? p.permanent_address : "" })); }}>{Object.entries(personalChoices.displacement_status).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select></label>
              {personal.displacement_status === "idp" && <label className={styles.full}>عنوان الإقامة الدائم<textarea name="permanent_address" value={personal.permanent_address} onChange={e => changed(setPersonal, "permanent_address", e.target.value)} rows={3} maxLength={255} />{fieldError("permanent_address")}</label>}
            </div></details><details className={layout.optionalGroup}><summary>المهنة والتدخين والكحول</summary><div className={styles.fields}>
              {input(personal, setPersonal, personalLabels, "occupation")}
              {["smoking_status", "alcohol_status"].map(key => <label key={key}>{personalLabels[key]}<select name={key} value={personal[key]} onChange={e => changed(setPersonal, key, e.target.value)}>{Object.entries(personalChoices[key]).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select>{fieldError(key)}</label>)}
            </div></details></>}
          </>}
          {step === 1 && <>
            <div className={styles.fields}><label>معلومات الإعاقة<textarea name="disability_text" value={medical.disability_text} onChange={e => changed(setMedical, "disability_text", e.target.value)} rows={4} />{fieldError("disability_text")}</label><label>القصة المرضية المختصرة<textarea name="clinical_history" value={medical.clinical_history} onChange={e => changed(setMedical, "clinical_history", e.target.value)} rows={4} />{fieldError("clinical_history")}</label></div>
            <label>هل المريض ورمي؟ *<select name="is_oncology" value={medical.is_oncology} onChange={e => changed(setMedical, "is_oncology", e.target.value)}><option value="">اختر نعم أو لا</option><option value="yes">نعم</option><option value="no">لا</option></select>{fieldError("is_oncology")}</label>
            {medical.is_oncology === "no" && !!base?.medical.is_oncology && <label className={layout.checkGroup}><input name="confirm_hide_oncology" type="checkbox" checked={medical.confirm_hide_oncology === "yes"} onChange={e => changed(setMedical, "confirm_hide_oncology", e.target.checked ? "yes" : "no")} />أؤكد الاحتفاظ بالبيانات الورمية تاريخيًا وإخفاءها من العرض الحالي.{fieldError("confirm_hide_oncology")}</label>}
            {medical.is_oncology === "yes" && <><div className={styles.fields}>{[["history", "أنواع السوابق", historyLabels], ["treatment", "أنواع العلاج السابق", treatmentLabels]].map(([key, title, labels]) => <fieldset key={String(key)} className={styles.picker}><legend>{String(title)}</legend><div className={layout.checkGroup}>{Object.entries(labels as Fields).map(([v, name]) => <label key={v}><input type="checkbox" checked={(JSON.parse(medical[String(key)]) as string[]).includes(v)} onChange={e => { const list = JSON.parse(medical[String(key)]) as string[]; changed(setMedical, String(key), JSON.stringify(e.target.checked ? [...list, v] : list.filter(x => x !== v))); }} />{name}</label>)}</div></fieldset>)}</div><label>الفحوص السابقة<textarea name="previous_examinations" rows={4} value={medical.previous_examinations} onChange={e => changed(setMedical, "previous_examinations", e.target.value)} />{fieldError("previous_examinations")}</label><div className={styles.fields}><label>مصدر الدواء العام<select name="medication_source" value={medical.medication_source} onChange={e => changed(setMedical, "medication_source", e.target.value)}><option value="">غير مسجل</option>{Object.entries(sourceLabels).map(([v, label]) => <option key={v} value={v}>{label}</option>)}</select></label>{medical.medication_source === "other_organization" && input(medical, setMedical, medicalLabels, "other_organization", "text", true)}</div></>}
          </>}
          {step === 2 && <>
            <p className={styles.hint}>تُحفظ زيارة فعلية مسودة بتاريخها، دون اشتراط فترة تقارير. يمكن حفظها قبل إضافة التشخيصات.</p>
            <div className={styles.fields}>{input(visit, setVisit, visitLabels, "visit_date", "date", true)}</div>
            <label>هل المريض محال من مشفى آخر؟<select name="is_referred" value={visit.is_referred} onChange={e => changed(setVisit, "is_referred", e.target.value)}><option value="no">لا</option><option value="yes">نعم</option></select></label>
            {visit.is_referred === "yes" && <div className={styles.fields}>{input(visit, setVisit, visitLabels, "referring_hospital", "text", true)}{input(visit, setVisit, visitLabels, "referral_date", "date", true)}<label className={styles.full}>سبب الإحالة *<textarea name="referral_reason" rows={3} value={visit.referral_reason} onChange={e => changed(setVisit, "referral_reason", e.target.value)} />{fieldError("referral_reason")}</label></div>}
            <div><h4>التشخيصات</h4><p className={styles.hint}>لكل تشخيص عيادته وطبيبه؛ لا يوجد تشخيص رئيسي. الارتباط الجديد يجب أن يغطي تاريخ الزيارة.</p>{!diagnoses.length && <p>لم تُسجّل تشخيصات بعد؛ هذا القسم غير مكتمل.</p>}{fieldError("diagnoses")}</div>
            {diagnoses.map((row, index) => <DiagnosisEditor key={row.key} row={row} index={index} facility={facility} date={visit.visit_date} canCreate={!!caps.diagnoses_create} change={value => { touch(); setDiagnoses(items => items.map(d => d.key === row.key ? value : d)); }} remove={() => { touch(); setDiagnoses(items => items.filter(d => d.key !== row.key)); }} error={fieldError} />)}
            <div><button type="button" className={styles.secondary} onClick={() => { touch(); setDiagnoses(items => [...items, { key: `new-${crypto.randomUUID()}`, diagnosis: null, clinic: null, doctor: null, diagnosed_on: "" }]); }}><LuPlus aria-hidden="true" />إضافة تشخيص للزيارة</button></div>
          </>}
          {(step===3||step===4)&&<ClinicalEditor section={step} value={clinical} change={v=>{touch();setClinical(v);}} facility={facility} date={visit.visit_date} canCreateMedication={!!caps.medications_create} error={fieldError}/>}
          {base?.visit&&<div className={layout.review} hidden={step!==5}><FinalReview onReviewMode={setReviewPane} facility={facility} snapshot={base} caps={caps} onSaved={s=>acceptServer(s,-1)} onPending={setUploadsPending} blocked={busy||dirty.some(n=>n<5)||!!review||error?.status===409} dirty={dirty.length>0} reviewed={reviewed} setReviewed={v=>{setReviewed(v);touch();}} error={fieldError} onError={e=>{focusError.current=true;setError(e);}}/></div>}
        </section>
      </fieldset>
      <div className={layout.actions} hidden={step===5&&!reviewPane}><span role="status">{busy ? "جارٍ الحفظ أو جلب البيانات…" : saved || (dirty.includes(step) ? "توجد تغييرات لم تُحفظ." : "الحفظ لا يفعّل بطاقة المريض ولا يُكمل الزيارة.")}</span>{step > (subsequent?2:0) && <button type="button" className={styles.secondary} disabled={busy || !!review || error?.status === 409} onClick={() => go(step - 1)}>السابق</button>}<button type="button" className={styles.secondary} disabled={!allowed || busy || uploadsPending || !!review || error?.status === 409 || (step===5&&dirty.some(n=>n!==5))} onClick={() => void save(true)}>حفظ كمسودة والخروج</button><button type="submit" className={styles.primary} disabled={!allowed || busy || uploadsPending || !!review || error?.status === 409 || (step===5&&dirty.some(n=>n!==5))}><LuSave aria-hidden="true" />{step === 5 ? "حفظ المراجعة كمسودة" : "حفظ ومتابعة"}</button></div>
      {!allowed && !existingDossier && <p className={styles.hint}>لا تتوفر صلاحية حفظ هذا القسم أو أن حالته لا تسمح بالتعديل.</p>}
    </form>
    {base?.visit&&(tools.length>0||step>5||step===4&&medicationPane!=="prescription")&&<WorkspaceClinical facility={facility} snapshot={base} caps={caps} active={step===4&&medicationPane!=="prescription"?8:step} medicationView={step===4&&medicationPane!=="prescription"?medicationPane:undefined} visited={tools} revision={clinicalRevision} onChanged={()=>setClinicalRevision(v=>v+1)}/>}
    </div></div>
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
    return personalChoices[key]?.[value] ?? (value === "yes" ? "نعم" : value === "no" ? "لا" : sourceLabels[value] ?? value);
  };
  return <><ConflictReview key={`${latest.lock_version}:${latest.visit?.lock_version}`} latest={latestFields} draft={draft} labels={labels} format={format} onAccept={value => { const rows = Object.entries(value).filter(([key, data]) => key.startsWith("row:") && data).map(([, data]) => { const r = JSON.parse(data) as DiagnosisDraft; return { ...r, lock_version: currentRows.find(c => c.id === r.id)?.lock_version }; }); onAccept(Object.fromEntries(Object.entries(value).filter(([k]) => !k.startsWith("row:"))), rows); }} />{step === 2 && diagnoses.some(r => r.id && !currentRows.some(c => c.id === r.id)) && <p role="status">أزيلت بعض التشخيصات في النسخة الحالية؛ لن تُعاد تلقائيًا. يمكنك مراجعتها وإضافتها صراحة بعد اعتماد المراجعة.</p>}</>;
}
