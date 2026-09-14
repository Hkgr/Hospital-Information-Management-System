"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import Picker from "./Picker";
import PersonFields, { BloodFields, personDraft, personPayload, type Draft } from "./PersonFields";
import ScreeningFields from "./ScreeningFields";
import InlineDoctor from "./InlineDoctor";
import ConflictReview from "./ConflictReview";
import { type Choice, type Options, statuses, useBloodRequest } from "./api";
import { type BloodEvent, type EventKind, type Person, unitLabel } from "./events";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";
import { FormActions, FormSection, PersonSummary } from "./FormLayout";
import { useFormErrors } from "./useFormErrors";

function eventDraft(e?: BloodEvent): Draft {
  const d: Draft = {};
  for (const key of ["benefit_kind", "occurred_on", "quantity", "blood_group", "rh", "blood_component_id", "clinic_id", "responsible_staff_id", "beneficiary_entity", "entity_address", "issue_event_id"] as const) d[key] = String(e?.[key] ?? "");
  d.benefit_link_mode = e?.benefit_kind === "transfusion" ? e.issue_event_id ? "linked" : "independent" : "";
  for (const a of ["HBsAg", "HCV", "HIV"]) d[a] = e?.screenings.find(s => s.analyte === a)?.status ?? "";
  return d;
}
const labels = { occurred_on: "التاريخ الفعلي", quantity: "الكمية", blood_group: "ABO", rh: "Rh", blood_component_id: "المكوّن", clinic_id: "العيادة", responsible_staff_id: "الطبيب", beneficiary_entity: "جهة المستفيد", entity_address: "عنوان الجهة", HBsAg: "HBsAg", HCV: "HCV", HIV: "HIV" };
export default function EventEditor({ kind, event, selectedPerson, facilityId, options, onClose, onSaved, onRefresh }: { kind: EventKind; event?: BloodEvent; selectedPerson?: Person; facilityId: number; options: Options; onClose: () => void; onSaved: (e: BloodEvent) => void; onRefresh: () => void }) {
  const [base, setBase] = useState(event); const [draft, setDraft] = useState(() => eventDraft(event));
  const [source, setSource] = useState("existing"); const [personal, setPersonal] = useState(() => personDraft());
  const [chosen, setChosen] = useState<Choice | null>(selectedPerson ? { id: selectedPerson.id, name_ar: selectedPerson.name, code: selectedPerson.code } : event ? { id: event.person_id, name_ar: event.name, code: event.person_code } : null);
  const [patient, setPatient] = useState<Choice | null>(null);
  const [clinic, setClinic] = useState<Choice | null>(event?.clinic_id ? { id: event.clinic_id, name_ar: event.clinic_name ?? "العيادة المسجلة" } : null);
  const [doctor, setDoctor] = useState<Choice | null>(event?.responsible_staff_id ? { id: event.responsible_staff_id, name_ar: event.doctor_name ?? "الطبيب المسجل" } : null);
  const [inline, setInline] = useState(false); const [busy, setBusy] = useState(false); const [error, setError] = useState<AuthError | null>(null);
  const [conflict, setConflict] = useState(false); const [latest, setLatest] = useState<BloodEvent | null>(null); const [reloadError, setReloadError] = useState("");
  const pending = useRef(false); const active = useRef<AbortController | null>(null); const retry = useRef<{ body: string; id: string } | null>(null);
  useEffect(() => () => active.current?.abort(), []);
  const current = useBloodRequest<Person>(source === "existing" && chosen ? `blood-bank/people/${chosen.id}?facility_id=${facilityId}` : null);
  const [bloodPerson, setBloodPerson] = useState<number | null>(null);
  // Initialize once per explicit person selection. Request retries and refreshed
  // options must not overwrite a blood-group draft (including an explicit unknown).
  if (!base && source === "existing" && current.data?.id === chosen?.id && current.data && bloodPerson !== current.data.id) {
    setBloodPerson(current.data.id);
    setDraft(p => ({ ...p, blood_group: current.data!.blood_group ?? "", rh: current.data!.rh ?? "" }));
  }
  const [issueChoice, setIssueChoice] = useState<Choice | null>(null);
  const change = (key: string, value: string) => { if (key === "blood_group" || key === "rh") setBloodPerson(chosen?.id ?? null); setDraft(p => ({ ...p, [key]: value })); };
  function resetPersonBlood() { setBloodPerson(null); setDraft(p => ({ ...p, blood_group: "", rh: "", issue_event_id: "" })); }
  const { form, onInvalid, fieldError } = useFormErrors(error, busy);
  async function reload() {
    if (!base || pending.current) return;
    pending.current = true; setBusy(true); setReloadError(""); const c = new AbortController(); active.current = c;
    try { const value = await apiRequest<BloodEvent>(`blood-bank/events/${base.id}?facility_id=${facilityId}`, { signal: c.signal }); if (!c.signal.aborted) { setLatest(value); onRefresh(); } }
    catch { if (!c.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ مسودتك محفوظة. أعد المحاولة."); }
    finally { if (!c.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (pending.current || conflict || (source === "existing" && (!current.data || current.loading || current.error))) return;
    const payload: Record<string, unknown> = { facility_id: facilityId, kind, occurred_on: draft.occurred_on, quantity: draft.quantity, quantity_unit: base?.quantity_unit ?? "kg", blood_group: draft.blood_group || null, rh: draft.rh || null, blood_component_id: Number(draft.blood_component_id), clinic_id: Number(draft.clinic_id), responsible_staff_id: Number(draft.responsible_staff_id), screenings: ["HBsAg", "HCV", "HIV"].filter(a => draft[a]).map(analyte => ({ analyte, status: draft[analyte] })) };
    if (base) payload.lock_version = base.lock_version;
    if (source === "existing") payload.person_id = chosen?.id;
    else payload.person = personPayload({ ...personal, blood_group: draft.blood_group, rh: draft.rh }, patient);
    if (kind === "benefit") {
      payload.benefit_kind = draft.benefit_kind; payload.beneficiary_entity = draft.beneficiary_entity || null; payload.entity_address = draft.entity_address || null;
      if (draft.benefit_kind === "transfusion") { payload.benefit_link_mode = draft.benefit_link_mode; if (draft.benefit_link_mode === "linked") payload.issue_event_id = Number(draft.issue_event_id); }
    }
    const body = JSON.stringify(payload); if (retry.current?.body !== body) retry.current = { body, id: crypto.randomUUID() };
    pending.current = true; setBusy(true); setError(null); const c = new AbortController(); active.current = c;
    try { const value = await apiRequest<BloodEvent>(`blood-bank/events${base ? `/${base.id}` : ""}`, { method: base ? "PUT" : "POST", body: JSON.stringify({ ...payload, request_id: retry.current.id }), signal: c.signal }); if (!c.signal.aborted) onSaved(value); }
    catch (reason) { if (!c.signal.aborted) { const err = reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. مسودتك محفوظة؛ أعد المحاولة."); setError(err); if (err.code === "BLOOD_BANK_VERSION_CONFLICT") { setConflict(true); setLatest(null); } } }
    finally { if (!c.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <><Modal title={`${base ? "تعديل" : "تسجيل"} ${kind === "donation" ? "تبرع" : "استفادة"}`} onClose={onClose} busy={busy} size="wide"><form ref={form} className={`${styles.form} ${layout.profileForm}`} onSubmit={save} onInvalidCapture={onInvalid}>
    <p className={styles.scopeNote}>الحقول المعلّمة * مطلوبة. يحفظ التسجيل الشخص والواقعة معًا؛ لا يدل على أهلية أو قبول تبرع، ولا ينشئ مخزونًا.</p>
    {error && <p className={styles.error} role="alert" tabIndex={-1}>{error.message}{error.status === 422 && <> مسودتك محفوظة؛ راجع الحقول الموضحة أدناه.</>}</p>}
    {conflict && <><button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة</button>{reloadError && <p role="alert">{reloadError}</p>}</>}
    {latest && <ConflictReview key={latest.lock_version} latest={eventDraft(latest)} draft={draft} labels={labels} format={(key, value) => statuses[value] ?? (key === "clinic_id" ? [clinic, { id: latest.clinic_id, name_ar: latest.clinic_name }].find(v => String(v?.id) === value)?.name_ar ?? value : key === "responsible_staff_id" ? [doctor, { id: latest.responsible_staff_id, name_ar: latest.doctor_name }].find(v => String(v?.id) === value)?.name_ar ?? value : key === "blood_component_id" ? options.blood_components.find(v => String(v.id) === value)?.name_ar ?? value : value)} onAccept={value => { setDraft(value); setBase(latest); setClinic(String(latest.clinic_id) === value.clinic_id ? latest.clinic_id ? { id: latest.clinic_id, name_ar: latest.clinic_name ?? "" } : null : clinic); setDoctor(String(latest.responsible_staff_id) === value.responsible_staff_id ? latest.responsible_staff_id ? { id: latest.responsible_staff_id, name_ar: latest.doctor_name ?? "" } : null : doctor); setConflict(false); setLatest(null); setError(null); retry.current = null; }} />}
    <fieldset className={layout.sectionList} disabled={busy || conflict}>
      <FormSection number="01" title="بيانات الشخص" hint="اختر شخصًا مسجلًا أو أضف بياناته مع أول واقعة.">
      {!base && !selectedPerson && <label className={styles.full}>الشخص<select value={source} onChange={e => { setSource(e.target.value); resetPersonBlood(); }}><option value="existing">اختيار شخص موجود</option><option value="new" disabled={!options.capabilities.create}>إضافة شخص جديد مع الواقعة</option></select></label>}
      {source === "existing" ? <div className={`${styles.full} ${layout.screenings}`}>{!base && !selectedPerson && <Picker name="person_id" label="الشخص الموجود" path={`blood-bank/people?facility_id=${facilityId}`} selected={chosen} onSelect={p => { if (p.id !== chosen?.id) resetPersonBlood(); setChosen(p); }} />}{current.loading && <p role="status">جارٍ جلب بيانات الشخص…</p>}{current.error && <p role="alert">{current.error} <button type="button" className={styles.secondary} onClick={current.retry}>إعادة المحاولة</button></p>}{current.data && <PersonSummary person={current.data} />}{fieldError("person_id")}</div> : <PersonFields draft={personal} change={(key, value) => setPersonal(p => ({ ...p, [key]: value }))} patient={patient} onPatient={setPatient} facilityId={facilityId} options={options} fieldError={fieldError} />}
      </FormSection>
      <FormSection number="02" title={`بيانات ${kind === "donation" ? "التبرع" : "الاستفادة"}`} hint={`التاريخ الفعلي والكمية ${base?.quantity_unit === "unit" ? "بالوحدة التاريخية" : "بالكيلوغرام"}؛ زمرة هذه الواقعة مستقلة عن الوقائع السابقة.`}>
      {kind === "benefit" && <><label className={styles.full}>نوع الاستفادة *<select name="benefit_kind" required disabled={!!base} value={draft.benefit_kind} onChange={e => change("benefit_kind", e.target.value)}><option value="">اختر نوع الاستفادة</option><option value="issue">استلام/صرف مكوّن دم</option><option value="transfusion">نقل دم أُجري فعليًا للمستفيد</option></select>{fieldError("benefit_kind")}</label>
        {draft.benefit_kind === "transfusion" && <><label className={styles.full}>ارتباط عملية النقل *<select name="benefit_link_mode" required disabled={!!base} value={draft.benefit_link_mode} onChange={e => change("benefit_link_mode", e.target.value)}><option value="">حدد ارتباط العملية</option><option value="independent">نقل مستقل؛ لا توجد واقعة صرف سابقة لهذه العملية</option><option value="linked" disabled={source !== "existing"}>استكمال صرف سابق مسجل للشخص نفسه</option></select>{fieldError("benefit_link_mode")}</label>{draft.benefit_link_mode === "linked" && <div className={styles.full}>{base ? <p>الصرف المرتبط: {base.issue_event_id}</p> : <Picker name="issue_event_id" key={chosen?.id} label="واقعة الصرف السابقة" eventChoices path={chosen ? `blood-bank/events?facility_id=${facilityId}&person_id=${chosen.id}&available_issues=1` : undefined} selected={String(issueChoice?.id) === draft.issue_event_id ? issueChoice : null} onSelect={i => { setIssueChoice(i); change("issue_event_id", String(i.id)); }} />}{fieldError("issue_event_id")}<p className={styles.hint}>يظهر الصرف والنقل مرحلتين في السجل، ويُحسبان استفادة واحدة.</p></div>}</>}
      </>}
      <label>التاريخ الفعلي *<input name="occurred_on" aria-label="التاريخ الفعلي" required type="date" value={draft.occurred_on} onChange={e => change("occurred_on", e.target.value)} />{fieldError("occurred_on")}</label>
      <label>الكمية ({unitLabel(base?.quantity_unit ?? "kg")}) *<input name="quantity" aria-label={`الكمية (${unitLabel(base?.quantity_unit ?? "kg")})`} required type="number" inputMode="decimal" min="0.0001" max="99999999999999.9999" step="0.0001" value={draft.quantity} onChange={e => change("quantity", e.target.value)} />{fieldError("quantity")}</label>
      {base?.quantity_unit === "unit" && <p className={`${styles.hint} ${styles.full}`}>كمية تاريخية بعدد الوحدات؛ لا تُحوّل إلى كيلوغرامات.</p>}
      <fieldset className={`${styles.fields} ${styles.full} ${layout.bloodFields}`}><legend>زمرة الدم لهذه الواقعة</legend><BloodFields draft={draft} change={change} fieldError={fieldError} /></fieldset>
      <label className={styles.full}>نوع المكوّن *<select name="blood_component_id" required value={draft.blood_component_id} onChange={e => change("blood_component_id", e.target.value)}><option value="">اختر المكوّن</option>{options.blood_components.map(c => <option key={c.id} value={c.id}>{c.name_ar}</option>)}{base?.blood_component_id && !options.blood_components.some(c => c.id === base.blood_component_id) && <option value={base.blood_component_id}>{base.component_name} (قيمة تاريخية)</option>}</select>{fieldError("blood_component_id")}</label>
      {kind === "benefit" && <><label>جهة المستفيد<input name="beneficiary_entity" maxLength={200} value={draft.beneficiary_entity} onChange={e => change("beneficiary_entity", e.target.value)} />{fieldError("beneficiary_entity")}</label><label>عنوان الجهة<input name="entity_address" aria-label="عنوان الجهة" maxLength={255} value={draft.entity_address} onChange={e => change("entity_address", e.target.value)} />{fieldError("entity_address")}<small>مستقل عن عنوان سكن الشخص.</small></label></>}
      </FormSection>
      <FormSection number="03" title="العيادة والطبيب" hint="اختر العيادة أولًا، ثم الطبيب المرتبط بها. الحقلان مطلوبان.">
      <div><Picker name="clinic_id" label="العيادة" path={`blood-bank/clinics?facility_id=${facilityId}`} selected={clinic} onSelect={c => { setClinic(c); change("clinic_id", String(c.id)); if (c.id !== clinic?.id) { setDoctor(null); change("responsible_staff_id", ""); } }} />{fieldError("clinic_id")}</div>
      {clinic ? <div><Picker name="responsible_staff_id" key={clinic.id} label="الطبيب المسؤول" path={`blood-bank/doctors?facility_id=${facilityId}&clinic_id=${clinic.id}`} selected={doctor} onSelect={d => { setDoctor(d); change("responsible_staff_id", String(d.id)); }} emptyMessage="لا يوجد أطباء مؤهلون مرتبطون حاليًا بالعيادة يطابقون البحث." />{fieldError("responsible_staff_id")}{options.can_add_doctor && <div className={layout.secondaryActions}><button type="button" className={styles.secondary} onClick={() => setInline(true)}>إضافة طبيب</button><p className={styles.hint}>تُحفظ مسودة الواقعة أثناء إضافة الطبيب.</p></div>}</div> : <p className={styles.hint}>تظهر خيارات الطبيب بعد اختيار العيادة.</p>}
      </FormSection>
      <FormSection number="04" title="فحوص الواقعة" hint="اختيارية؛ أضف حالة الفحص عند الحاجة."><ScreeningFields draft={draft} profile={base} change={change} fieldError={fieldError} /></FormSection>
    </fieldset>
    <FormActions busy={busy} disabled={busy || conflict || !draft.clinic_id || !draft.responsible_staff_id || (source === "existing" && (!current.data || current.loading || !!current.error))} hint={conflict ? "راجع تعارض النسخة قبل الحفظ" : source === "existing" && (!current.data || current.loading || current.error) ? "اختر الشخص وانتظر اكتمال تحميل بياناته" : !draft.clinic_id ? "اختر العيادة لإتاحة الحفظ" : !draft.responsible_staff_id ? "اختر الطبيب المسؤول لإتاحة الحفظ" : undefined} label="حفظ الواقعة" onClose={onClose} />
  </form></Modal>{inline && clinic && <InlineDoctor facilityId={facilityId} clinic={clinic} onClose={() => setInline(false)} onSelected={d => { setDoctor(d); change("responsible_staff_id", String(d.id)); setInline(false); }} />}</>;
}
