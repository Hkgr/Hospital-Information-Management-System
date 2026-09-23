"use client";
import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError, subscribeSession } from "../auth/api";
import { useClinicRequest, type Page } from "../clinics/api";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination, LongText } from "../directory/Controls";
import Modal from "../clinics/Modal";
import PathologyEditor from "./PathologyEditor";
import { doctorRoleLabel, pathologyLabels, type Pathology, type PathologyFile } from "./pathology";
import type { Visit } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

export default function PathologyPanel({ facility, dossier, visit, caps, revision = 0, onChanged }: { facility: number; dossier: number; visit?: Visit; caps: Record<string, boolean>; revision?: number; onChanged: () => void }) {
  const [page, setPage] = useState(1), [status, setStatus] = useState(""), [editor, setEditor] = useState<"new" | Pathology | null>(null), [voiding, setVoiding] = useState<Pathology | null>(null), [reason, setReason] = useState("");
  const [busy, setBusy] = useState(false), [error, setError] = useState("");
  const pending = useRef<AbortController | null>(null), reservation = useRef<{ body: string; id: string } | null>(null);
  useEffect(() => { const unsubscribe = subscribeSession(() => { pending.current?.abort(); setEditor(null); setVoiding(null); }); return () => { pending.current?.abort(); unsubscribe(); }; }, []);
  const path = `dossiers/${dossier}${visit ? `/visits/${visit.id}` : ""}/pathology`;
  const list = useClinicRequest<Page<Pathology>>(`${path}?facility_id=${facility}&page=${page}&status=${status}`, true, false, revision);
  const saved = () => { setEditor(null); setVoiding(null); list.retry(); onChanged(); };
  async function voidCase() {
    if (!voiding || pending.current || !voiding.capabilities.void) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError("");
    const body = JSON.stringify({ facility_id: facility, lock_version: voiding.lock_version, void_reason: reason });
    if (reservation.current?.body !== body) reservation.current = { body, id: crypto.randomUUID() };
    try { await apiRequest(`dossiers/${dossier}/visits/${voiding.visit_id}/pathology/${voiding.id}/void`, { method: "POST", signal: c.signal, body: JSON.stringify({ ...JSON.parse(body), request_id: reservation.current.id }) }); if (!c.signal.aborted) saved(); }
    catch (e) { if (!c.signal.aborted) { setError(e instanceof AuthError ? Object.values(e.fields)[0] ?? e.message : "تعذر الإلغاء"); if (e instanceof AuthError && e.status === 409) { list.retry(); onChanged(); } } }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  async function download(file: PathologyFile) {
    if (pending.current || !caps.attachments_download || file.voided_at) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError("");
    try { const { blob } = await apiRequest<{ blob: Blob }>(`dossiers/${dossier}/visits/${file.visit_id}/attachments/${file.id}/download?facility_id=${facility}`, { signal: c.signal }, "blob"); if (c.signal.aborted) return; const url = URL.createObjectURL(blob), a = document.createElement("a"); a.href = url; a.download = file.original_filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); }
    catch (e) { if (!c.signal.aborted) setError(e instanceof Error ? e.message : "تعذر فتح المرفق"); }
    finally { if (pending.current === c) pending.current = null; if (!c.signal.aborted) setBusy(false); }
  }
  function sourceLine(row: Pathology) {
    const doctor = row.doctor_name ? `${doctorRoleLabel(row.source)}: ${row.doctor_name}` : doctorRoleLabel(row.source);
    if (row.source === "internal") return `ضمن المشفى · ${doctor}${row.clinic_name ? ` · ${row.clinic_name}` : ""}`;
    return `جهة خارجية: ${row.external_organization ?? "غير مسجلة"} · ${doctor}${row.clinic_name ? ` · ${row.clinic_name}` : ""}`;
  }
  return <section className={styles.detailPanel}><h2>{visit ? "التشريح المرضي لهذه الزيارة" : "التشريح المرضي"}</h2>
    <p className={layout.pathologyIntro}>{visit ? "سجّل تقرير التشريح إن وُجد لهذه الزيارة. لا موافقات ولا مراجعات تقييم؛ التقرير وحده يكفي." : "أحدث التقارير المحفوظة في البطاقة. كل تقرير يعرض زيارته المصدر."}</p>
    {visit && <div className={styles.actions}>{caps.pathology_create && <button className={styles.primary} onClick={() => setEditor("new")}>إضافة تقرير تشريح مرضي</button>}</div>}
    <h3>تقارير التشريح المرضي</h3><label>حالة التقرير<select value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="">الكل</option>{Object.entries(pathologyLabels).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</select></label>
    {list.loading && <p role="status">جارٍ تحميل تقارير التشريح المرضي…</p>}{list.error && <p role="alert">{list.error}<button className={styles.secondary} onClick={list.retry}>إعادة المحاولة</button></p>}{error && <p role="alert">{error}</p>}
    {list.data && <><DirectoryTable label={visit ? "تقارير تشريح الزيارة" : "تقارير التشريح المرضي للبطاقة"} headers={["التقرير", "المصدر والمسؤول", "النتيجة", "الإجراءات"]}>{list.data.data.map(row => <tr key={row.id}><td><bdi>{row.report_number ?? `#${row.id}`}</bdi><p>{row.visit_no} · {row.visit_date}</p><span className={row.voided_at ? styles.badge : row.status === "completed" ? styles.active : styles.badge}>{row.voided_at ? "ملغى مع حفظ التاريخ" : pathologyLabels[row.status]}</span></td><td>{sourceLine(row)}</td><td>{row.result_on ? `تاريخ النتيجة: ${row.result_on}` : "تاريخ النتيجة غير مسجل"}<LongText text={String(row.conclusion ?? "غير مسجل")} />{!!row.attachments.length && <details><summary>المرفقات</summary>{row.attachments.map(file => <div key={file.id}>{file.title}{file.voided_at ? " · مرفق ملغى" : caps.attachments_download && <button className={styles.textButton} disabled={busy} onClick={() => void download(file)}>فتح {file.original_filename}</button>}</div>)}</details>}</td><td>{visit && row.capabilities.update && <button className={styles.textButton} onClick={() => setEditor(row)}>تعديل تقرير التشريح المرضي</button>}{visit && row.capabilities.void && <button className={`${styles.textButton} ${styles.dangerText}`} onClick={() => { setVoiding(row); setReason(""); setError(""); }}>إلغاء التقرير</button>}{!visit && <Link className={styles.textButton} href={`/patient-cards/${dossier}?facility_id=${facility}&visit=${row.visit_id}`}>استعراض الزيارة المصدر</Link>}</td></tr>)}{!list.data.data.length && <tr><td colSpan={4}>لا يوجد تقرير تشريح مرضي محفوظ.</td></tr>}</DirectoryTable><Pagination meta={list.data.meta} onPage={setPage} /></>}
    {editor && visit && <PathologyEditor facility={facility} dossier={dossier} visit={visit} caps={caps} record={editor === "new" ? null : editor} onClose={() => setEditor(null)} onSaved={saved} onRefresh={() => { list.retry(); onChanged(); }} />}
    {voiding && <Modal title="إلغاء تقرير التشريح المرضي" busy={busy} onClose={() => setVoiding(null)}><div className={styles.form}><p>يبقى التقرير ومرفقاته في التاريخ ويتوقف احتسابه نتيجة حالية.</p><label>سبب الإلغاء *<textarea value={reason} maxLength={255} onChange={e => setReason(e.target.value)} /></label>{error && <p role="alert">{error}</p>}<div className={styles.formActions}><button className={styles.danger} disabled={busy || !reason.trim()} onClick={() => void voidCase()}>تأكيد الإلغاء</button><button className={styles.secondary} disabled={busy} onClick={() => setVoiding(null)}>رجوع</button></div></div></Modal>}
  </section>;
}
