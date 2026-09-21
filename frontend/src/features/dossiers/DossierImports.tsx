"use client";

import Link from "next/link";
import { useSearchParams, useRouter } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest, AuthError, getToken } from "../auth/api";
import { downloadReport, useClinicRequest } from "../clinics/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack, DirectoryTable } from "../directory/DirectoryPrimitives";
import styles from "../clinics/clinics.module.css";
import local from "./imports.module.css";

type ImportRow = { id: number; sheet: string; row_number: number; source_record_id: string; local_patient_ref: string; local_visit_ref: string | null; status: string; action: string | null; errors: Record<string, string[]>; match: { canonical_code?: string | null; match_type?: string }; dossier_id: number | null };
type Batch = { id: number; status: string; purpose: string; cutover_date: string; lock_version: number; total_rows: number; counts: Record<string, number>; actions: Record<string, number>; sheets: Record<string, number>; matches: Record<string, number>; pre_cutover_patients: number; rows: { data: ImportRow[]; current_page: number; last_page: number; total: number } };
const states: Record<string, string> = { uploaded: "مرفوعة — لم تُفحص", validating: "الفحص قيد الاستكمال", needs_review: "تحتاج مراجعة", validated: "اكتملت المعاينة", committing: "الاعتماد قيد الاستكمال", completed: "مكتملة", completed_with_errors: "مكتملة جزئيًا", failed: "تعذرت المعالجة", cancelled: "ملغاة", pending: "بانتظار الفحص", valid: "صالحة للمعاينة", committed: "محفوظة", skipped: "معتمدة سابقًا — لم تتكرر", error: "خطأ" };
const matchLabels: Record<string, string> = { canonical: "مطابقة بالكود النظامي", alias_or_source: "مطابقة بمعرّف تاريخي أو مصدر محفوظ", new: "هويات جديدة" };
const actions: Record<string, string> = { new_patient: "مريض وبطاقة جديدان", new_dossier: "بطاقة منشأة لهوية موجودة", reuse_dossier: "إعادة استخدام البطاقة", new_fact: "واقعة صريحة جديدة", skip: "تجاوز مصدر محفوظ" };

export default function DossierImports() {
  const { access, user } = useIdentity();
  const params = useSearchParams();
  const { entry } = directoryFacility(access, "dossiers.view", params.get("facility_id"));
  if (!entry || !entry.permissions.includes("dossiers.import.view")) return <section className={styles.status}><h2>الاستيراد غير متاح</h2><p role="alert">لا يتوفر تفويض لاستيراد بطاقات المرضى في المشفى المحدد.</p></section>;
  return <Workspace key={`${user.id}:${entry.facility.id}`} facility={entry.facility.id} name={entry.facility.name_ar} permissions={entry.permissions} />;
}

