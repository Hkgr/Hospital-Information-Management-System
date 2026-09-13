"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import Modal from "../clinics/Modal";
import styles from "../clinics/clinics.module.css";

export type Category = { id: number; code: string; name_ar: string; is_active: boolean };
export default function CategoryEditor({ facilityId, onClose, onSaved }: { facilityId: number; onClose: () => void; onSaved: (category: Category) => void }) {
  const [fields, setFields] = useState({ code: "", name_ar: "", is_active: true });
  const [error, setError] = useState<AuthError | null>(null), [busy, setBusy] = useState(false);
  const pending = useRef(false), controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current) return;
    pending.current = true; setBusy(true); setError(null); const active = new AbortController(); controller.current = active;
    try { const category = await apiRequest<Category>("service-catalog/categories", { method: "POST", signal: active.signal, body: JSON.stringify({ facility_id: facilityId, ...fields }) }); if (!active.signal.aborted) onSaved(category); }
    catch (reason) { if (!active.signal.aborted) setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر إنشاء الفئة. المدخلات محفوظة.")); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title="إضافة فئة" size="compact" busy={busy} onClose={onClose}><form className={styles.form} onSubmit={save}>
    <p className={styles.scopeNote}>الفئة تعريف مشترك بين المنشآت. الفئات الفعالة فقط متاحة للخدمات الجديدة.</p>
    {error && <p role="alert" className={styles.error}>{error.message}</p>}
    <fieldset disabled={busy} className={styles.fields}>{([['code', 'رمز الفئة', 50], ['name_ar', 'اسم الفئة', 200]] as const).map(([key, label, max]) => <label className={styles.full} key={key}>{label}<input required maxLength={max} aria-label={label} value={fields[key]} onChange={e => setFields({ ...fields, [key]: e.target.value })} aria-invalid={!!error?.fields[key]} aria-describedby={error?.fields[key] ? `category-${key}-error` : undefined} />{error?.fields[key] && <span id={`category-${key}-error`} className={styles.fieldError}>{error.fields[key]}</span>}</label>)}
      <label className={styles.full}>حالة الفئة<select value={String(fields.is_active)} onChange={e => setFields({ ...fields, is_active: e.target.value === "true" })}><option value="true">فعالة</option><option value="false">غير فعالة</option></select></label>
    </fieldset><div className={styles.modalActions}><button className={styles.primary} disabled={busy}>{busy ? "جارٍ الحفظ…" : "حفظ الفئة"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </form></Modal>;
}
