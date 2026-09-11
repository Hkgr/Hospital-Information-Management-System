"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import { useClinicRequest, type Page } from "../clinics/api";
import Modal from "../clinics/Modal";
import { Pagination } from "./Controls";
import styles from "../clinics/clinics.module.css";

export type LifecycleAction = "delete" | "deactivate" | "reactivate" | "restore";
type CompletedAction = LifecycleAction | "archive";
type RecordState = { id: number; code: string; is_active: boolean; archived_at?: string | null; lock_version: number };
type Preview = { action: "delete" | "archive"; organizational_links: number; has_other_references: boolean; lock_version: number; archived: boolean };
const labels = { delete: "حذف نهائي", archive: "أرشفة وإزالة من الدليل", deactivate: "تعطيل مؤقت", reactivate: "إعادة تفعيل", restore: "استعادة كغير فعال" };
const messages = { delete: "تم الحذف النهائي.", archive: "تمت الأرشفة وحفظ التاريخ.", deactivate: "تم التعطيل مع حفظ الارتباطات.", reactivate: "تمت إعادة التفعيل.", restore: "تمت الاستعادة كغير فعال دون إعادة فتح الارتباطات." };

export function LifecycleActions({ record, name, canUpdate, onAction }: { record: RecordState; name: string; canUpdate: boolean; onAction: (action: LifecycleAction) => void }) {
  if (!canUpdate) return null;
  const action = record.archived_at ? "restore" : record.is_active ? "deactivate" : "reactivate";
  const label = action === "restore" ? "استعادة" : action === "deactivate" ? "تعطيل" : "إعادة تفعيل";
  return <button className={styles.textButton} onClick={() => onAction(action)} aria-label={`${label} ${name}`}>{label}</button>;
}

export default function LifecycleDialog({ kind, record, name, facilityId, action, onClose, onSaved, onRefresh }: { kind: "doctors" | "clinics"; record: RecordState; name: string; facilityId: number; action: LifecycleAction; onClose: () => void; onSaved: (message: string, action: CompletedAction) => void; onRefresh: () => void }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState(""), [stale, setStale] = useState(false);
  const pending = useRef(false), controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const preview = useClinicRequest<Preview>(action === "delete" ? `${kind}/${record.id}/deletion-preview?facility_id=${facilityId}` : null);
  const outdated = stale || (action === "delete" && !!preview.data && preview.data.lock_version !== record.lock_version);
  const chosen = action === "delete" ? preview.data?.action : action;
  const blocked = busy || outdated || !chosen || (action === "delete" && (preview.loading || !!preview.error || (preview.data?.archived && chosen === "archive")));
  async function confirm() {
    if (pending.current || blocked || !chosen) return;
    pending.current = true; setBusy(true); setError("");
    const active = new AbortController(); controller.current = active;
    try {
      await apiRequest(`${kind}/${record.id}${chosen === "delete" ? "" : `/${chosen}`}`, { method: chosen === "delete" ? "DELETE" : "POST", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, lock_version: record.lock_version }) });
      if (!active.signal.aborted) onSaved(messages[chosen], chosen);
    } catch (reason) {
      if (!active.signal.aborted) {
        setError(reason instanceof AuthError ? Object.values(reason.fields).filter(Boolean).join(" ") || reason.message : "تعذّر إتمام العملية. أعد تحميل السجل للتحقق من حالته قبل المحاولة مجددًا.");
        if (!(reason instanceof AuthError) || [0, 404, 409].includes(reason.status) || reason.status >= 500) setStale(true);
      }
    } finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  const description = chosen === "archive" ? "سيُحفظ السجل والتاريخ، وتُنهى الارتباطات الحالية وتُلغى المجدولة دون حذفها. سيختفي السجل من الدليل وخيارات الإسناد، ويبقى متاحًا بفلتر المؤرشف."
    : chosen === "delete" ? "لا توجد ارتباطات أو مراجع تمنع الحذف حسب المعاينة. سيُعاد الفحص عند التنفيذ. الحذف نهائي ولا يمكن التراجع عنه."
      : action === "deactivate" ? "يتغير نشاط السجل فقط، وتبقى الارتباطات وتواريخها محفوظة. لا يمكن إضافة ارتباطات جديدة ما دام معطلًا."
        : action === "reactivate" ? "ستظهر الارتباطات التي ما زالت سارية وفق حالة الطرف الآخر وتاريخ المنشأة. لن تُفتح الفترات المغلقة أو المنتهية."
          : action === "restore" ? "يعود السجل إلى الدليل بحالة غير فعالة. التفعيل إجراء مستقل، ولن تُفتح الارتباطات التي أغلقتها الأرشفة."
            : "جارٍ التحقق من المراجع والإجراء المتاح…";
  return <Modal size="compact" title={action === "delete" ? "حذف أو أرشفة" : labels[action]} busy={busy} onClose={onClose}><div className={styles.confirm}>
    <strong>{name} · <bdi>{record.code}</bdi></strong>
    {kind === "doctors" && <p className={styles.scopeNote}>هذا إجراء على دليل الأطباء المشترك، وقد يؤثر في جميع المنشآت، وليس المنشأة الحالية فقط.</p>}
    <p>{description}</p>
    {action === "delete" && preview.data && <p>فترات الارتباط في المنشأة الحالية: {preview.data.organizational_links}. {preview.data.has_other_references && "توجد مراجع أخرى محفوظة تمنع الحذف النهائي."}</p>}
    {preview.error && <p role="alert" className={styles.error}>{preview.error} <button onClick={preview.retry}>إعادة المعاينة</button></p>}
    {error && <p role="alert" className={styles.error}>{error}</p>}
    {outdated && <p role={error ? undefined : "alert"}>تغيّرت حالة السجل. أغلق هذه النافذة وحدّث البيانات ثم راجع العملية من جديد.</p>}
    {preview.data?.archived && chosen === "archive" && <p role="status">السجل مؤرشف بالفعل وتاريخه محفوظ.</p>}
    <div className={styles.modalActions}><button className={styles.danger} disabled={!!blocked} onClick={() => void confirm()}>{busy ? "جارٍ التنفيذ…" : chosen ? labels[chosen] : "جارٍ المعاينة…"}</button><button className={styles.secondary} disabled={busy} onClick={outdated ? onRefresh : onClose}>{outdated ? "إغلاق وتحديث السجل" : "إلغاء"}</button></div>
  </div></Modal>;
}

