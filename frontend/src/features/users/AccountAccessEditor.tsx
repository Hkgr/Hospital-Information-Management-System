"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import { useClinicRequest } from "../clinics/api";
import Modal from "../clinics/Modal";
import type { Permission } from "./TaskPermissionPicker";
import styles from "../clinics/clinics.module.css";
import ui from "./permissions.module.css";

type Role = { id: number; name_ar: string; permissions: Permission[] };
type Access = { id: number; username: string; name: string; is_active: boolean; lock_version: number; access_fingerprint: string; protected: boolean; local_role_ids: number[]; historical_roles?: { id: number; name_ar: string }[]; assignable_roles: Role[]; global_permissions: Permission[]; global_permission_ids: number[]; global_effective_codes: string[]; capabilities: { assign: boolean; global_view: boolean; global_manage: boolean } };

export default function AccountAccessEditor({ id, facility, onClose, onSaved }: { id: number; facility: number; onClose: () => void; onSaved: () => void }) {
  const request = useClinicRequest<Access>(`users/${id}/access?facility_id=${facility}`);
  return <Modal title="تفاصيل الحساب والتفويض" onClose={onClose} size="wide">
    {request.error && <p role="alert">{request.error}<button onClick={request.retry}>إعادة المحاولة</button></p>}
    {!request.data && !request.error && <p role="status">جارٍ تحميل وصول الحساب…</p>}
    {request.data && <AccessForm key={request.data.lock_version} data={request.data} facility={facility} onSaved={onSaved} />}
  </Modal>;
}

