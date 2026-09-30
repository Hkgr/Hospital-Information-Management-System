"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import { useClinicRequest } from "../clinics/api";
import Modal from "../clinics/Modal";
import type { Permission } from "./TaskPermissionPicker";
import styles from "../clinics/clinics.module.css";
import ui from "./permissions.module.css";

type Role = { id: number; name_ar: string };
type Access = { id: number; username: string; name: string; is_active: boolean; lock_version: number; protected: boolean; local_role_ids: number[]; assignable_roles: Role[]; global_permissions: Permission[]; global_permission_ids: number[]; global_effective_codes: string[]; capabilities: { assign: boolean; global_view: boolean; global_manage: boolean } };

export default function AccountAccessEditor({ id, facility, onClose, onSaved }: { id: number; facility: number; onClose: () => void; onSaved: () => void }) {
  const request = useClinicRequest<Access>(`users/${id}/access?facility_id=${facility}`);
  return <Modal title="تفاصيل الحساب والتفويض" onClose={onClose} size="wide">
    {request.error && <p role="alert">{request.error}<button onClick={request.retry}>إعادة المحاولة</button></p>}
    {!request.data && !request.error && <p role="status">جارٍ تحميل وصول الحساب…</p>}
    {request.data && <AccessForm key={request.data.lock_version} data={request.data} facility={facility} onSaved={onSaved} />}
  </Modal>;
}

function AccessForm({ data, facility, onSaved }: { data: Access; facility: number; onSaved: () => void }) {
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
  async function save() {
    if (pending.current || !preview || !reason.trim() || !(changedLocal || changedGlobal)) return;
    const controller = new AbortController(); pending.current = controller; setBusy(true); setError("");
    try {
      await apiRequest(`users/${data.id}/access`, { method: "PUT", signal: controller.signal, body: JSON.stringify({ facility_id: facility, lock_version: data.lock_version, reason, ...(changedLocal ? { local_role_ids: local } : {}), ...(changedGlobal ? { global_permission_ids: global } : {}) }) });
      if (!controller.signal.aborted) onSaved();
    } catch (e) { if (!controller.signal.aborted) setError(e instanceof AuthError ? e.message : "تعذّر حفظ التفويض. بقيت اختياراتك للمراجعة."); }
    finally { if (!controller.signal.aborted) { pending.current = null; setBusy(false); } }
  }
  return <div className={styles.form}>
    <p><strong>{data.name}</strong> · <bdi>{data.username}</bdi> · {data.is_active ? "فعال" : "غير فعال"}</p>
    {data.protected && <p className={styles.hint}>الحساب محمي. تعديل صلاحيات المدير الشامل يتم من تفاصيل الدور؛ لا يمكن سحب تعيينه هنا.</p>}
    <section className={ui.summary}><h3>الدور داخل المشفى</h3><p>{describe(local, data.assignable_roles)}</p><details><summary>مراجعة الأدوار المحلية وتغييرها</summary><label className={styles.search}>ابحث عن دور<input type="search" value={roleSearch} onChange={e => setRoleSearch(e.target.value)} /></label><fieldset disabled={!data.capabilities.assign || busy || preview} className={styles.fields}><legend>الأدوار المتاحة للإسناد</legend>{data.assignable_roles.filter(role => role.name_ar.includes(roleSearch.trim())).map(role => <label className={ui.permission} key={role.id}><input type="checkbox" checked={local.includes(role.id)} onChange={() => setLocal(toggle(local, role.id))} /><span>{role.name_ar}</span></label>)}</fieldset></details></section>
    {data.capabilities.global_view ? <fieldset disabled={!data.capabilities.global_manage || busy || preview} className={styles.fields}><legend>تفويض عالمي مستقل</legend><p className={`${styles.hint} ${styles.full}`}>الاختيارات التالية فقط هي التفويض العالمي الفعّال بعد الحفظ. لا يُنسخ الدور المحلي. حفظ هذه الخطوة يستبدل التعيينات العالمية السابقة مع حفظها في التدقيق؛ لا يمنح تاريخًا طبيًا داخل مشفى آخر.</p>{data.global_permissions.map(permission => <label className={ui.permission} key={permission.id}><input type="checkbox" checked={global.includes(permission.id)} disabled={["roles.delegate", "users.global.manage"].includes(permission.code)} onChange={() => setGlobal(toggle(global, permission.id))} /><span>{permission.name_ar}</span></label>)}</fieldset> : <p className={styles.hint}>عرض التفويض العالمي يحتاج صلاحية مستقلة؛ لا يكفي عرض الحساب.</p>}
    {(data.capabilities.assign || data.capabilities.global_manage) && <>
      <label>سبب التغيير<textarea required minLength={3} maxLength={255} disabled={busy || preview} value={reason} onChange={e => setReason(e.target.value)} /></label>
      {preview && <section className={styles.panel} aria-label="معاينة فرق الوصول"><h3>راجع الفرق قبل التأكيد</h3><p>إضافة محلية: {describe(localDiff.added, data.assignable_roles)}</p><p>سحب محلي: {describe(localDiff.removed, data.assignable_roles)}</p><p>إضافة عالمية: {describe(globalDiff.added, data.global_permissions)}</p><p>سحب عالمي: {describe(globalDiff.removed, data.global_permissions)}</p><p>التفويض العالمي بعد الحفظ: {describe(global, data.global_permissions)}</p></section>}
      {error && <p role="alert" className={styles.error}>{error}</p>}
      <div className={styles.modalActions}>{preview ? <><button className={styles.primary} disabled={busy} onClick={() => void save()}>{busy ? "جارٍ الحفظ…" : "تأكيد التفويض"}</button><button className={styles.secondary} disabled={busy} onClick={() => setPreview(false)}>العودة للاختيارات</button></> : <button className={styles.primary} disabled={!(changedLocal || changedGlobal) || reason.trim().length < 3 || (changedLocal && !local.length)} onClick={() => setPreview(true)}>معاينة فرق الوصول</button>}</div>
    </>}
  </div>;
}