function Workspace({ facility, name, permissions }: { facility: number; name: string; permissions: string[] }) {
  const router = useRouter(), params = useSearchParams();
  const batchId = params.get("batch"), page = params.get("page") ?? "1", filter = params.get("status") ?? "", action = params.get("action") ?? "", batchesPage = params.get("batches_page") ?? "1";
  const [purpose, setPurpose] = useState("legacy_migration"), [cutover, setCutover] = useState("2026-09-01");
  const [file, setFile] = useState<File | null>(null), [busy, setBusy] = useState(false), [progress, setProgress] = useState<number | null>(null), [error, setError] = useState(""), [confirmedBatch, setConfirmedBatch] = useState<string | null>(null);
  const current = useRef<AbortController | null>(null), fileInput = useRef<HTMLInputElement>(null), message = useRef<HTMLParagraphElement>(null);
  const confirmed = confirmedBatch === batchId && batchId !== null;
  const selectedContext = `${batchId}:${page}:${filter}:${action}`;
  useEffect(() => () => current.current?.abort(), [selectedContext]);
  const batches = useClinicRequest<{ data: Pick<Batch, "id" | "status" | "purpose" | "cutover_date">[]; current_page: number; last_page: number }>(`dossiers/imports?facility_id=${facility}&page=${batchesPage}`);
  const batch = useClinicRequest<Batch>(batchId && /^[1-9]\d*$/.test(batchId) ? `dossiers/imports/${batchId}?facility_id=${facility}&page=${page}${filter ? `&status=${filter}` : ""}${action ? `&action=${action}` : ""}` : null);
  const can = (action: string) => permissions.includes(`dossiers.import.${action}`);
  function navigate(id: number, nextPage = "1", status = "", nextAction = action) {
    current.current?.abort();
    const q = new URLSearchParams({ facility_id: String(facility), batch: String(id), page: nextPage });
    if (status) q.set("status", status);
    if (nextAction) q.set("action", nextAction);
    if (batchesPage !== "1") q.set("batches_page", batchesPage);
    router.push(`/patient-cards/imports?${q}`);
  }
  function fail(e: unknown) {
    setError(e instanceof AuthError ? Object.values(e.fields)[0] ?? e.message : e instanceof Error ? e.message : "تعذرت العملية؛ لم تُفقد الدفعة. أعد تحميلها ثم استكمل.");
    requestAnimationFrame(() => message.current?.focus());
  }
  async function download(path: string) {
    if (current.current || !can("download")) return;
    const c = new AbortController(); current.current = c; setBusy(true); setError("");
    try { await downloadReport(path, c.signal); } catch (e) { if (!c.signal.aborted) fail(e); }
    finally { if (current.current === c) { current.current = null; setBusy(false); } }
  }
  async function upload() {
    if (current.current || !can("create")) return;
    if (!file || !cutover) { setError("اختر ملف XLSX وتاريخ الانتقال قبل الرفع."); fileInput.current?.focus(); return; }
    if (!file.name.toLowerCase().endsWith(".xlsx") || file.size > 10 * 1024 * 1024) { setError("يُقبل XLSX حتى 10 MiB فقط."); fileInput.current?.focus(); return; }
    const c = new AbortController(), token = getToken(); current.current = c; setBusy(true); setError(""); setProgress(0);
    try {
      const saved = await new Promise<Batch>((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open("POST", "/hospital-api/dossiers/imports"); xhr.timeout = 120000;
        xhr.setRequestHeader("Accept", "application/json"); if (token) xhr.setRequestHeader("Authorization", `Bearer ${token}`);
        xhr.upload.onprogress = e => { if (!c.signal.aborted && e.lengthComputable) setProgress(Math.round(e.loaded / e.total * 100)); };
        xhr.onload = () => {
          if (c.signal.aborted || getToken() !== token) return reject(new Error("تغيّرت الجلسة؛ افتح الدفعة من جلسة مخولة."));
          let response: { data?: Batch; errors?: { file?: string[] }; error?: { message?: string } };
          try { response = JSON.parse(xhr.responseText); } catch { return reject(new Error("تعذرت قراءة نتيجة الرفع. راجع قائمة الدفعات قبل إعادة المحاولة.")); }
          if (xhr.status >= 200 && xhr.status < 300 && response.data) resolve(response.data);
          else reject(new Error(response.errors?.file?.[0] ?? response.error?.message ?? "تعذر رفع الملف. تحقق من التفويض والمنشأة وبيانات القالب."));
        };
        xhr.onerror = xhr.ontimeout = () => reject(new Error("انقطع الاتصال أثناء الرفع. راجع قائمة الدفعات؛ إعادة الملف نفسه لا تضاعف الاستيراد."));
        xhr.onabort = () => reject(new Error("أُلغي انتظار الرفع."));
        c.signal.addEventListener("abort", () => xhr.abort(), { once: true });
        const form = new FormData(); form.append("file", file); form.append("facility_id", String(facility)); form.append("purpose", purpose); form.append("cutover_date", cutover); xhr.send(form);
      });
      if (!c.signal.aborted) { batches.retry(); navigate(saved.id); }
    } catch (e) { if (!c.signal.aborted) fail(e); }
    finally { if (current.current === c) { current.current = null; setBusy(false); setProgress(null); } }
  }
  async function process(operation: "validate" | "commit" | "cancel") {
    if (current.current || !batch.data || batch.loading || batch.error || !can(operation) || operation === "commit" && !confirmed) return;
    const c = new AbortController(); current.current = c; setBusy(true); setError("");
    try {
      await apiRequest<Batch>(`dossiers/imports/${batch.data.id}/${operation}`, { method: "POST", signal: c.signal, body: JSON.stringify({ facility_id: facility, lock_version: batch.data.lock_version, confirm: operation === "commit" ? confirmed : undefined }) });
      if (!c.signal.aborted) { batch.retry(); batches.retry(); }
    } catch (e) { if (!c.signal.aborted) { fail(e); batch.retry(); } }
    finally { if (current.current === c) { current.current = null; setBusy(false); } }
  }
  const b = batch.data;
  return <div className={styles.screen}>
    <DirectoryBack href={`/patient-cards?facility_id=${facility}`}>بطاقات المرضى</DirectoryBack>
    <header className={styles.heading}><div><h2>استيراد بطاقات المرضى</h2><p>{name} · معاينة قبل الاعتماد</p></div></header>
    <section className={`${styles.panel} ${local.section}`} aria-labelledby="import-template-heading"><h3 id="import-template-heading">١. القالب وبيانات الدفعة</h3><p>اختر الغرض وتاريخ الانتقال للنظام. لا ينشئ رفع الملف أو فحصه أي بطاقة أو زيارة. لا تُحوَّل حقول الورم إلى خطة أو جرعة علاجية.</p>
      <div className={local.fields}><label>الغرض<select value={purpose} disabled={busy} onChange={e => setPurpose(e.target.value)}><option value="legacy_migration">نقل ملفات سابقة</option><option value="offline_capture">تسجيل أثناء انقطاع الاتصال</option></select></label><label>تاريخ الانتقال للنظام<input type="date" value={cutover} disabled={busy} onChange={e => setCutover(e.target.value)} required /></label></div>
      {can("download") && <button className={styles.secondary} disabled={busy || !cutover} onClick={() => void download(`dossiers/import-template.xlsx?${new URLSearchParams({ facility_id: String(facility), purpose, cutover_date: cutover })}`)}>تنزيل قالب Excel الحالي</button>}
      {can("create") && <div className={local.upload}><label>٢. ملف XLSX المعبأ<input ref={fileInput} type="file" accept=".xlsx" disabled={busy} onChange={e => setFile(e.target.files?.[0] ?? null)} /></label><span className={styles.hint}>حتى 5000 مريض و30000 صف. استخدم المراجع الثابتة نفسها عند إعادة الرفع.</span><button className={styles.primary} disabled={busy} onClick={() => void upload()}>رفع ومعاينة الملف</button>{progress !== null && <div role="status"><progress max={100} value={progress} /> {progress}% {progress === 100 ? "— جارٍ قراءة بنية الملف" : ""}</div>}</div>}
    </section>
    {error && <p ref={message} tabIndex={-1} role="alert" className={styles.status}>{error}</p>}
    <section className={`${styles.panel} ${local.section}`}><h3>دفعات المشفى</h3>{batches.error ? <p role="alert">{batches.error}<button className={styles.secondary} onClick={batches.retry}>إعادة التحميل</button></p> : <DirectoryTable label="دفعات الاستيراد" busy={batches.loading} headers={["الدفعة", "الغرض", "تاريخ الانتقال", "الحالة", "الإجراءات"]}>{batches.data?.data.map(row => <tr key={row.id}><td>{row.id}</td><td>{row.purpose === "offline_capture" ? "تسجيل دون اتصال" : "نقل ملفات سابقة"}</td><td>{row.cutover_date}</td><td><span className={styles.badge}>{states[row.status]}</span></td><td><button className={styles.secondary} disabled={busy} onClick={() => navigate(row.id)}>استعراض الدفعة {row.id}</button></td></tr>)}</DirectoryTable>}</section>
    {batches.data && batches.data.last_page > 1 && <nav className={styles.actions} aria-label="صفحات الدفعات"><button className={styles.secondary} disabled={busy || batches.data.current_page <= 1} onClick={() => { const q = new URLSearchParams(params); q.set("batches_page", String(batches.data!.current_page - 1)); router.push("/patient-cards/imports?" + q); }}>السابق</button><span>{batches.data.current_page} / {batches.data.last_page}</span><button className={styles.secondary} disabled={busy || batches.data.current_page >= batches.data.last_page} onClick={() => { const q = new URLSearchParams(params); q.set("batches_page", String(batches.data!.current_page + 1)); router.push("/patient-cards/imports?" + q); }}>التالي</button></nav>}
    {batchId && <section className={`${styles.panel} ${local.section}`} aria-busy={batch.loading || busy}><h3>٣. معاينة الدفعة {batchId}</h3>{batch.loading && <p role="status">جارٍ تحميل أحدث حالة…</p>}{batch.error && <p role="alert">{batch.error}<button onClick={batch.retry} className={styles.secondary}>إعادة التحميل</button></p>}{b && <><p><strong>{states[b.status]}</strong> · {b.total_rows} صفًا · {b.cutover_date}</p><div className={local.counts}><span>مرضى: {b.sheets.Patients ?? 0}</span><span>زيارات صريحة: {b.sheets.Visits ?? 0}</span><span>ملفات قبل الانتقال: {b.pre_cutover_patients}</span>{Object.entries(b.matches).filter(([key]) => key !== "new").map(([key, count]) => <span key={`match-${key}`}>{matchLabels[key]}: <strong>{count}</strong></span>)}{Object.entries(b.counts).map(([key, count]) => <span key={key}>{states[key]}: <strong>{count}</strong></span>)}{Object.entries(b.actions).filter(([key]) => key).map(([key, count]) => <span key={key}>{actions[key]}: <strong>{count}</strong></span>)}</div>
      <p>تعتمد كل مجموعة مريض مع زياراتها وحقائقها معًا. الصفوف التي تحتاج مراجعة لا تُحفظ. يبقى كود الهوية المحفوظ دون استبدال، ولا تُكتب بيانات شخصية فوق الموجود. لا يعني الاعتماد إكمال الزيارة أو تفعيل البطاقة.</p>
      <div className={styles.actions}>{can("validate") && ["uploaded", "validating"].includes(b.status) && <button className={styles.primary} disabled={busy || batch.loading} onClick={() => void process("validate")}>{b.status === "validating" ? "استكمال الفحص" : "فحص الدفعة"}</button>}{can("download") && <button className={styles.secondary} disabled={busy} onClick={() => void download(`dossiers/imports/${b.id}/errors.xlsx?facility_id=${facility}`)}>تنزيل تقرير الأخطاء</button>}{can("cancel") && !["completed", "completed_with_errors", "cancelled"].includes(b.status) && <button className={styles.secondary} disabled={busy} onClick={() => void process("cancel")}>إلغاء الصفوف غير المعتمدة</button>}</div>
      {can("commit") && ["validated", "committing"].includes(b.status) && <div className={local.confirm}><label><input type="checkbox" checked={confirmed} disabled={busy} onChange={e => setConfirmedBatch(e.target.checked ? batchId : null)} />راجعت النتائج وأوافق على حفظ المجموعات الصالحة فقط دون دمج هويات أو استبدال تاريخ محفوظ.</label><button className={styles.primary} disabled={busy || batch.loading || !confirmed} onClick={() => void process("commit")}>{b.status === "committing" ? "استكمال اعتماد الدفعة" : "اعتماد المجموعات الصالحة"}</button><p className={styles.hint}>لن تُستبدل الأكواد النظامية أو البيانات الموجودة. تُحفظ المجموعات الصالحة وتبقى غير الصالحة للمراجعة، ولا تُستورد حقائق التشريح المرضي أو خطط وجرعات الأورام. تعالج كل خطوة حتى 10 مرضى؛ يمكن العودة لاستكمالها دون تكرار.</p></div>}
      <label className={local.filter}>عرض الصفوف<select value={filter} disabled={busy} onChange={e => navigate(b.id, "1", e.target.value)}><option value="">الكل</option>{["pending", "valid", "needs_review", "error", "committed", "skipped"].map(s => <option key={s} value={s}>{states[s]}</option>)}</select></label>
      <label className={local.filter}>نوع المطابقة<select value={action} disabled={busy} onChange={e => navigate(b.id, "1", filter, e.target.value)}><option value="">الكل</option>{Object.entries(actions).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
      <div className={local.results}><DirectoryTable label="نتائج فحص ملف الاستيراد" headers={["الورقة / السطر", "مرجع المصدر", "مرجع المريض / الزيارة", "النتيجة", "المراجعة / البطاقة"]}>{b.rows.data.map(row => <tr key={row.id}><td>{row.sheet} / {row.row_number}</td><td dir="ltr">{row.source_record_id}{row.match.canonical_code && <small className={styles.hint}>الكود النظامي: {row.match.canonical_code}<br />{matchLabels[row.match.match_type ?? ""]}</small>}</td><td>{row.local_patient_ref}{row.local_visit_ref ? ` / ${row.local_visit_ref}` : ""}</td><td>{states[row.status]}<small className={styles.hint}>{row.action ? actions[row.action] : ""}</small></td><td>{Object.entries(row.errors).map(([key, messages]) => <p key={key}>{key}: {messages.join(" ")}</p>)}{row.dossier_id && <Link href={`/patient-cards/${row.dossier_id}?facility_id=${facility}`}>فتح بطاقة المريض</Link>}</td></tr>)}</DirectoryTable></div>
      <nav aria-label="صفحات نتائج الاستيراد" className={styles.actions}><button className={styles.secondary} disabled={busy || b.rows.current_page <= 1} onClick={() => navigate(b.id, String(b.rows.current_page - 1), filter)}>السابق</button><span>{b.rows.current_page} / {b.rows.last_page}</span><button className={styles.secondary} disabled={busy || b.rows.current_page >= b.rows.last_page} onClick={() => navigate(b.id, String(b.rows.current_page + 1), filter)}>التالي</button></nav>
    </>}</section>}
  </div>;
}
