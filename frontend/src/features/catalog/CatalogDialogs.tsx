"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import Modal from "../clinics/Modal";
import { Pagination } from "../directory/Controls";
import { type Item, type Page, useCatalogRequest, useDebounced, itemPath } from "./api";
import styles from "../clinics/clinics.module.css";

export type Action = "delete" | "archive" | "restore" | "deactivate" | "reactivate";
export const actionNames: Record<Action, string> = { delete: "حذف", archive: "أرشفة وإزالة من الدليل", restore: "استعادة كغير فعال", deactivate: "تعطيل", reactivate: "إعادة تفعيل" };
export function CatalogLifecycle({ item, action, facilityId, onClose, onSaved }: { item: Item; action: Action; facilityId: number; onClose: () => void; onSaved: (action: Action) => void }) {
  const preview = useCatalogRequest<{ action: "delete" | "archive"; has_references: boolean; lock_version: number; archived: boolean }>(action === "delete" ? `${itemPath(item)}/deletion-preview?facility_id=${facilityId}` : null);
  const [error, setError] = useState(""); const [busy, setBusy] = useState(false); const [conflict, setConflict] = useState(false);
  const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const actual = action === "delete" ? preview.data?.action : action;
  async function confirm() {
    if (!actual || pending.current || preview.loading || preview.error || conflict) return;
    pending.current = true; setBusy(true); setError(""); const active = new AbortController(); controller.current = active;
    try {
      await apiRequest(`${itemPath(item)}${actual === "delete" ? "" : `/${actual}`}`, { method: actual === "delete" ? "DELETE" : "POST", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, lock_version: preview.data?.lock_version ?? item.lock_version }) });
      if (!active.signal.aborted) onSaved(actual);
    } catch (reason) { if (!active.signal.aborted) { setError(reason instanceof AuthError ? reason.message : "تعذّر إتمام العملية. حاول مجددًا."); setConflict(reason instanceof AuthError && reason.status === 409); } }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={`${actionNames[action]} ${item.name_ar}`} busy={busy} size="compact" onClose={onClose}><div className={styles.confirm}>
    <p className={styles.scopeNote}>هذا تعريف مشترك؛ تغيير حالته يسري على جميع المنشآت. تبقى السجلات العلاجية السابقة محفوظة.</p>
    {preview.loading && <p role="status">جارٍ فحص موانع الحذف…</p>}{preview.error && <p role="alert">{preview.error} <button onClick={preview.retry}>إعادة المحاولة</button></p>}
    {preview.data?.has_references && <p>توجد استخدامات أو مراجع تاريخية تمنع الحذف النهائي. يمكنك تأكيد الأرشفة بدلًا منه.</p>}
    {actual === "delete" && <p>لا توجد مراجع حاليًا. الحذف نهائي ويُعاد فحص الموانع عند التنفيذ.</p>}
    {actual === "restore" && <p>ستتم الاستعادة كغير فعال. إعادة التفعيل قرار مستقل.</p>}
    {error && <p role="alert" className={styles.error}>{error}</p>}{conflict && <p>أغلق النافذة وأعد تحميل العنصر ثم راجع العملية؛ لن نكررها تلقائيًا.</p>}
    <div className={styles.modalActions}><button className={styles.danger} disabled={busy || !actual || preview.loading || !!preview.error || conflict || (actual === "archive" && !!preview.data?.archived)} onClick={() => void confirm()}>{busy ? "جارٍ التنفيذ…" : `تأكيد ${actionNames[actual ?? action]}`}</button><button className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </div></Modal>;
}

type Patient = { id: number; patient_code: string; first_name: string; family_name: string };
type Audit = { id: number; event: string; occurred_at: string; old_values: string | null; new_values: string | null };
const auditLabels: Record<string, string> = { code: "الكود", name_ar: "الاسم", description: "الوصف", is_active: "الحالة", archived_at: "تاريخ الأرشفة", category_id: "معرّف فئة الخدمة", procedure_type_id: "معرّف نوع الإجراء" };
function AuditChanges({ row }: { row: Audit }) {
  const previous: Record<string, unknown> = row.old_values ? JSON.parse(row.old_values) : {};
  const current: Record<string, unknown> = row.new_values ? JSON.parse(row.new_values) : {};
  const changed = Object.keys(auditLabels).filter(key => previous[key] !== current[key]);
  const value = (key: string, data: unknown) => data == null ? "—" : key === "is_active" ? (data ? "فعال" : "غير فعال") : String(data);
  return changed.length ? <details><summary>عرض التغييرات</summary>{changed.map(key => <div key={key}><strong>{auditLabels[key]}</strong><p style={{ overflowWrap: "anywhere" }}>السابق: {value(key, previous[key])}</p><p style={{ overflowWrap: "anywhere" }}>الحالي: {value(key, current[key])}</p></div>)}</details> : null;
}
export function CatalogRelated({ item, facilityId, history = false, onClose }: { item: Item; facilityId: number; history?: boolean; onClose: () => void }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const committed = useDebounced(search);
  const result = useCatalogRequest<Page<Patient | Audit>>(`${itemPath(item)}/${history ? "history" : "beneficiaries"}?facility_id=${facilityId}&page=${page}&search=${encodeURIComponent(committed)}`, true);
  const events: Record<string, string> = { created: "إضافة", updated: "تعديل", archived: "أرشفة", restored: "استعادة", deactivated: "تعطيل", reactivated: "إعادة تفعيل", exported: "تصدير" };
  return <Modal title={`${history ? "سجل التغييرات" : "المستفيدون"} — ${item.name_ar}`} onClose={onClose}><div className={styles.doctorList}>
    <p className={styles.hint}>{history ? "التغييرات المسجلة ضمن المنشأة المحددة فقط." : item.patient_count_definition}</p>
    {!history && <><label>البحث عن مستفيد<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label><p className={styles.hint}>تُعرض هوية المريض الأساسية فقط. صفحة استعراض سجل المريض غير منفّذة بعد.</p></>}
    {result.loading && <p role="status">جارٍ التحميل…</p>}{result.error && <p role="alert">{result.error} <button onClick={result.retry}>إعادة المحاولة</button></p>}
    {result.data && <><p>{result.data.meta.total} {history ? "حدث" : "مستفيد فريد"} مطابق</p><div className={styles.tableScroll}><table><thead><tr><th>{history ? "الحدث" : "كود المريض"}</th><th>{history ? "التاريخ والتغييرات" : "اسم المريض"}</th></tr></thead><tbody>{result.data.data.map(row => <tr key={row.id}>{"event" in row ? <><td>{events[row.event] ?? row.event}</td><td><time dir="ltr">{row.occurred_at}</time><AuditChanges row={row} /></td></> : <><td><bdi>{row.patient_code}</bdi></td><td>{row.first_name} {row.family_name}</td></>}</tr>)}</tbody></table></div>{!result.data.data.length && <p>لا توجد نتائج مطابقة.</p>}<Pagination meta={result.data.meta} onPage={setPage} /></>}
  </div></Modal>;
}
