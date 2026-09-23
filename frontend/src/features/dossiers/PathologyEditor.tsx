"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError, subscribeSession } from "../auth/api";
import Modal from "../clinics/Modal";
import { ClinicalContext } from "./ClinicalEditor";
import type { Context } from "./clinical";
import type { Visit } from "./api";
import { doctorRoleLabel, normalizePathologyDraft, pathologyFields, type Pathology } from "./pathology";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

export default function PathologyEditor({ facility, dossier, visit, record, onClose, onSaved, onRefresh }: { facility: number; dossier: number; visit: Visit; record: Pathology | null; caps: Record<string, boolean>; onClose: () => void; onSaved: () => void; onRefresh: () => void }) {
  const strings = (row: Pathology | null) => Object.fromEntries(Object.keys(pathologyFields).map(k => [k, String(row?.[k] ?? "")]));
  const [draft, setDraft] = useState<Record<string, string>>(() => normalizePathologyDraft({ ...strings(record), status: "completed", ...!record ? { source: "internal", status: "completed" } : {} }));
  const [version, setVersion] = useState(record?.lock_version ?? 1);
  const [context, setContext] = useState<Context>(() => ({ clinic: record?.clinic_id ? { id: Number(record.clinic_id), name_ar: String(record.clinic_name ?? "العيادة المحفوظة") } : null, doctor: record?.doctor_id ? { id: Number(record.doctor_id), name_ar: String(record.doctor_name ?? "الطبيب المحفوظ") } : null }));
  const [busy, setBusy] = useState(false), [error, setError] = useState<AuthError | null>(null), [conflict, setConflict] = useState(false), [latest, setLatest] = useState<Pathology | null>(null), [choices, setChoices] = useState<Record<string, boolean>>({});
  const pending = useRef<AbortController | null>(null), reservation = useRef<{ body: string; id: string } | null>(null), form = useRef<HTMLFormElement>(null);
  const close = useRef(onClose);
  useEffect(() => { close.current = onClose; }, [onClose]);
  useEffect(() => { const unsubscribe = subscribeSession(() => { pending.current?.abort(); close.current(); }); return () => { unsubscribe(); pending.current?.abort(); }; }, []);
  const path = `dossiers/${dossier}/visits/${visit.id}/pathology${record ? `/${record.id}` : ""}`;
  const doctorLabel = doctorRoleLabel(draft.source);
  function change(key: string, value: string) { setDraft(d => normalizePathologyDraft({ ...d, [key]: value })); }
  function fieldError(key: string) { return error?.fields[key] ? <small className={styles.fieldError} role="alert">{error.fields[key]}</small> : null; }
  async function refresh() {
    if (pending.current) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null);
    try { const current = await apiRequest<Pathology>(`${path}?facility_id=${facility}`, { signal: c.signal }); if (!c.signal.aborted) { setLatest(current); setChoices({}); onRefresh(); } }
    catch (e) { if (!c.signal.aborted) setError(e as AuthError); }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  function applyReview() {
    if (!latest) return;
    const current = strings(latest), next = { ...current };
    for (const key of Object.keys(pathologyFields)) if (choices[key]) next[key] = draft[key];
    if (choices.clinic_id || choices.doctor_id) { next.clinic_id = draft.clinic_id; next.doctor_id = draft.doctor_id; }
    next.status = "completed";
    setDraft(normalizePathologyDraft(next)); setVersion(latest.lock_version); setLatest(null); setConflict(false); setError(null);
    setContext({ clinic: next.clinic_id ? { id: Number(next.clinic_id), name_ar: String(latest.clinic_name ?? "العيادة المختارة بعد المراجعة") } : null, doctor: next.doctor_id ? { id: Number(next.doctor_id), name_ar: String(latest.doctor_name ?? "الطبيب المختار بعد المراجعة") } : null });
  }
  async function save() {
    if (pending.current || conflict) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null);
    const values = Object.fromEntries(Object.entries(normalizePathologyDraft(draft)).map(([k, v]) => [k, v === "" ? null : k.endsWith("_id") ? Number(v) : v]));
    const body = JSON.stringify({ ...values, facility_id: facility, lock_version: version, status: "completed" });
    if (reservation.current?.body !== body) reservation.current = { body, id: crypto.randomUUID() };
    try { await apiRequest(path, { method: record ? "PUT" : "POST", signal: c.signal, body: JSON.stringify({ ...JSON.parse(body), request_id: reservation.current.id }) }); if (!c.signal.aborted) onSaved(); }
    catch (e) { if (!c.signal.aborted) { const failure = e as AuthError; setError(failure); if (failure.status === 409) setConflict(true); else requestAnimationFrame(() => { const name = Object.keys(failure.fields ?? {})[0]; const element = Array.from(form.current?.querySelectorAll<HTMLElement>("[name]") ?? []).find(el => el.getAttribute("name") === name); element?.focus(); element?.scrollIntoView({ block: "center", behavior: "instant" }); }); } }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  const visible = ["source", "external_organization", "report_number", "result_on", "conclusion", "note"];
  const extra = ["specimen_type", "anatomical_site", "requested_on", "collected_on"];
  return <Modal title={record ? "تعديل تقرير التشريح المرضي" : "إضافة تقرير تشريح مرضي"} size="wide" busy={busy} onClose={onClose}><form ref={form} className={`${styles.form} ${layout.editorForm}`} noValidate onSubmit={e => { e.preventDefault(); void save(); }}>
    <p className={styles.hint}>الزيارة {visit.visit_no} · {visit.visit_date}. سجّل تقريرًا موجودًا ونتيجته. إن كان ضمن المشفى اختر الطبيب المنظم، وإن كان من جهة خارجية اذكر اسم الجهة والطبيب المراجع في المشفى. المرفقات تُضاف في قسم المرفقات.</p>
    {error && <p role="alert">{error.message || "تعذّر الحفظ. مسودتك محفوظة."}</p>}
    {conflict && <section className={layout.diagnosis}><h3>تغيّرت البيانات؛ راجع أحدث نسخة ومسودتك</h3><button type="button" className={styles.secondary} disabled={busy} onClick={() => void refresh()}>جلب أحدث نسخة</button>{latest && <><p>اختر الحقول التي تريد تطبيقها من مسودتك. الحقول الأخرى تبقى وفق أحدث نسخة. هذه المراجعة لا تحفظ تلقائيًا.</p>{Object.entries(pathologyFields).filter(([key]) => !["status", "unavailable_reason"].includes(key)).map(([key, label]) => <label key={key}><input type="checkbox" checked={!!choices[key]} onChange={e => setChoices(c => ({ ...c, [key]: e.target.checked }))} />{label}<small>الأحدث: {String(latest[key] ?? "غير مسجل")} · مسودتي: {draft[key] || "غير مسجل"}</small></label>)}<button type="button" className={styles.secondary} disabled={!!latest.voided_at} onClick={applyReview}>اعتماد الاختيارات للمراجعة قبل الحفظ</button>{!!latest.voided_at && <p>التقرير ملغى؛ تبقى مسودتك ظاهرة ولا يمكن تعديل هذا التقرير.</p>}</>}</section>}
    <fieldset disabled={busy || conflict} className={layout.diagnosis}><legend>بيانات التقرير</legend><div className={styles.fields}>
      {visible.map(key => {
        const label = pathologyFields[key];
        if (key === "external_organization" && draft.source !== "external") return null;
        const options = key === "source" ? { internal: "ضمن المشفى", external: "من جهة خارجية" } : null;
        return <label key={key} className={key === "conclusion" || key === "note" ? styles.full : undefined}>{label}{key === "source" || key === "external_organization" || key === "result_on" || key === "conclusion" ? " *" : ""}{options ? <select name={key} value={draft[key] ?? ""} onChange={e => change(key, e.target.value)}>{Object.entries(options).map(([k, text]) => <option key={k} value={k}>{text}</option>)}</select> : key === "conclusion" || key === "note" ? <textarea name={key} rows={key === "conclusion" ? 4 : 3} value={draft[key] ?? ""} onChange={e => change(key, e.target.value)} /> : <input name={key} type={key.endsWith("_on") ? "date" : "text"} value={draft[key] ?? ""} onChange={e => change(key, e.target.value)} />}{fieldError(key)}</label>;
      })}
      <details className={`${layout.optionalGroup} ${styles.full}`}><summary>تفاصيل إضافية (اختياري)</summary><div className={styles.fields}>
        {extra.map(key => <label key={key} className={key === "note" ? styles.full : undefined}>{pathologyFields[key]}{key === "note" ? <textarea name={key} rows={3} value={draft[key] ?? ""} onChange={e => change(key, e.target.value)} /> : <input name={key} type={key.endsWith("_on") ? "date" : "text"} value={draft[key] ?? ""} onChange={e => change(key, e.target.value)} />}{fieldError(key)}</label>)}
        <label>الإجراء المرتبط (اختياري)<select name="procedure_event_id" value={draft.procedure_event_id} onChange={e => change("procedure_event_id", e.target.value)}><option value="">دون إجراء مرتبط</option>{visit.clinical?.procedures.map(p => <option key={p.id} value={p.id}>{p.name_ar} · {p.performed_on}</option>)}</select>{fieldError("procedure_event_id")}</label>
      </div></details>
      <ClinicalContext value={context} change={ctx => { setContext(ctx); setDraft(d => ({ ...d, clinic_id: String(ctx.clinic?.id ?? ""), doctor_id: String(ctx.doctor?.id ?? "") })); }} facility={facility} date={visit.visit_date} prefix="" label="التقرير" doctorLabel={doctorLabel} error={fieldError} />
    </div></fieldset>
    <div className={styles.formActions}><button className={styles.primary} disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : "حفظ"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
