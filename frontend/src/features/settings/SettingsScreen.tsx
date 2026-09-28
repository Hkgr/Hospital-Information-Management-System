"use client";

import Link from "next/link";
import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest, AuthError } from "../auth/api";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest } from "../clinics/api";
import Modal from "../clinics/Modal";
import styles from "../clinics/clinics.module.css";
import ui from "./settings.module.css";

type Policy = { idle_minutes: number; lock_version: number };
type Settings = Policy & { facility: { id: number; name_ar: string }; can_update: boolean; system_policy: Policy | null };
type Session = { idle_timeout: number | null; applied_idle_timeout?: number; remaining_seconds?: number };

export default function SettingsScreen() {
  const { access } = useIdentity(), query = useSearchParams();
  const { entry } = directoryFacility(access, "settings.view", query.get("facility_id"));
  if (!entry || query.getAll("facility_id").length > 1 || (query.has("facility_id") && !/^[1-9]\d*$/.test(query.get("facility_id")!))) return <section className={styles.status}><h2>الإعدادات غير متاحة</h2><p role="alert">تعذّر تحديد منشأة مسموحة. تحقق من الرابط أو تواصل مع المسؤول. يمكنك تسجيل الخروج من قائمة الحساب.</p></section>;
  return <div className={styles.screen}><div className={styles.context}><span>المنشأة</span><strong>{entry.facility.name_ar}</strong></div><Workspace key={entry.facility.id} facility={entry.facility.id} /></div>;
}

function Workspace({ facility }: { facility: number }) {
  const [revision, setRevision] = useState(0);
  const [saved, setSaved] = useState(false);
  const request = useClinicRequest<Settings>(`settings?facility_id=${facility}`, false, false, revision);
  const session = useClinicRequest<Session>("session", false, false, revision);
  return <div className={ui.page}><header className={styles.heading}><div><h2>الإعدادات</h2><p>بيانات المنشأة وسياسة أمان جلسات الويب.</p></div><Link className={styles.secondary} href={`/guide?facility_id=${facility}`}>دليل الاستخدام</Link></header>
    {saved && <p className={ui.success} role="status">حُفظت الإعدادات وسُجّل التغيير بنجاح.</p>}
    {request.loading && <p className={styles.status} role="status">جارٍ تحميل الإعدادات…</p>}
    {request.error && <section className={styles.status}><p role="alert">{request.error}</p><button className={styles.secondary} onClick={request.retry}>إعادة المحاولة</button></section>}
    {request.data && <><SettingsForm key={`facility-${request.data.lock_version}`} value={request.data} facility={facility} canUpdate={request.data.can_update} onSaved={() => { setSaved(true); setRevision(v => v + 1); }} />
      <section className={`${styles.panel} ${ui.card}`}><h3>السياسة الفعلية لجلسة المستخدم</h3><p>للمستخدم متعدد المنشآت تُطبّق أقصر مدة بين جميع المنشآت الفعالة المتاحة له، وليس المنشأة المختارة هنا. يستخدم مدير النظام الشامل سياسته العامة المستقلة.</p>{session.data && <p>{session.data.idle_timeout === null ? "هذا رمز تكامل غير تفاعلي؛ لا تنطبق عليه جلسة الويب." : `المدة الفعلية الحالية: ${(session.data.applied_idle_timeout ?? session.data.idle_timeout) / 60} دقيقة. السياسة الحالية للنشاط التالي: ${session.data.idle_timeout / 60} دقيقة.`}</p>}{session.error && <p role="alert">تعذّر جلب مدة الجلسة. <button className={styles.textButton} onClick={session.retry}>إعادة المحاولة</button></p>}<p>رفع المدة يستفيد منه النشاط الحقيقي التالي لجلسة ما زالت حية؛ لا يعيد جلسة انتهت. القراءة والحفظ والطلبات الخلفية لا تمدد الجلسة.</p></section>
      {request.data.system_policy && <SettingsForm key={`system-${request.data.system_policy.lock_version}`} value={request.data.system_policy} system facility={facility} canUpdate={request.data.can_update} onSaved={() => { setSaved(true); setRevision(v => v + 1); }} />}</>}
  </div>;
}

