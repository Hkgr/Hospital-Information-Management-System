"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";
import Modal from "../clinics/Modal";
import CategoryEditor from "./CategoryEditor";
import { type Item, type Kind, type Choices, useCatalogRequest, kindName, itemPath } from "./api";
import styles from "../clinics/clinics.module.css";

type Fields = { code: string; name_ar: string; description: string; classification: string; is_active: boolean };
const labels: Record<keyof Fields, string> = { code: "الكود", name_ar: "الاسم", description: "الوصف", classification: "التصنيف", is_active: "الحالة" };
const fieldsOf = (item?: Item): Fields => ({ code: item?.code ?? "", name_ar: item?.name_ar ?? "", description: item?.description ?? "", classification: String(item?.category_id ?? item?.procedure_type_id ?? ""), is_active: item?.is_active ?? true });

export default function CatalogEditor({ kind, item, facilityId, canCreateCategory = false, onClose, onSaved, onRefresh }: { kind: Kind; item?: Item; facilityId: number; canCreateCategory?: boolean; onClose: () => void; onSaved: () => void; onRefresh: () => void }) {
  const [base, setBase] = useState(item);
  const [fields, setFields] = useState<Fields>(() => fieldsOf(item));
  const [error, setError] = useState<AuthError | null>(null);
  const [busy, setBusy] = useState(false);
  const [conflict, setConflict] = useState(false);
  const [latest, setLatest] = useState<Item | null>(null);
  const [selected, setSelected] = useState<Partial<Record<keyof Fields, boolean>>>({});
  const [fetching, setFetching] = useState(false);
  const [reloadError, setReloadError] = useState("");
  const controller = useRef<AbortController | null>(null);
  const pending = useRef(false);
  useEffect(() => () => controller.current?.abort(), []);
  const [categoryOpen, setCategoryOpen] = useState(false), [categoryRevision, setCategoryRevision] = useState(0), [categoryNotice, setCategoryNotice] = useState("");
  const choices = useCatalogRequest<Choices>(`service-catalog/classifications?facility_id=${facilityId}`, false, false, categoryRevision);
  const options = (kind === "service" ? choices.data?.categories : choices.data?.procedure_types) ?? [];
  const relation = kind === "service" ? "category_id" : "procedure_type_id";
  const fieldError = (name: string) => error?.fields[name] && <span id={`catalog-${name}-error`} className={styles.fieldError}>{error.fields[name]}</span>;
  const display = (key: keyof Fields, value: string | boolean) => key === "is_active" ? (value ? "فعال" : "غير فعال") : key === "classification" ? options.find(option => String(option.id) === value)?.name_ar ?? String(value || "دون تصنيف") : String(value || "—");

  async function reload() {
    if (!base || pending.current) return;
    pending.current = true; setFetching(true); setReloadError("");
    const active = new AbortController(); controller.current = active;
    try {
      const row = await apiRequest<Item>(`${itemPath(base)}?facility_id=${facilityId}`, { signal: active.signal });
      if (!active.signal.aborted) { setLatest(row); setSelected({}); onRefresh(); }
    } catch { if (!active.signal.aborted) setReloadError("تعذّر جلب أحدث نسخة؛ مسودتك محفوظة. حاول مجددًا."); }
    finally { if (!active.signal.aborted) { pending.current = false; setFetching(false); } }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current || conflict) return;
    pending.current = true; setBusy(true); setError(null);
    const active = new AbortController(); controller.current = active;
    try {
      const { classification, ...values } = fields;
      await apiRequest<Item>(base ? itemPath(base) : "service-catalog", { method: base ? "PUT" : "POST", signal: active.signal,
        body: JSON.stringify({ ...values, [relation]: classification ? Number(classification) : null, facility_id: facilityId, ...(base ? { lock_version: base.lock_version } : { kind }) }) });
      if (!active.signal.aborted) onSaved();
    } catch (reason) {
      if (!active.signal.aborted) {
        setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. مسودتك محفوظة."));
        if (reason instanceof AuthError && reason.code === "CATALOG_VERSION_CONFLICT") { setConflict(true); setLatest(null); }
      }
    } finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <><Modal title={`${item ? "تعديل" : "إضافة"} ${kindName(kind)}`} onClose={onClose} busy={busy}>
    <form className={styles.form} onSubmit={save}>
      <p className={styles.scopeNote}>تعريف مشترك بين المنشآت، وليس تسجيل تقديم علاج لمريض. النوع ثابت؛ تغيير الحالة يؤثر في الاختيار الجديد في جميع المنشآت، ويحفظ التاريخ.</p>
      {error && <p role="alert" className={styles.error}>{conflict ? "عدّل مستخدم آخر العنصر. مسودتك محفوظة؛ اجلب أحدث نسخة وراجع التغييرات قبل الحفظ." : error.message}</p>}
      {conflict && <div><button type="button" className={styles.secondary} disabled={fetching} onClick={() => void reload()}>{fetching ? "جارٍ جلب أحدث نسخة…" : "جلب أحدث نسخة"}</button>{reloadError && <p role="alert">{reloadError}</p>}</div>}
      {latest && <section className={styles.conflictReview} aria-label="مراجعة التعارض"><h3>أحدث نسخة ومسودتك</h3><p className={styles.hint}>تُحفظ أحدث قيم المستخدم الآخر افتراضيًا. اختر فقط التغييرات التي تريد تطبيقها؛ لا يتم الحفظ تلقائيًا.</p>
        {(Object.keys(labels) as (keyof Fields)[]).map(key => <div className={styles.reviewField} key={key}><strong>{labels[key]}</strong><p>أحدث نسخة: {display(key, fieldsOf(latest)[key])}</p><p>مسودتك: {display(key, fields[key])}</p>{fields[key] !== fieldsOf(latest)[key] && <label><input type="checkbox" checked={!!selected[key]} onChange={e => setSelected({ ...selected, [key]: e.target.checked })} />تطبيق مسودتي: {labels[key]}</label>}</div>)}
        {latest.archived_at ? <p role="alert">أصبح العنصر مؤرشفًا. أغلق النافذة واستعده بإجراء مستقل قبل التعديل؛ لم تُفقد مسودتك.</p> : <button type="button" className={styles.secondary} onClick={() => {
          const merged = fieldsOf(latest); for (const key of Object.keys(labels) as (keyof Fields)[]) if (selected[key]) Object.assign(merged, { [key]: fields[key] });
          setFields(merged); setBase(latest); setLatest(null); setConflict(false); setError(null);
        }}>اعتماد الاختيارات للمراجعة</button>}
      </section>}
      <fieldset className={styles.fields} disabled={busy || conflict}>
        <label>الكود *<input autoFocus required maxLength={50} dir="auto" value={fields.code} onChange={e => setFields({ ...fields, code: e.target.value })} aria-invalid={!!error?.fields.code} aria-describedby={error?.fields.code ? "catalog-code-error" : undefined} />{fieldError("code")}</label>
        <label>الاسم *<input required maxLength={200} value={fields.name_ar} onChange={e => setFields({ ...fields, name_ar: e.target.value })} aria-invalid={!!error?.fields.name_ar} />{fieldError("name_ar")}</label>
        <label className={styles.full}>الوصف<textarea maxLength={10000} rows={4} value={fields.description} onChange={e => setFields({ ...fields, description: e.target.value })} />{fieldError("description")}</label>
        <label>{kind === "service" ? "فئة الخدمة *" : "نوع الإجراء"}<select required={kind === "service"} value={fields.classification} onChange={e => setFields({ ...fields, classification: e.target.value })}><option value="">{kind === "service" ? "اختر فئة الخدمة" : "دون تصنيف"}</option>{fields.classification && !options.some(option => String(option.id) === fields.classification) && <option value={fields.classification}>التصنيف الحالي ({fields.classification})</option>}{options.map(option => <option key={option.id} value={option.id}>{option.name_ar}</option>)}</select>{fieldError(relation)}</label>
        {kind === "service" && canCreateCategory && <div className={styles.actions}><button type="button" className={styles.secondary} onClick={() => setCategoryOpen(true)}>إضافة فئة</button></div>}
        <label>الحالة<select value={String(fields.is_active)} onChange={e => setFields({ ...fields, is_active: e.target.value === "true" })}><option value="true">فعال</option><option value="false">غير فعال</option></select></label>
        {categoryNotice && <p role="status">{categoryNotice}</p>}
        {choices.loading && <p role="status">جارٍ تحميل التصنيفات…</p>}{choices.error && <p role="alert">{choices.error} <button type="button" onClick={choices.retry}>إعادة تحميل التصنيفات</button></p>}
        {!choices.loading && !choices.error && kind === "service" && !options.length && <p className={styles.hint}>لا توجد فئات خدمة فعالة. يلزم إعداد فئات الدليل المعتمدة قبل إضافة خدمة.</p>}
      </fieldset>
      <div className={styles.modalActions}><button type="submit" className={styles.primary} disabled={busy || conflict}>{busy ? "جارٍ الحفظ…" : "حفظ التعريف"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
    </form>
  </Modal>{categoryOpen && <CategoryEditor facilityId={facilityId} onClose={() => setCategoryOpen(false)} onSaved={category => {
    setCategoryOpen(false); setCategoryRevision(n => n + 1);
    if (category.is_active) { setFields(previous => ({ ...previous, classification: String(category.id) })); setCategoryNotice(`أضيفت الفئة ${category.name_ar} واختيرت للخدمة.`); }
    else setCategoryNotice("حُفظت الفئة غير الفعالة؛ اختر فئة فعالة للخدمة الجديدة.");
  }} />}</>;
}
