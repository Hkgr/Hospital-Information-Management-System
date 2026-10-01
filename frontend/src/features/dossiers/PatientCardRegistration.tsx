"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { LuFolderHeart, LuSearch } from "react-icons/lu";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest, AuthError } from "../auth/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack, DirectoryTable } from "../directory/DirectoryPrimitives";
import { useClinicRequest } from "../clinics/api";
import useClinicSearch from "../clinics/useClinicSearch";
import { personalChoices, type WorkflowActions } from "../dossiers/wizard";
import IdentityPanel from "../reception/IdentityPanel";
import styles from "../clinics/clinics.module.css";
import layout from "../dossiers/wizard.module.css";

type Patient = { id: number; code: string; first_name: string; family_name: string; birth_date: string | null; gender: string; dossier_id: number | null };
type Card = Omit<Patient, "dossier_id"> & { patient_id: number; status: string; opening_date: string; registration_visit_id: number | null; workflow: WorkflowActions | null };
type Options = { today: string; can_search: boolean; can_create_patient: boolean; can_register: boolean };
const initialDraft = { first_name: "", family_name: "", father_name: "", mother_name: "", national_id: "", phone: "", alt_phone: "", birth_date: "", birth_date_accuracy: "unknown", gender: "unknown", marital_status: "unknown", address_line: "", displacement_status: "unknown", permanent_address: "", occupation: "", opening_date: "", visit_date: "" };
const normalize = (value: string) => value.trim().replace(/\s+/g, " ");

export default function PatientCardRegistration({ id, create = false }: { id?: string; create?: boolean }) {
  const identity = useIdentity(), params = useSearchParams();
  const { entry } = directoryFacility(identity.access, "patients.basic.view", params.get("facility_id"));
  if (!entry || (id && !/^[1-9]\d*$/.test(id))) return <section className={styles.status}><h2>بطاقات المرضى</h2><p role="alert">لا يتوفر وصول إلى البيانات الأساسية في المشفى المحدد. راجع مسؤول الصلاحيات.</p></section>;
  return <Registration key={`${identity.user.id}:${entry.facility.id}:${id ?? create}`} id={id} create={create} facility={entry.facility.id} hospital={entry.facility.name_ar} medical={entry.permissions.includes("dossiers.medical.view")} />;
}