function SettingsForm({ value, facility, system = false, canUpdate, onSaved }: { value: Policy & { facility?: { name_ar: string } }; facility: number; system?: boolean; canUpdate: boolean; onSaved: () => void }) {
  const [minutes, setMinutes] = useState(String(value.idle_minutes)), [name, setName] = useState(value.facility?.name_ar ?? ""), [reason, setReason] = useState("");
  const [error, setError] = useState<AuthError | null>(null), [busy, setBusy] = useState(false), [confirm, setConfirm] = useState<"save" | "defaults" | null>(null);
  const [conflicted, setConflicted] = useState(false);
  const [latest, setLatest] = useState<Settings | null>(null), [version, setVersion] = useState(value.lock_version), [baseline, setBaseline] = useState(value.idle_minutes);
  const pending = useRef<AbortController | null>(null), root = useRef<HTMLFormElement>(null);
  useEffect(() => () => pending.current?.abort(), []);
  const policyChanged = Number(minutes) !== baseline;
  async function refresh() {
    if (pending.current) return;
    const c = new AbortController(); pending.current = c; setBusy(true);
    try { const current = await apiRequest<Settings>(`settings?facility_id=${facility}`, { signal: c.signal }); if (!c.signal.aborted) setLatest(current); }
    catch (e) { if (!c.signal.aborted) setError(e as AuthError); }
    finally { if (!c.signal.aborted) setBusy(false); pending.current = null; }
  }
  async function save() {
    if (pending.current || !canUpdate) return;
    const c = new AbortController(); pending.current = c; setBusy(true); setError(null); setConfirm(null);
    try {
      await apiRequest<Settings>(system ? "settings/system-session" : "settings", { method: "PUT", signal: c.signal, body: JSON.stringify({ facility_id: facility, lock_version: version, idle_minutes: Number(minutes), reason: reason || null, ...(!system ? { name_ar: name } : {}) }) });
      if (!c.signal.aborted) { window.dispatchEvent(new Event("hospital-access-refresh")); onSaved(); }
    } catch (e) { if (!c.signal.aborted) { setConflicted(e instanceof AuthError && e.code === "SETTINGS_VERSION_CONFLICT"); setError(e instanceof AuthError ? e : new AuthError(0, "FAILED", "تعذّر الحفظ. بقيت مسودتك محفوظة هنا.")); requestAnimationFrame(() => root.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()); } }
    finally { if (!c.signal.aborted) setBusy(false); pending.current = null; }
  }
  return <form ref={root} className={`${styles.panel} ${ui.card}`} onSubmit={e => { e.preventDefault(); if (policyChanged) setConfirm("save"); else void save(); }}>
    <h3>{system ? "سياسة جلسات مدير النظام الشامل" : "إعدادات المنشأة"}</h3>
    <p>{system ? "إعداد عام يطبق على جلسات أصحاب التفويض النظامي الشامل فقط، ولا يغيّر سياسات المنشآت." : "اسم العرض يظهر في واجهات المنشأة وتقاريرها. لا يتغير كود المنشأة أو توقيتها أو بياناتها التاريخية."}</p>
    {error && <p className={styles.error} role="alert">{error.message}</p>}
    {conflicted && <button type="button" className={styles.secondary} disabled={busy} onClick={() => void refresh()}>جلب القيم الحالية للمراجعة</button>}
    {latest && <section className={ui.comparison}><h4>راجع أحدث القيم ومسودتك</h4><p>لم تُدمج التغييرات ولم تُحفظ. اختر القيم الحالية أو احتفظ بمسودتك صراحة.</p>{!system && <div><span>اسم المنشأة الحالي: {latest.facility.name_ar}</span><button type="button" className={styles.secondary} onClick={() => setName(latest.facility.name_ar)}>استخدام الاسم الحالي</button></div>}<div><span>المدة الحالية: {(system ? latest.system_policy : latest)?.idle_minutes} دقيقة — مسودتك: {minutes}</span><button type="button" className={styles.secondary} onClick={() => setMinutes(String((system ? latest.system_policy : latest)!.idle_minutes))}>استخدام المدة الحالية</button></div><button type="button" className={styles.secondary} onClick={() => { const p = (system ? latest.system_policy : latest)!; setVersion(p.lock_version); setBaseline(p.idle_minutes); setLatest(null); setError(null); setConflicted(false); }}>راجعت القيم؛ متابعة بالاختيارات الظاهرة دون حفظ</button></section>}
    <fieldset className={styles.fields} disabled={!canUpdate || busy || !!latest}>
      {!system && <label>اسم المنشأة<input required maxLength={200} value={name} onChange={e => setName(e.target.value)} aria-invalid={!!error?.fields.name_ar} />{error?.fields.name_ar && <small className={styles.fieldError}>{error.fields.name_ar}</small>}</label>}
      <label>مدة الخمول (دقائق)<input type="number" min={1} max={60} step={1} required value={minutes} onChange={e => setMinutes(e.target.value)} aria-invalid={!!error?.fields.idle_minutes} /><small>من 1 إلى 60 دقيقة. القيمة المحفوظة: {baseline} دقيقة.</small>{error?.fields.idle_minutes && <small className={styles.fieldError}>{error.fields.idle_minutes}</small>}</label>
      <label className={styles.full}>سبب تغيير سياسة الجلسة{policyChanged ? " *" : ""}<textarea required={policyChanged} maxLength={500} rows={3} value={reason} onChange={e => setReason(e.target.value)} aria-invalid={!!error?.fields.reason} />{error?.fields.reason && <small className={styles.fieldError}>{error.fields.reason}</small>}</label>
    </fieldset>
    <p className={ui.notice}>الافتراضي دقيقتان. تغيير المدة يغيّر السياسة الافتراضية المطلوبة من الجهة. خفضها يسري عند أول تحقق تالٍ وقد ينهي جلستك الحالية ويفقد التغييرات غير المحفوظة. لا يمكن تعطيل انتهاء الجلسة.</p>
    {canUpdate && <div className={styles.actions}><button className={styles.primary} disabled={busy || !!latest || conflicted}>{busy ? "جارٍ الحفظ…" : "حفظ الإعدادات"}</button><button type="button" className={styles.secondary} disabled={busy || !!latest} onClick={() => setConfirm("defaults")}>معاينة استعادة المدة الافتراضية</button></div>}
    {confirm && <Modal title={confirm === "defaults" ? "معاينة استعادة الافتراضي" : "تأكيد تغيير سياسة الجلسة"} onClose={() => setConfirm(null)} size="compact"><div className={ui.page}><p>مدة الخمول: {confirm === "defaults" ? minutes : baseline} ← {confirm === "defaults" ? 2 : minutes} دقيقة. {confirm === "defaults" ? "لن يتغير اسم المنشأة. ستُراجع السبب ثم تحفظ صراحة." : "قد تنتهي الجلسة الحالية عند خفض المدة. احفظ عملك في التبويبات الأخرى أولًا."}</p><div className={styles.actions}><button className={styles.primary} type="button" onClick={() => { if (confirm === "defaults") { setMinutes("2"); setConfirm(null); } else void save(); }}>{confirm === "defaults" ? "استخدام دقيقتين في المسودة" : "تأكيد الحفظ"}</button><button className={styles.secondary} type="button" onClick={() => setConfirm(null)}>إلغاء</button></div></div></Modal>}
  </form>;
}
