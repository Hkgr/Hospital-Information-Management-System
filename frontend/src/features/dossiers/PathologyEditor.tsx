"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError, subscribeSession } from "../auth/api";
import { useClinicRequest, type Page } from "../clinics/api";
import Modal from "../clinics/Modal";
import Picker from "../blood-bank/Picker";
import { ClinicalContext } from "./ClinicalEditor";
import { Pagination } from "../directory/Controls";
import type { Context } from "./clinical";
import type { Visit } from "./api";
import { assessmentFields, dispositionLabels, pathologyFields, pathologyLabels, type Assessment, type Pathology, type PathologyFile } from "./pathology";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

type Saved = Pathology | Assessment;
export default function PathologyEditor({ facility, dossier, visit, record, assessment = false, caps, onClose, onSaved, onRefresh }: { facility: number; dossier: number; visit: Visit; record: Saved | null; assessment?: boolean; caps: Record<string, boolean>; onClose: () => void; onSaved: () => void; onRefresh: () => void }) {
  const labels = assessment ? assessmentFields : pathologyFields;
  const strings = (row: Saved | null) => Object.fromEntries(Object.keys(labels).map(k => [k, String(row?.[k] ?? "")]));
  const [draft, setDraft] = useState<Record<string, string>>(() => ({ ...strings(record), ...!record ? assessment ? { disposition: "not_assessed" } : { source: "internal", status: "requested" } : {} }));
  const [version, setVersion] = useState(record?.lock_version ?? (assessment ? 0 : 1));
  const [context, setContext] = useState<Context>(() => ({ clinic: record?.clinic_id ? { id: Number(record.clinic_id), name_ar: "العيادة المحفوظة" } : null, doctor: record?.doctor_id ? { id: Number(record.doctor_id), name_ar: "الطبيب المحفوظ" } : null }));
  const [files, setFiles] = useState<PathologyFile[]>([]), [filePage, setFilePage] = useState(1);
  const [busy, setBusy] = useState(false), [error, setError] = useState<AuthError | null>(null), [conflict, setConflict] = useState(false), [latest, setLatest] = useState<Saved | null>(null), [choices, setChoices] = useState<Record<string, boolean>>({});
  const pending = useRef<AbortController | null>(null), reservation = useRef<{ body: string; id: string } | null>(null), form = useRef<HTMLFormElement>(null);
  const close = useRef(onClose);
  useEffect(() => { close.current = onClose; }, [onClose]);
  useEffect(() => { const unsubscribe = subscribeSession(() => { pending.current?.abort(); close.current(); }); return () => { unsubscribe(); pending.current?.abort(); }; }, []);
  const path = `dossiers/${dossier}/visits/${visit.id}/${assessment ? "diagnostic-assessment" : `pathology${record ? `/${record.id}` : ""}`}`;
  const attachments = useClinicRequest<Page<PathologyFile>>(!assessment && caps.attachments_view ? `dossiers/${dossier}/attachments?facility_id=${facility}&visit_id=${visit.id}&page=${filePage}&per_page=10` : null, true);
  function change(key: string, value: string) { setDraft(d => ({ ...d, [key]: value })); }
  function fieldError(key: string) { return error?.fields[key] ? <small className={styles.fieldError} role="alert">{error.fields[key]}</small> : null; }
  async function refresh() {
    if (pending.current) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null);
    try { const current = await apiRequest<Saved>(`${path}?facility_id=${facility}`, { signal: c.signal }); if (!c.signal.aborted) { setLatest(current); setChoices({}); onRefresh(); } }
    catch (e) { if (!c.signal.aborted) setError(e as AuthError); }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  function applyReview() {
    if (!latest) return;
    const current = strings(latest), next = { ...current };
    for (const key of Object.keys(labels)) if (choices[key]) next[key] = draft[key];
    if (choices.clinic_id || choices.doctor_id) { next.clinic_id = draft.clinic_id; next.doctor_id = draft.doctor_id; }
    setDraft(next); setVersion(latest.lock_version); setLatest(null); setConflict(false); setError(null);
    setContext({ clinic: next.clinic_id ? { id: Number(next.clinic_id), name_ar: "العيادة المختارة بعد المراجعة" } : null, doctor: next.doctor_id ? { id: Number(next.doctor_id), name_ar: "الطبيب المختار بعد المراجعة" } : null });
  }
  async function save() {
    if (pending.current || conflict) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null);
    const values = Object.fromEntries(Object.entries(draft).map(([k, v]) => [k, v === "" ? null : k.endsWith("_id") ? Number(v) : v]));
    const body = JSON.stringify({ ...values, facility_id: facility, lock_version: version, ...!assessment ? { attachment_ids: files.map(f => f.id) } : {} });
    if (reservation.current?.body !== body) reservation.current = { body, id: crypto.randomUUID() };
    try { await apiRequest(path, { method: assessment || record ? "PUT" : "POST", signal: c.signal, body: JSON.stringify({ ...JSON.parse(body), request_id: reservation.current.id }) }); if (!c.signal.aborted) onSaved(); }
    catch (e) { if (!c.signal.aborted) { const failure = e as AuthError; setError(failure); if (failure.status === 409) setConflict(true); else requestAnimationFrame(() => { const name = Object.keys(failure.fields ?? {})[0]; const element = Array.from(form.current?.querySelectorAll<HTMLElement>("[name]") ?? []).find(el => el.getAttribute("name") === name); element?.focus(); element?.scrollIntoView({ block: "center", behavior: "instant" }); }); } }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  const dates = ["requested_on", "collected_on", "result_on", "assessed_on"];
  const textarea = ["conclusion", "note", "required_reason", "not_required_reason", "follow_up", "unavailable_reason"];
  return <Modal title={assessment ? "التقييم التشخيصي" : record ? "تعديل تقرير التشريح المرضي" : "إضافة تقرير تشريح مرضي"} size="wide" busy={busy} onClose={onClose}><form ref={form} className={styles.form} noValidate onSubmit={e => { e.preventDefault(); void save(); }}>
    <p className={styles.hint}>الزيارة {visit.visit_no} · {visit.visit_date}. التواريخ غير المعروفة تبقى فارغة؛ التقرير الخارجي قد يسبق فتح الملف الطبي. لا يُنشأ إجراء أو خدمة تلقائيًا.</p>
    {error && <p role="alert">{error.message || "تعذّر الحفظ. مسودتك محفوظة."}</p>}
    {conflict && <section className={layout.diagnosis}><h3>تغيّرت البيانات؛ راجع أحدث نسخة ومسودتك</h3><button type="button" className={styles.secondary} disabled={busy} onClick={() => void refresh()}>جلب أحدث نسخة</button>{latest && <><p>اختر الحقول التي تريد تطبيقها من مسودتك. الحقول الأخرى تبقى وفق أحدث نسخة. هذه المراجعة لا تحفظ تلقائيًا.</p>{Object.entries(labels).map(([key, label]) => <label key={key}><input type="checkbox" checked={!!choices[key]} onChange={e => setChoices(c => ({ ...c, [key]: e.target.checked }))} />{label}<small>الأحدث: {String(latest[key] ?? "غير مسجل")} · مسودتي: {draft[key] || "غير مسجل"}</small></label>)}<button type="button" className={styles.secondary} disabled={!!latest.voided_at} onClick={applyReview}>اعتماد الاختيارات للمراجعة قبل الحفظ</button>{!!latest.voided_at && <p>التقرير ملغى؛ تبقى مسودتك ظاهرة ولا يمكن تعديل هذا التقرير.</p>}</>}</section>}
    <fieldset disabled={busy || conflict} className={layout.diagnosis}><legend>{assessment ? "قرار الطبيب" : "بيانات التقرير"}</legend><div className={styles.fields}>
      {Object.entries(labels).filter(([key]) => !["clinic_id", "doctor_id", "procedure_event_id", "evidence_pathology_id"].includes(key)).map(([key, label]) => {
        const options = key === "source" ? { internal: "ضمن المشفى", external: "من جهة خارجية" } : key === "status" ? pathologyLabels : key === "disposition" ? Object.fromEntries(Object.entries(dispositionLabels).filter(([k]) => !["unavailable", "cancelled"].includes(k))) : null;
        if (key === "external_organization" && draft.source !== "external") return null;
        if (key === "required_reason" && draft.disposition !== "pathology_required") return null;
        if (key === "not_required_reason" && draft.disposition !== "pathology_not_required") return null;
        if (key === "unavailable_reason" && !["unavailable", "cancelled"].includes(draft.status)) return null;
        return <label key={key} className={textarea.includes(key) ? styles.full : undefined}>{label}{options ? <select name={key} value={draft[key]} onChange={e => change(key, e.target.value)}>{Object.entries(options).map(([k, text]) => <option key={k} value={k}>{text}</option>)}</select> : textarea.includes(key) ? <textarea name={key} rows={3} value={draft[key]} onChange={e => change(key, e.target.value)} /> : <input name={key} type={dates.includes(key) ? "date" : "text"} value={draft[key]} onChange={e => change(key, e.target.value)} />}{fieldError(key)}</label>;
      })}
      {assessment && draft.disposition === "pathology_confirmed" && <div className={styles.full}><Picker name="evidence_pathology_id" label="التقرير المكتمل الداعم" path={`dossiers/${dossier}/pathology?facility_id=${facility}&evidence_only=1`} selected={draft.evidence_pathology_id ? { id: Number(draft.evidence_pathology_id), name_ar: `التقرير ${draft.evidence_pathology_id}` } : null} onSelect={r => change("evidence_pathology_id", String(r.id))} />{fieldError("evidence_pathology_id")}</div>}
      {assessment && draft.disposition === "referred_out" && <p className={styles.hint}>سجّل الوجهة وتاريخ الإحالة وسبب عدم توفر الفحص عبر نتيجة الزيارة الحالية؛ لا ينشئ هذا الاختيار إحالة ثانية.</p>}
      {!assessment && <label>الإجراء المرتبط (اختياري)<select name="procedure_event_id" value={draft.procedure_event_id} onChange={e => change("procedure_event_id", e.target.value)}><option value="">دون إجراء مرتبط</option>{visit.clinical?.procedures.map(p => <option key={p.id} value={p.id}>{p.name_ar} · {p.performed_on}</option>)}</select>{fieldError("procedure_event_id")}</label>}
      <ClinicalContext value={context} change={ctx => { setContext(ctx); setDraft(d => ({ ...d, clinic_id: String(ctx.clinic?.id ?? ""), doctor_id: String(ctx.doctor?.id ?? "") })); }} facility={facility} date={visit.visit_date} prefix="" label="التقييم أو التقرير (اختياري)" error={fieldError} />
      <button type="button" className={styles.textButton} onClick={() => { setContext({ clinic: null, doctor: null }); setDraft(d => ({ ...d, clinic_id: "", doctor_id: "" })); }}>إزالة المسؤول الاختياري</button>
    </div></fieldset>
    {!assessment && caps.attachments_view && <section className={layout.diagnosis}><h3>المرفقات الداعمة من هذه الزيارة</h3><p>المرفقات اختيارية. لا تُحذف الروابط السابقة عند إغفالها.</p>{files.map(f => <p key={f.id}>{f.title}<button type="button" className={styles.textButton} onClick={() => setFiles(old => old.filter(x => x.id !== f.id))}>إزالة من الاختيارات الجديدة</button></p>)}{attachments.loading && <p role="status">جارٍ تحميل المرفقات…</p>}{attachments.error && <p role="alert">{attachments.error}<button type="button" onClick={attachments.retry}>إعادة المحاولة</button></p>}{attachments.data?.data.map(f => <label key={f.id}><input type="checkbox" disabled={busy || conflict} checked={files.some(x => x.id === f.id)} onChange={e => setFiles(old => e.target.checked ? [...old, f] : old.filter(x => x.id !== f.id))} />{f.title} · {f.original_filename}</label>)}{attachments.data && <Pagination meta={attachments.data.meta} onPage={setFilePage} />}{fieldError("attachment_ids")}</section>}
    <div className={styles.formActions}><button className={styles.primary} disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : "حفظ"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