function Registration({ facility, hospital, id, medical, create }: { facility: number; hospital: string; id?: string; medical: boolean; create: boolean }) {
  const params = useSearchParams(), path = usePathname(), router = useRouter();
  const search = useClinicSearch(path, params.toString(), facility);
  const options = useClinicRequest<Options>(`patient-cards/options?facility_id=${facility}`);
  const term = normalize(search.committed);
  const results = useClinicRequest<Patient[]>(options.data?.can_search && term.length >= 3 ? `patient-cards/patients?facility_id=${facility}&search=${encodeURIComponent(term)}` : null);
  const detail = useClinicRequest<Card>(id ? `patient-cards/cards/${id}?facility_id=${facility}` : null);
  const [selected, setSelected] = useState<Patient | null>(null), [creating, setCreating] = useState(create && !id);
  const [saved, setSaved] = useState<Card | null>(null), [busy, setBusy] = useState(false), [error, setError] = useState<AuthError | null>(null);
  const [draft, setDraft] = useState(initialDraft), [reviewedMatch, setReviewedMatch] = useState("");
  const pending = useRef<AbortController | null>(null), reservation = useRef<{ body: string; id: string } | null>(null), form = useRef<HTMLFormElement>(null);
  const duplicateTerm = creating ? normalize(`${draft.first_name} ${draft.family_name}`) : "";
  const [settledName, setSettledName] = useState("");
  useEffect(() => { const timer = setTimeout(() => setSettledName(duplicateTerm), 300); return () => clearTimeout(timer); }, [duplicateTerm]);
  const duplicates = useClinicRequest<Patient[]>(creating && options.data?.can_search && settledName.length >= 3 ? `patient-cards/patients?facility_id=${facility}&search=${encodeURIComponent(settledName)}` : null);
  const duplicatePending = !!options.data?.can_search && duplicateTerm.length >= 3 && (settledName !== duplicateTerm || duplicates.loading);
  const matches = settledName === duplicateTerm ? duplicates.data ?? [] : [];
  const duplicateKey = `${duplicateTerm}:${matches.map(p => p.id).join(",")}`;
  const card = saved ?? detail.data;
  useEffect(() => () => pending.current?.abort(), []);
  useEffect(() => { if (error) requestAnimationFrame(() => form.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()); }, [error]);
  const back = new URLSearchParams(params.toString()); back.set("facility_id", String(facility)); back.delete("card"); back.delete("section"); back.delete("visit");
  function open(patient: Patient) {
    if (busy) return;
    setError(null); setSaved(null); setCreating(false);
    if (patient.dossier_id) router.push(`/patient-cards/${patient.dossier_id}?${back}&view=registration`);
    else { setSelected(patient); setDraft(value => ({ ...value, opening_date: value.opening_date || options.data?.today || "" })); }
  }
  function start() { setCreating(true); setSelected(null); setSaved(null); setError(null); }
  async function save() {
    if (pending.current || !options.data?.can_register || (!creating && !selected) || selected?.dossier_id || creating && (duplicatePending || !!duplicates.error || matches.length > 0 && reviewedMatch !== duplicateKey)) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null);
    const personal = Object.fromEntries(Object.entries(draft).map(([key, value]) => [key, value || null]));
    const payload = { facility_id: facility, ...(creating ? { ...personal, person_mode: "new" } : { person_mode: "existing", patient_id: selected!.id, opening_date: draft.opening_date, visit_date: draft.visit_date }) };
    const body = JSON.stringify(payload);
    if (reservation.current?.body !== body) reservation.current = { body, id: crypto.randomUUID() };
    try {
      const value = await apiRequest<Card>("patient-cards/registrations", { method: "POST", signal: c.signal, body: JSON.stringify({ ...payload, request_id: reservation.current.id }) });
      if (!c.signal.aborted) { setSaved(value); setCreating(false); setSelected(null); setDraft(initialDraft); }
    } catch (e) { if (!c.signal.aborted) setError(e as AuthError); }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  function field(key: keyof typeof draft, label: string, type = "text", required = false) {
    const choices = personalChoices[key];
    return <label key={key}>{label}{required && " *"}{choices ? <select name={key} value={draft[key]} aria-invalid={!!error?.fields[key]} onChange={e => setDraft(d => ({ ...d, [key]: e.target.value }))}>{Object.entries(choices).map(([value, title]) => <option value={value} key={value}>{title}</option>)}</select> : <input aria-label={label} name={key} type={type} required={required} value={draft[key]} aria-invalid={!!error?.fields[key]} onChange={e => setDraft(d => ({ ...d, [key]: e.target.value }))} />}{error?.fields[key] && <small role="alert" className={styles.fieldError}>{error.fields[key]}</small>}</label>;
  }
  function patientTable(rows: Patient[], label: string) { return <DirectoryTable label={label} headers={["الكود", "الاسم", "الميلاد", "الإجراء"]}>{rows.map(p => <tr key={p.id}><td><bdi>{p.code}</bdi></td><td>{p.first_name} {p.family_name}</td><td>{p.birth_date ?? "غير معروف"}</td><td><button type="button" className={styles.secondary} disabled={busy} onClick={() => open(p)}>{p.dossier_id ? "فتح البطاقة" : "اختيار هذه الهوية"}</button></td></tr>)}</DirectoryTable>; }
  return <div className={styles.screen}>
    {(id || creating || selected) && <DirectoryBack href={`/patient-cards?${back}`}>العودة إلى بطاقات المرضى</DirectoryBack>}
    <header className={styles.heading}><div><h2>{create && !card ? "إضافة بطاقة مريض" : "بطاقات المرضى"}</h2><p>{hospital}</p></div><Link className={styles.secondary} href={`/guide?facility_id=${facility}`}>دليل إضافة البطاقة</Link></header>
    <p className={styles.scopeNote}><LuFolderHeart aria-hidden="true" /> هوية وكود واحد للمريض، وبطاقة للمشفى وزيارات متعددة. هذه الشاشة تعرض البيانات الأساسية فقط.</p>
    {[options.error, results.error, detail.error].filter(Boolean).map((message, i) => <p role="alert" key={i}>{message}<button className={styles.secondary} disabled={busy} onClick={() => { options.retry(); results.retry(); detail.retry(); }}>إعادة المحاولة</button></p>)}
    {error && <p role="alert">{error.message}{error.code === "DOSSIER_ALREADY_EXISTS" && typeof error.details.existing_dossier_id === "number" && <Link className={styles.secondary} href={`/patient-cards/${error.details.existing_dossier_id}?${back}&view=registration`}>فتح البطاقة الموجودة</Link>}</p>}
    {!options.data && !options.error && <p role="status">جارٍ التحقق من صلاحيات التسجيل…</p>}
    {!id && options.data?.can_search && <details className={styles.detailPanel} open={!create || undefined}><summary><LuSearch aria-hidden="true" /> البحث عن مريض موجود</summary><label className={styles.search}>الاسم أو كود المريض أو الرقم الوطني<input type="search" value={search.search} disabled={busy} onChange={e => search.change(e.target.value)} placeholder="ثلاثة محارف على الأقل للبحث فقط" /></label><p className={styles.hint}>البحث اختياري لفتح النموذج. يعرض عشر هويات كحد أقصى ضمن النطاق المسموح، دون معلومات طبية.</p>{search.searching || results.loading ? <p role="status">جارٍ البحث…</p> : term.length >= 3 && results.data ? results.data.length ? patientTable(results.data, "نتائج البحث المحدود") : <p role="status">لا توجد نتائج مطابقة. راجع الاسم والمعرّف قبل إنشاء مريض جديد.</p> : null}</details>}
    {!id && !creating && !selected && !card && options.data?.can_register && <Link className={styles.primary} href={`/patient-cards/new?facility_id=${facility}`}>إضافة بطاقة مريض</Link>}
    {create && options.data && !options.data.can_register && <p role="alert">يمكنك عرض البيانات الأساسية فقط؛ إضافة البطاقة تحتاج صلاحية «إضافة مريض وبطاقته وزيارته الأولى» داخل المشفى. راجع مسؤول الصلاحيات.</p>}
    {detail.loading && <p role="status">جارٍ فتح ملخص البطاقة…</p>}
    {card && <><section className={styles.panel} aria-label="البيانات الأساسية للبطاقة"><h3>{card.first_name} {card.family_name}</h3><dl className={styles.facts}><div><dt>كود المريض</dt><dd><bdi>{card.code}</bdi></dd></div><div><dt>حالة البطاقة</dt><dd>{card.status === "draft" ? "مسودة" : "فعالة"}</dd></div><div><dt>تاريخ فتح البطاقة</dt><dd>{card.opening_date}</dd></div></dl><p className={styles.hint}>{card.registration_visit_id ? "الزيارة الأولى محفوظة؛ يتابع الفريق الطبي الاستكمال على السجل نفسه دون تسجيل جديد." : "بطاقة محفوظة في المشفى."}</p>{card.workflow && <div className={styles.actions}><Link className={styles.secondary} href={`/patient-cards/${card.id}?facility_id=${facility}`}>فتح مساحة العمل الطبية</Link>{card.workflow.resume_section !== null && <Link className={styles.primary} href={`/patient-cards/new?facility_id=${facility}&card=${card.id}&section=${card.workflow.resume_section}`}>استكمال المسودة المحفوظة</Link>}{card.workflow.subsequent_create && <Link className={styles.secondary} href={`/patient-cards/new?facility_id=${facility}&card=${card.id}&visit=new`}>إضافة زيارة للمريض</Link>}</div>}</section><IdentityPanel key={card.id} facility={facility} id={card.id} /></>}
    {medical && !id && <Link className={styles.secondary} href={`/patient-cards?facility_id=${facility}`}>قائمة البطاقات ومساحة العمل الطبية</Link>}
    {(creating || selected && !selected.dossier_id) && options.data?.can_register && <form ref={form} className={`${styles.panel} ${styles.form}`} noValidate onSubmit={e => { e.preventDefault(); void save(); }}><h3>{creating ? "بيانات المريض وبطاقته وزيارته الأولى" : `فتح بطاقة لـ ${selected?.first_name} ${selected?.family_name}`}</h3>{selected && <p className={styles.scopeNote}>ستُستخدم هوية المريض وكوده <bdi>{selected.code}</bdi> دون نسخ بياناته أو تغييرها.<button type="button" className={styles.secondary} onClick={start} disabled={busy}>العودة إلى مسودة الشخص الجديد</button></p>}<fieldset disabled={busy}>
      {creating && <><h4 className={layout.subheading}>الهوية الأساسية</h4><div className={styles.fields}>{field("first_name", "الاسم الأول", "text", true)}{field("family_name", "اسم العائلة", "text", true)}{field("national_id", "الرقم الوطني")}{field("phone", "رقم الهاتف", "tel")}{field("birth_date", "تاريخ الميلاد", "date")}{field("birth_date_accuracy", "دقة الميلاد")}{field("gender", "الجنس")}{field("address_line", "عنوان السكن")}</div><details><summary>بيانات شخصية إضافية متاحة</summary><div className={styles.fields}>{field("father_name", "اسم الأب")}{field("mother_name", "اسم الأم")}{field("alt_phone", "هاتف بديل", "tel")}{field("marital_status", "الوضع العائلي")}{field("occupation", "المهنة")}{field("displacement_status", "حالة النزوح")}{draft.displacement_status === "idp" && field("permanent_address", "عنوان الإقامة الدائم")}</div></details>
      {duplicatePending && <p role="status">جارٍ مراجعة الأسماء المشابهة…</p>}{duplicates.error && <p role="alert">تعذّر فحص الأسماء المشابهة؛ المسودة محفوظة.<button type="button" className={styles.secondary} onClick={duplicates.retry}>إعادة فحص التشابه</button></p>}{matches.length > 0 && <section className={styles.scopeNote} aria-label="مراجعة تشابه الأسماء"><h4>وجدنا أسماء مشابهة؛ راجعها قبل الإنشاء</h4><p>هذا تنبيه للمراجعة ولا يثبت تطابق الأشخاص. اختيار هوية موجودة لا يدمج السجلات.</p>{patientTable(matches, "هويات مشابهة للمراجعة")}<label><input type="checkbox" checked={reviewedMatch === duplicateKey} onChange={e => setReviewedMatch(e.target.checked ? duplicateKey : "")} />راجعت النتائج وأؤكد أن هذا شخص آخر</label></section>}</>}
      <h4 className={layout.subheading}>التسجيل والزيارة الفعلية</h4><div className={styles.fields}>{field("opening_date", "تاريخ فتح البطاقة", "date", true)}{field("visit_date", "تاريخ الزيارة الفعلي", "date", true)}</div><p className={styles.hint}>تاريخ الفتح يخص ملف المشفى. أدخل تاريخ الزيارة التي حدثت فعلًا؛ لن يُفترض تاريخ اليوم.</p>
    </fieldset>{creating && matches.length > 0 && reviewedMatch !== duplicateKey && <p role="status">الحفظ ينتظر مراجعة الأسماء المشابهة: اختر الهوية الموجودة أو أكد أن هذا شخص آخر.</p>}<div className={styles.actions}><button className={styles.primary} disabled={busy || creating && (duplicatePending || !!duplicates.error || matches.length > 0 && reviewedMatch !== duplicateKey)}>{busy ? "جارٍ الحفظ…" : "حفظ البطاقة والزيارة الأولى"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={() => router.push(`/patient-cards?${back}`)}>إلغاء</button></div><p className={styles.hint}>تُحفظ البطاقة والزيارة كمسودتين. لا يُنشأ شيء قبل نجاح الحفظ، ولا تُضاف معلومات طبية من هذا النموذج.</p></form>}
  </div>;
}