type Period = { id: number; code: string; name: string; starts_on: string; ends_on: string | null };
export function LinkHistory({ kind, id, facilityId }: { kind: "doctors" | "clinics"; id: number; facilityId: number }) {
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [size, setSize] = useState(20);
  const result = useClinicRequest<Page<Period>>(open ? `${kind}/${id}/link-history?facility_id=${facilityId}&page=${page}&per_page=${size}` : null, true);
  return <section className={styles.detailPanel}><button className={styles.secondary} aria-expanded={open} onClick={() => setOpen(value => !value)}>تاريخ الارتباطات في المنشأة</button>{open && <>
    <p className={styles.hint}>السارية والمنتهية والمجدولة، بما فيها السجلات المعطلة والمؤرشفة. تساوي البداية والنهاية يعني فترة مغلقة دون مدة.</p>
    {result.loading && <p role="status">جارٍ تحميل التاريخ…</p>}{result.error && <p role="alert">{result.error} <button onClick={result.retry}>إعادة المحاولة</button></p>}
    {result.data && <><div className={styles.tableScroll}><table><thead><tr><th>الكود</th><th>الاسم</th><th>البداية</th><th>النهاية (غير مشمولة)</th></tr></thead><tbody>{result.data.data.map(p => <tr key={p.id}><td><bdi>{p.code}</bdi></td><td>{p.name}</td><td><bdi>{p.starts_on}</bdi></td><td><bdi>{p.ends_on ?? "مفتوحة"}</bdi></td></tr>)}</tbody></table></div>{!result.data.data.length && <p>لا توجد فترات ارتباط مسجلة.</p>}<Pagination meta={result.data.meta} onPage={setPage} onPageSize={value => { setSize(Number(value)); setPage(1); }} /></>}
  </>}</section>;
}
