"use client";

import { useEffect, useState } from "react";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { useClinicRequest } from "../clinics/api";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { identityLabels, statusLabel, useReviewWrite } from "./review";
import styles from "../clinics/clinics.module.css";

type Snapshot = { id: number; code: string; patient_version: number; dossier_version: number; values: Record<string, string | null>;
  correction: { can_correct: boolean; remaining_seconds: number; editable_fields: string[]; reasons: string[] };
  requests: { id: number; status: string; reason: string; decision_reason: string | null }[] };

export default function IdentityPanel({ facility, id }: { facility: number; id: number }) {
  const result = useClinicRequest<Snapshot>(`reception/cards/${id}/identity?facility_id=${facility}`, false, true);
  return <section className={styles.panel} aria-label="تصحيح الهوية وطلبات المراجعة">
    <h3>تصحيح الهوية الشخصية</h3>
    {result.error && <p role="alert">{result.error}</p>}
    {!result.data ? <p role="status">جارٍ تحميل صلاحية التصحيح…</p> : <Editor data={result.data} facility={facility} refresh={result.retry} />}
    <button className={styles.secondary} onClick={result.retry} disabled={result.loading}>جلب أحدث نسخة</button>
  </section>;
}

function Editor({ data, facility, refresh }: { data: Snapshot; facility: number; refresh: () => void }) {
  const { access } = useIdentity();
  const permissions = access.find(e => e.facility.id === facility)?.permissions || [];
  const [draft, setDraft] = useState(data.values);
  const [selected, setSelected] = useState<string[]>([]);
  const [versions, setVersions] = useState({ patient_version: data.patient_version, dossier_version: data.dossier_version });
  const [reason, setReason] = useState("");
  const [message, setMessage] = useState("");
  const [elapsed, setElapsed] = useState(0);
  const write = useReviewWrite();
  useEffect(() => { const started = performance.now(); const timer = setInterval(() => setElapsed(Math.floor((performance.now() - started) / 1000)), 1000); return () => clearInterval(timer); }, [data]);
  const remaining = Math.max(0, data.correction.remaining_seconds - elapsed);
  const stale = versions.patient_version !== data.patient_version || versions.dossier_version !== data.dossier_version;
  const ready = !write.busy && !stale && selected.length > 0 && reason.trim().length > 0;
  async function submit(direct: boolean) {
    if (!ready) return;
    const changes = Object.fromEntries(selected.map(key => [key, draft[key] || null]));
    const saved = await write.save(`reception/cards/${data.id}/${direct ? "correct" : "corrections"}`, { facility_id: facility, ...versions, changes, reason });
    if (saved) { setMessage(direct ? "حُفظ التصحيح. المهلة الأصلية لا تتجدد." : "أُرسل الطلب للمراجعة دون تغيير الهوية."); setSelected([]); refresh(); }
  }
  return <>
    <p role="status">{data.correction.can_correct && remaining ? `المتبقي للتصحيح المباشر: ${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, "0")}` : "التصحيح يحتاج طلب مراجعة."}</p>
    {data.correction.reasons.map(text => <p className={styles.hint} key={text}>{text}</p>)}
    <p className={styles.hint}>اختر الحقول التي تريد تصحيحها فقط. لا يتيح هذا القسم قراءة أو تعديل التاريخ الطبي.</p>
    {stale && <p role="alert">تغيّرت النسخة. راجع القيم الحالية ومسودتك، ثم اعتمد النسخة المعروضة واختر الحقول مجددًا.</p>}
    {stale && <button className={styles.secondary} disabled={write.busy} onClick={() => { setVersions({ patient_version: data.patient_version, dossier_version: data.dossier_version }); setSelected([]); }}>اعتماد النسخة المعروضة بعد المراجعة</button>}
    <fieldset disabled={write.busy}><div className={styles.fields}>{Object.entries(identityLabels).map(([key, label]) => <label key={key}>
      <span><input type="checkbox" checked={selected.includes(key)} onChange={e => setSelected(s => e.target.checked ? [...s, key] : s.filter(k => k !== key))} /> تصحيح {label}</span>
      {key === "gender" || key === "birth_date_accuracy" ? <select aria-label={label} value={draft[key] || "unknown"} onChange={e => setDraft(d => ({ ...d, [key]: e.target.value }))}>{(key === "gender" ? [["unknown", "غير معروف"], ["male", "ذكر"], ["female", "أنثى"]] : [["unknown", "غير معروف"], ["exact", "دقيق"], ["year_only", "السنة فقط"], ["estimated", "تقديري"]]).map(([value, text]) => <option value={value} key={value}>{text}</option>)}</select>
        : <input aria-label={label} type={key === "birth_date" ? "date" : "text"} value={draft[key] || ""} onChange={e => setDraft(d => ({ ...d, [key]: e.target.value }))} />}
      <small>القيمة الحالية: {data.values[key] || "غير مسجلة"}</small>
    </label>)}</div><label>سبب التصحيح<textarea value={reason} maxLength={255} onChange={e => setReason(e.target.value)} /></label></fieldset>
    {write.error && <p role="alert">{write.error}</p>}{message && <p role="status">{message}</p>}
    <div className={styles.actions}>
      {permissions.includes("reception.correct") && <button className={styles.primary} disabled={!ready || !data.correction.can_correct || !remaining || selected.some(k => !data.correction.editable_fields.includes(k))} onClick={() => void submit(true)}>حفظ التصحيح خلال المهلة</button>}
      {permissions.includes("reception.corrections.request") && <button className={styles.secondary} disabled={!ready} onClick={() => void submit(false)}>إرسال طلب تصحيح</button>}
    </div>
    <DirectoryTable label="حالة طلبات التصحيح" headers={["الطلب", "الحالة", "السبب", "قرار المراجعة"]}>{data.requests.map(q => <tr key={q.id}><td>{q.id}</td><td>{statusLabel(q.status)}</td><td>{q.reason}</td><td>{q.decision_reason || "لم يُتخذ قرار بعد"}</td></tr>)}</DirectoryTable>
  </>;
}
