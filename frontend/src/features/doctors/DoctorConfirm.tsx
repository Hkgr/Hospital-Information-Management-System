"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import Modal from "../clinics/Modal";
import type { Doctor } from "./api";
import styles from "../clinics/clinics.module.css";

export default function DoctorConfirm({ doctor, facilityId, kind, onClose, onSaved }: { doctor: Doctor; facilityId: number; kind: "delete" | "deactivate"; onClose: () => void; onSaved: () => void }) {
  const [busy, setBusy] = useState(false); const [error, setError] = useState(""); const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  async function confirm() {
    if (pending.current) return; pending.current = true; setBusy(true); setError(""); const active = new AbortController(); controller.current = active;
    try { await apiRequest(`doctors/${doctor.id}${kind === "deactivate" ? "/deactivate" : ""}`, { method: kind === "delete" ? "DELETE" : "POST", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, lock_version: doctor.lock_version }) }); if (!active.signal.aborted) onSaved(); }
    catch (reason) { if (!active.signal.aborted) setError(reason instanceof AuthError ? reason.message : "تعذّر إتمام العملية."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={kind === "delete" ? "حذف الطبيب" : "تعطيل الطبيب عالميًا"} onClose={onClose} busy={busy}><div className={styles.confirm}>
    <strong>{doctor.name} · <bdi>{doctor.code}</bdi></strong><p>{kind === "delete" ? "سيُحذف الطبيب من الدليل المشترك فقط إن لم يكن له أي مراجع أو تاريخ محفوظ. لا يمكن التراجع عن الحذف." : "سيصبح الطبيب غير فعال في جميع المنشآت. تبقى بياناته وسجلاته وتاريخ ارتباطاته محفوظة. هذا الإجراء لا يقتصر على المنشأة الحالية."}</p>
    {error && <p role="alert" className={styles.error}>{error}</p>}<div className={styles.modalActions}><button className={styles.danger} disabled={busy} onClick={() => void confirm()}>{busy ? "جارٍ التنفيذ…" : kind === "delete" ? "حذف نهائي" : "تعطيل عالمي"}</button><button className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </div></Modal>;
}
