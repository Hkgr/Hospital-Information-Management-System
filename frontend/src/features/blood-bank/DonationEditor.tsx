"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import ConflictReview from "./ConflictReview";
import { type Donation, choices } from "./api";
import styles from "../clinics/clinics.module.css";
const labels = { donated_on: "تاريخ التبرع الفعلي", blood_group: "زمرة ABO", rh: "عامل Rh", units: "عدد الوحدات" };
const fields = (d?: Donation): Record<string, string> => ({ donated_on: d?.donated_on ?? "", blood_group: d?.blood_group ?? "", rh: d?.rh ?? "", units: d?.units ?? "" });

export default function DonationEditor({ donorId, donation, facilityId, onClose, onSaved, onRefresh }: { donorId: number; donation?: Donation; facilityId: number; onClose: () => void; onSaved: (row: Donation) => void; onRefresh: () => void }) {
  const [base, setBase] = useState(donation); const [draft, setDraft] = useState(() => fields(donation)); const [error, setError] = useState<AuthError | null>(null);
  const [conflict, setConflict] = useState(false); const [latest, setLatest] = useState<Donation | null>(null); const [busy, setBusy] = useState(false); const [reloadError, setReloadError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null); const retry = useRef<{ body: string; id: string } | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const path = `blood-bank/donor/${donorId}/donations${base ? `/${base.id}` : ""}`;
  async function reload() {
    if (pending.current) return; pending.current = true; setBusy(true); setReloadError(""); setLatest(null); const active = new AbortController(); controller.current = active;
    try { const row = await apiRequest<Donation>(`${path}?facility_id=${facilityId}`, { signal: active.signal }); if (!active.signal.aborted) { setLatest(row); onRefresh(); } }
    catch { if (!active.signal.aborted) setReloadError("تعذّر جلب أحدث تبرع. المسودة محفوظة؛ أعد المحاولة."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current || conflict) return;
    const payload = { ...draft, facility_id: facilityId, ...base ? { lock_version: base.lock_version } : {} }; const body = JSON.stringify(payload);
    if (retry.current?.body !== body) retry.current = { body, id: crypto.randomUUID() };
    pending.current = true; setBusy(true); setError(null); const active = new AbortController(); controller.current = active;
    try { const row = await apiRequest<Donation>(path, { method: base ? "PUT" : "POST", body: JSON.stringify({ ...payload, request_id: retry.current.id }), signal: active.signal }); if (!active.signal.aborted) onSaved(row); }
    catch (reason) { if (!active.signal.aborted) { const err = reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. أعد المحاولة بالمسودة نفسها."); setError(err); if (err.code === "BLOOD_BANK_VERSION_CONFLICT") { setConflict(true); setLatest(null); } } }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={base ? "تصحيح بيانات التبرع" : "تسجيل تبرع فعلي"} onClose={onClose} busy={busy} size={conflict ? "wide" : "regular"}><form className={styles.form} onSubmit={save}>
    <p className={styles.scopeNote}>يسجّل هذا الحفظ واقعة تبرع فعلية بحالة بانتظار المراجعة، دون قرار قبول أو حركة مخزون. يلزم تاريخ فعلي ضمن فترة تقارير مفتوحة.</p>{base && <p>الكود الحالي: <bdi>{base.donation_code}</bdi>. عند تصحيح التاريخ يتحدّث الكود ويبقى القديم قابلًا للبحث.</p>}
    {error && <p role="alert" className={styles.error}>{error.message}</p>}{conflict && <><button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة</button>{reloadError && <p role="alert">{reloadError}</p>}</>}
    {latest && <ConflictReview key={latest.lock_version} latest={fields(latest)} draft={draft} labels={labels} format={(key, value) => choices[key]?.[value] ?? value} onAccept={value => { setDraft(value); setBase(latest); setLatest(null); setConflict(false); setError(null); retry.current = null; }} />}
    <fieldset className={styles.fields} disabled={busy || conflict}>{Object.entries(labels).map(([key, label]) => <label key={key}>{label} *{key === "blood_group" || key === "rh" ? <select aria-label={label} required value={draft[key]} onChange={e => setDraft({ ...draft, [key]: e.target.value })}><option value="">اختر القيمة المسجلة</option>{Object.entries(key === "rh" ? choices.rh : { A: "A", B: "B", AB: "AB", O: "O" }).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select> : <input aria-label={label} required type={key === "donated_on" ? "date" : "number"} min={key === "units" ? "0.0001" : undefined} step={key === "units" ? "0.0001" : undefined} value={draft[key]} onChange={e => setDraft({ ...draft, [key]: e.target.value })} />}{error?.fields[key] && <small className={styles.fieldError}>{error.fields[key]}</small>}</label>)}</fieldset>
    <div className={styles.modalActions}><button className={styles.primary} disabled={busy || conflict}>حفظ التبرع</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