function AccessForm({ data: initial, facility, onSaved }: { data: Access; facility: number; onSaved: () => void }) {
  const [data, setData] = useState(initial);
  const [conflict, setConflict] = useState(false), [latest, setLatest] = useState<Access | null>(null);
  const [previousDraft, setPreviousDraft] = useState<{ local: string; global: string } | null>(null);
  const [local, setLocal] = useState(data.local_role_ids), [global, setGlobal] = useState(data.global_permission_ids);
  const [roleSearch, setRoleSearch] = useState("");
  const [reason, setReason] = useState(""), [preview, setPreview] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState("");
  const pending = useRef<AbortController | null>(null);
  useEffect(() => () => pending.current?.abort(), []);
  const diff = (before: number[], after: number[]) => ({ added: after.filter(id => !before.includes(id)), removed: before.filter(id => !after.includes(id)) });
  const localDiff = diff(data.local_role_ids, local), globalDiff = diff(data.global_permission_ids, global);
  const changedLocal = !!(localDiff.added.length + localDiff.removed.length), changedGlobal = !!(globalDiff.added.length + globalDiff.removed.length);
  const toggle = (ids: number[], id: number) => ids.includes(id) ? ids.filter(value => value !== id) : [...ids, id];
  const describe = (ids: number[], choices: { id: number; name_ar: string }[]) => ids.map(id => choices.find(p => p.id === id)?.name_ar ?? `تعيين ${id}`).join("، ") || "لا شيء";
  const reviewedRoles = [...new Set([...data.local_role_ids, ...local, ...(latest?.local_role_ids ?? [])])];
  const pendingGlobal = data.assignable_roles.filter(role => local.includes(role.id)).flatMap(role => role.permissions).filter(p => p.scope === "global" && !global.includes(p.id));
  function chooseRole(role: Role) {
    setLocal(toggle(local, role.id));
    if (!local.includes(role.id) && data.capabilities.global_manage) {
      // Prepare an explicit global subset in the same reviewed transaction;
      // never copy local permissions or grant the protected delegation powers.
      const allowed = new Set(data.global_permissions.filter(p => !["roles.delegate", "users.global.manage"].includes(p.code)).map(p => p.id));
      setGlobal([...new Set([...global, ...role.permissions.filter(p => p.scope === "global" && allowed.has(p.id)).map(p => p.id)])]);
    }
  }
  async function save() {
    if (pending.current || conflict || !preview || !reason.trim() || !(changedLocal || changedGlobal)) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError("");
    try {
      await apiRequest(`users/${data.id}/access`, { method: "PUT", signal: controller.signal, body: JSON.stringify({ facility_id: facility, lock_version: data.lock_version, access_fingerprint: data.access_fingerprint, reason, ...(changedLocal ? { local_role_ids: local } : {}), ...(changedGlobal ? { global_permission_ids: global } : {}) }) });
      if (!controller.signal.aborted) onSaved();
    } catch (e) { if (!controller.signal.aborted) { setError(e instanceof AuthError ? e.message : "تعذّر حفظ التفويض. بقيت اختياراتك للمراجعة."); if (e instanceof AuthError && e.status === 409) { setConflict(true); setLatest(null); } } }
    finally { if (!controller.signal.aborted) { pending.current = null; setBusy(false); } }
  }
  async function reload() {
    if (pending.current) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError("");
    try {
      const current = await apiRequest<Access>(`users/${data.id}/access?facility_id=${facility}`, { signal: controller.signal });
      if (!controller.signal.aborted) setLatest(current);
    } catch { if (!controller.signal.aborted) setError("تعذّر جلب الحالة الحالية. مسودتك محفوظة؛ يمكنك إعادة المحاولة."); }
    finally { if (!controller.signal.aborted) { pending.current = null; setBusy(false); } }
  }
  function reviewCurrent() {
    if (!latest || busy) return;
    setPreviousDraft({ local: describe(local, data.assignable_roles), global: describe(global, data.global_permissions) });
    setData(latest); setLocal(latest.local_role_ids); setGlobal(latest.global_permission_ids);
    setLatest(null); setConflict(false); setPreview(false); setError("");
  }
  return <div className={styles.form}>
    <p><strong>{data.name}</strong> · <bdi>{data.username}</bdi> · {data.is_active ? "فعال" : "غير فعال"}</p>
    {data.protected && <p className={styles.hint}>الحساب محمي. تعديل صلاحيات المدير الشامل يتم من تفاصيل الدور؛ لا يمكن سحب تعيينه هنا.</p>}
    {!data.local_role_ids.length && !data.historical_roles?.length && <p className={styles.scopeNote}>لا يوجد دور فعال داخل المشفى. يمكنك إسناد دور صالح؛ تبقى الارتباطات المعطلة محفوظة في السجل.</p>}
    {!!data.historical_roles?.length && <p className={styles.hint}>إسناد توافق محفوظ: {data.historical_roles.map(role => role.name_ar).join("، ")}. لا يُعرض لإسناد جديد، ولا يُحذف عند اختيار دور آخر.</p>}
    {previousDraft && <section className={styles.conflictReview} aria-label="المسودة السابقة"><h3>مسودتك السابقة للمقارنة فقط</h3><p>الأدوار: {previousDraft.local}</p><p>التفويض العالمي: {previousDraft.global}</p><p className={styles.hint}>الاختيارات أدناه تبدأ من الحالة الحالية. اختر ما تريد تغييره ثم عاين الفرق؛ لم تُطبّق المسودة تلقائيًا.</p></section>}
    <section className={ui.summary}><h3>الدور داخل المشفى</h3><p>{describe(local, data.assignable_roles)}</p><details><summary>مراجعة الأدوار المحلية وتغييرها</summary><label className={styles.search}>ابحث عن دور<input type="search" value={roleSearch} onChange={e => setRoleSearch(e.target.value)} /></label><fieldset disabled={!data.capabilities.assign || busy || preview} className={styles.fields}><legend>الأدوار المتاحة للإسناد</legend>{data.assignable_roles.filter(role => role.name_ar.includes(roleSearch.trim())).map(role => <label className={ui.permission} key={role.id}><input type="checkbox" checked={local.includes(role.id)} onChange={() => chooseRole(role)} /><span>{role.name_ar}</span></label>)}</fieldset></details><p className={styles.hint}>عند إضافة دور، تُجهّز صلاحياته ذات النطاق العالمي فقط في المعاينة أدناه إذا كنت مخولًا. لا تُحفظ إلا مع تأكيد الفرق. إزالة دور محلي لا تسحب تفويضًا عالميًا مستقلًا؛ راجعه أدناه.</p></section>
    {!!pendingGlobal.length && <p role="status" className={styles.hint}>لن تُفعّل هذه المهام عالميًا بالاختيارات الحالية: {[...new Set(pendingGlobal.map(p => p.name_ar))].join("، ")}. {data.capabilities.global_manage ? "يمكن اختيارها أدناه قبل تأكيد الحفظ." : "تحتاج اعتماد المدير الشامل من شاشة الحساب نفسها."}</p>}
    {data.capabilities.global_view ? <fieldset disabled={!data.capabilities.global_manage || busy || preview} className={styles.fields}><legend>تفويض عالمي مستقل</legend><p className={`${styles.hint} ${styles.full}`}>الاختيارات التالية فقط هي التفويض العالمي الفعّال بعد الحفظ. لا يُنسخ الدور المحلي. حفظ هذه الخطوة يستبدل التعيينات العالمية السابقة مع حفظها في التدقيق؛ لا يمنح تاريخًا طبيًا داخل مشفى آخر.</p>{data.global_permissions.map(permission => <label className={ui.permission} key={permission.id}><input type="checkbox" checked={global.includes(permission.id)} disabled={["roles.delegate", "users.global.manage"].includes(permission.code)} onChange={() => setGlobal(toggle(global, permission.id))} /><span>{permission.name_ar}</span></label>)}</fieldset> : <p className={styles.hint}>عرض التفويض العالمي يحتاج صلاحية مستقلة؛ لا يكفي عرض الحساب.</p>}
    {(data.capabilities.assign || data.capabilities.global_manage) && <>
      <label>سبب التغيير<textarea required minLength={3} maxLength={255} disabled={busy || preview} value={reason} onChange={e => setReason(e.target.value)} /></label>
      {preview && <section className={styles.panel} aria-label="معاينة فرق الوصول"><h3>راجع الفرق قبل التأكيد</h3><p>إضافة محلية: {describe(localDiff.added, data.assignable_roles)}</p><p>سحب محلي: {describe(localDiff.removed, data.assignable_roles)}</p><p>إضافة عالمية: {describe(globalDiff.added, data.global_permissions)}</p><p>سحب عالمي: {describe(globalDiff.removed, data.global_permissions)}</p><p>التفويض العالمي بعد الحفظ: {describe(global, data.global_permissions)}</p></section>}
      {error && <p role="alert" className={styles.error}>{error}</p>}
      {conflict && <section className={styles.conflictReview} aria-label="تعارض حالة الوصول"><h3>تغيّرت حالة الوصول منذ المعاينة</h3><p>لم يُحفظ التغيير. اجلب الحالة الحالية وراجع الأدوار وصلاحياتها قبل اختيار التعديلات مجددًا.</p>{latest ? <><p>الأدوار الحالية: {describe(latest.local_role_ids, latest.assignable_roles)}</p><p>التفويض العالمي الحالي: {describe(latest.global_permission_ids, latest.global_permissions)}</p><button className={styles.secondary} disabled={busy} onClick={reviewCurrent}>بدء مراجعة جديدة بالقيم الحالية</button></> : <button className={styles.secondary} disabled={busy} onClick={() => void reload()}>{busy ? "جارٍ جلب الحالة…" : "جلب أحدث حالة الوصول"}</button>}</section>}
      {conflict && latest && <details className={styles.conflictReview}><summary>مقارنة صلاحيات الأدوار المحلية</summary>{reviewedRoles.map(id => {
        const before = data.assignable_roles.find(role => role.id === id), current = latest.assignable_roles.find(role => role.id === id);
        const describeRole = (role?: Role) => role ? role.permissions.map(permission => permission.name_ar).join("، ") || "لا صلاحيات فعالة" : "غير متاح للإسناد في هذه المعاينة";
        return <section key={id}><h4>{current?.name_ar ?? before?.name_ar ?? `دور ${id}`}</h4><p>عند المعاينة: {describeRole(before)}</p><p>الآن: {describeRole(current)}</p></section>;
      })}</details>}
      <div className={styles.modalActions}>{preview ? <><button className={styles.primary} disabled={busy || conflict} onClick={() => void save()}>{busy ? "جارٍ الحفظ…" : "تأكيد التفويض"}</button><button className={styles.secondary} disabled={busy || conflict} onClick={() => setPreview(false)}>العودة للاختيارات</button></> : <button className={styles.primary} disabled={conflict || !(changedLocal || changedGlobal) || reason.trim().length < 3 || (changedLocal && !local.length)} onClick={() => setPreview(true)}>معاينة فرق الوصول</button>}</div>
    </>}
  </div>;
}
