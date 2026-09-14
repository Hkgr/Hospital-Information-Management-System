"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import PersonFields, { BloodFields, personDraft, personPayload } from "./PersonFields";
import ConflictReview from "./ConflictReview";
import { type Options, personLabels } from "./api";
import { type Person } from "./events";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";

export default function PersonEditor({ person, facilityId, options, onClose, onSaved, onRefresh }: { person: Person; facilityId: number; options: Options; onClose: () => void; onSaved: () => void; onRefresh: () => void }) {
  const [base, setBase] = useState(person); const [draft, setDraft] = useState(() => personDraft(person));
  const [busy, setBusy] = useState(false); const [error, setError] = useState<AuthError | null>(null); const [conflict, setConflict] = useState(false); const [latest, setLatest] = useState<Person | null>(null); const [reloadError, setReloadError] = useState("");
  const active = useRef<AbortController | null>(null); const pending = useRef(false); const retry = useRef<{ body: string; id: string } | null>(null);
  useEffect(() => () => active.current?.abort(), []);
  const change = (key: string, value: string) => setDraft(p => ({ ...p, [key]: value }));
  async function reload() {
    if (pending.current) return; pending.current = true; setBusy(true); setReloadError(""); const c = new AbortController(); active.current = c;
    try { const p = await apiRequest<Person>(`blood-bank/people/${base.id}?facility_id=${facilityId}`, { signal: c.signal }); if (!c.signal.aborted) { setLatest(p); onRefresh(); } }
    catch { if (!c.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ مسودتك محفوظة."); }
    finally { if (!c.signal.aborted) { setBusy(false); pending.current = false; } }
  }
  async function save(e: React.FormEvent) {
    e.preventDefault(); if (pending.current || conflict) return;
    const payload = { facility_id: facilityId, lock_version: base.lock_version, person: personPayload(draft, base.patient_id ? { id: base.patient_id, name_ar: base.name } : null) };
    const body = JSON.stringify(payload); if (retry.current?.body !== body) retry.current = { body, id: crypto.randomUUID() };
    pending.current = true; setBusy(true); setError(null); const c = new AbortController(); active.current = c;
    try { await apiRequest(`blood-bank/people/${base.id}`, { method: "PUT", body: JSON.stringify({ ...payload, request_id: retry.current.id }), signal: c.signal }); if (!c.signal.aborted) onSaved(); }
    catch (reason) { if (!c.signal.aborted) { const err = reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ؛ مسودتك محفوظة."); setError(err); if (err.code === "BLOOD_BANK_VERSION_CONFLICT") { setConflict(true); setLatest(null); } } }
    finally { if (!c.signal.aborted) { setBusy(false); pending.current = false; } }
  }
  return <Modal title="تعديل بيانات الشخص" onClose={onClose} busy={busy} size="wide"><form className={`${styles.form} ${layout.profileForm}`} onSubmit={save}><p className={styles.scopeNote}>تعديل البيانات الحالية لا يغيّر الزمرة والحقول المثبتة للوقائع السابقة.</p>{error && <p role="alert" className={styles.error}>{error.message}</p>}{conflict && <button type="button" disabled={busy} className={styles.secondary} onClick={() => void reload()}>جلب أحدث نسخة</button>}{reloadError && <p role="alert">{reloadError}</p>}
    {latest && <ConflictReview key={latest.lock_version} latest={personDraft(latest)} draft={draft} labels={{ ...personLabels, governorate_text: "المحافظة اليدوية", city_text: "المدينة اليدوية", governorate_mode: "مصدر المحافظة", city_mode: "مصدر المدينة", blood_group: "ABO", rh: "Rh" }} onAccept={value => { setDraft(value); setBase(latest); setConflict(false); setLatest(null); setError(null); retry.current = null; }} />}
    <fieldset className={styles.fields} disabled={busy || conflict}><PersonFields draft={draft} change={change} patient={base.patient_id ? { id: base.patient_id, name_ar: base.name } : null} onPatient={() => {}} base={base} facilityId={facilityId} options={options} fieldError={key => error?.fields[key] && <small className={styles.fieldError}>{error.fields[key]}</small>} /><BloodFields draft={draft} change={change} /></fieldset><div className={styles.modalActions}><button className={styles.primary} disabled={busy || conflict}>حفظ بيانات الشخص</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
