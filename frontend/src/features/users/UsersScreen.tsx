"use client";

import { useEffect, useRef, useState } from "react";
import { LuPlus, LuSearch, LuUsers } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { apiRequest, AuthError } from "@/features/auth/api";
import { directoryFacility } from "@/features/directory/facilityContext";
import { DirectoryRowActions, DirectoryTable } from "@/features/directory/DirectoryPrimitives";
import { Pagination } from "@/features/directory/Controls";
import { useClinicRequest, type Page } from "@/features/clinics/api";
import useClinicSearch from "@/features/clinics/useClinicSearch";
import Modal from "@/features/clinics/Modal";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import styles from "@/features/clinics/clinics.module.css";

import AccountAccessEditor from "./AccountAccessEditor";

type Role = { id: number; code: string; name_ar: string };
import TaskPermissionPicker, { missingRequirements, type Permission, type TaskTemplate } from "./TaskPermissionPicker";
type Group = { key: string; name_ar: string; permissions: Permission[] };
type ManagedRole = Role & { permissions: Permission[]; manageable: boolean; lock_version: number; protected?: boolean; locked_permissions?: string[] };
type Member = { id: number; username: string; name: string; email: string | null; is_active: boolean; last_login_at: string | null; roles: Role[]; legacy_search_access?: boolean; pending_global_permissions?: string[] };
type Options = {
  roles: Role[];
  permission_groups: Group[];
  task_templates: TaskTemplate[];
  capabilities: { view: boolean; create: boolean; delete: boolean; roles_view: boolean; roles_create: boolean; roles_update: boolean };
};

export default function UsersScreen() {
  const { access } = useIdentity();
  const query = useSearchParams();
  const { entry } = directoryFacility(access.map(e => ({ ...e, permissions: e.permissions.includes("roles.view") ? [...e.permissions, "users.view"] : e.permissions })), "users.view", query.get("facility_id"));
  const ids = query.getAll("facility_id");
  if (ids.length > 1 || (ids.length === 1 && (!/^[1-9]\d*$/.test(ids[0]) || !Number.isSafeInteger(Number(ids[0])) || Number(ids[0]) > 2147483647))) return <section className={styles.status}><h2>تعذّر اختيار المنشأة</h2><p role="alert">معرّف المنشأة غير صالح. افتح رابطًا صحيحًا أو سجّل الخروج من قائمة الحساب.</p></section>;
  if (!entry) return <section className={styles.status}><h2>إدارة المستخدمين غير متاحة</h2><p role="alert">ليس لديك وصول إلى مستخدمي المنشأة المطلوبة.</p></section>;
  return <div className={styles.screen}>
    <div className={styles.context}><LuUsers aria-hidden="true" /><span>المنشأة</span><strong>{entry.facility.name_ar}</strong></div>
    <UsersWorkspace key={entry.facility.id} facilityId={entry.facility.id} canViewUsers={access.find(e => e.facility.id === entry.facility.id)?.permissions.includes("users.view") ?? false} />
  </div>;
}

function UsersWorkspace({ facilityId, canViewUsers }: { facilityId: number; canViewUsers: boolean }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const pathname = usePathname();
  const [revision, setRevision] = useState(0);
  const [tab, setTab] = useState<"users" | "roles">(canViewUsers ? "users" : "roles");
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState("");
  const [creatingRole, setCreatingRole] = useState(false);
  const [editingRole, setEditingRole] = useState<ManagedRole | null>(null);
  const [pending, setPending] = useState<Member | null>(null);
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, searchParams.toString(), facilityId);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  const page = searchParams.get("page"); const perPage = searchParams.get("per_page");
  if (page) query.set("page", page);
  if (perPage) query.set("per_page", perPage);
  if (committed) query.set("search", committed);
  // Options is the response's data object; lists alone retain their envelope.
  // Never keep stale capability controls while options are reloading/failed.
  const options = useClinicRequest<Options>(`${canViewUsers ? "users/options" : "users/roles/options"}?facility_id=${facilityId}`, false, false, revision);
  const list = useClinicRequest<Page<Member>>(canViewUsers ? `users?${query}` : "", true, true, revision);
  const roles = useClinicRequest<{ data: ManagedRole[] }>(options.data?.capabilities.roles_view ? `users/roles?facility_id=${facilityId}` : "", true, true, revision);
  function filter(key: string, value: string) {
    cancel();
    const next = new URLSearchParams(query);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  }
  const caps = options.data?.capabilities;
  return <>
    <header className={styles.heading}><div><p className={styles.eyebrow}>الحسابات</p><h2>إدارة المستخدمين</h2><p>حدد صلاحيات الدور ثم أسنده للمستخدم. المدير الشامل يدير التفويض دون اشتراط استخدامه للصلاحيات التشغيلية.</p></div>
      <div className={styles.actions}>
        {tab === "users" && caps?.create && <button className={styles.primary} onClick={() => setCreating(true)}><LuPlus aria-hidden="true" />إضافة مستخدم</button>}
        {tab === "roles" && caps?.roles_create && <button className={styles.primary} onClick={() => setCreatingRole(true)}><LuPlus aria-hidden="true" />إضافة دور</button>}
      </div>
    </header>
    {notice && <p className={styles.status} role="status">{notice}</p>}
    {options.loading && <p className={styles.status} role="status">جارٍ تحميل خيارات إدارة المستخدمين…</p>}
    {options.error && <section className={styles.status}><p>تعذّر تحميل خيارات إدارة المستخدمين.</p><p role="alert">{options.error}</p><button className={styles.secondary} onClick={options.retry}>إعادة تحميل الخيارات</button></section>}
    {caps?.roles_view && <div className={styles.tabs} role="tablist" aria-label="أقسام إدارة المستخدمين">
      <button type="button" role="tab" disabled={!canViewUsers} aria-selected={tab === "users"} onClick={() => setTab("users")}>المستخدمون</button>
      <button type="button" role="tab" aria-selected={tab === "roles"} onClick={() => setTab("roles")}>الأدوار والصلاحيات</button>
    </div>}
    {tab === "users" && <section className={styles.panel} data-inset="none" aria-label="قائمة المستخدمين">
      <div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث في المستخدمين</span><input type="search" placeholder="اسم المستخدم أو الاسم…" value={search} onChange={event => change(event.target.value)} /></label></div>
      {list.error && <div className={styles.status}><p role="alert">{list.error}</p><button className={styles.secondary} onClick={list.retry}>إعادة المحاولة</button></div>}
      {!list.data && !list.error && <p className={styles.status} role="status">جارٍ تحميل المستخدمين…</p>}
      {list.data && <>
        <div className={styles.resultSummary}><strong>{list.data.meta.total} مستخدم</strong>{(searching || list.loading) && <span role="status">جارٍ تحديث النتائج…</span>}</div>
        <DirectoryTable label="جدول المستخدمين" busy={searching || list.loading} headers={["اسم المستخدم", "الاسم", "الدور", "الحالة", "الإجراءات"]}>
          {list.data.data.map(row => <tr key={row.id}>
            <td><bdi>{row.username}</bdi></td>
            <td>{row.name}</td>
            <td>{row.roles.map(role => role.name_ar).join("، ") || (row.legacy_search_access ? "صلاحيات بحث سابقة محفوظة — راجع إسناد دور" : "لا يوجد دور فعال — يحتاج إسنادًا")}</td>
            <td><span className={row.is_active ? styles.active : styles.inactive}>{row.is_active ? "فعال" : "غير فعال"}</span></td>
            <td><DirectoryRowActions name={row.username} href={`/users?${query}&account=${row.id}`} onDelete={caps?.delete ? () => setPending(row) : undefined} /></td>
          </tr>)}
          {!list.data.data.length && <tr><td colSpan={5}><div className={styles.status}>لا يوجد مستخدمون مطابقون.</div></td></tr>}
        </DirectoryTable>
        <Pagination meta={list.data.meta} onPage={value => filter("page", String(value))} onPageSize={value => filter("per_page", value)} />
      </>}
    </section>}
    {tab === "roles" && <section className={styles.panel} data-inset="none" aria-label="قائمة الأدوار">
      {canViewUsers && <div className={styles.toolbar}><p>بعد حفظ الدور، اختر المستخدم وافتح تفاصيل حسابه لمراجعة الإسناد ونطاقه في حفظ واحد.</p><button type="button" className={styles.secondary} onClick={() => setTab("users")}>اختيار مستخدم وإسناد دور</button></div>}
      {roles.error && <div className={styles.status}><p role="alert">{roles.error}</p><button className={styles.secondary} onClick={roles.retry}>إعادة المحاولة</button></div>}
      {!roles.data && !roles.error && <p className={styles.status} role="status">جارٍ تحميل الأدوار…</p>}
      {roles.data && <>
        <div className={styles.resultSummary}><strong>{roles.data.data.length} دور</strong></div>
        <DirectoryTable label="جدول الأدوار" headers={["الدور", "الرمز", "الصلاحيات", "الإجراءات"]}>
          {roles.data.data.map(row => <tr key={row.id}>
            <td>{row.name_ar}</td>
            <td><bdi>{row.code}</bdi></td>
            <td><span className={styles.permissionMeta}>{row.permissions.length} صلاحية</span></td>
            <td><DirectoryRowActions name={row.name_ar} href={`/users?${query}&role=${row.id}`} onEdit={caps?.roles_update && row.manageable ? () => setEditingRole(row) : undefined} editTitle="تعديل الصلاحيات" /></td>
          </tr>)}
          {!roles.data.data.length && <tr><td colSpan={4}><div className={styles.status}>لا توجد أدوار.</div></td></tr>}
        </DirectoryTable>
      </>}
    </section>}
    {creating && options.data && <UserEditor facilityId={facilityId} roles={options.data.roles} groups={options.data.permission_groups} templates={options.data.task_templates??[]} canCreateRole={!!caps?.roles_create} onClose={() => setCreating(false)} onSaved={member => { setNotice(member.pending_global_permissions?.length ? "أُنشئ الحساب وإسناده المحلي. العمليات العالمية المختارة تحتاج إسنادًا مستقلًا من مسؤول النظام؛ إضافة بطاقة المريض والبحث المحدود داخل المشفى يعملان بصلاحيات البطاقة المحلية." : "أُنشئ الحساب وأُسند الدور بنجاح."); setCreating(false); setRevision(value => value + 1); }} />}
    {creatingRole && options.data && <RoleEditor facilityId={facilityId} groups={options.data.permission_groups} templates={options.data.task_templates??[]} onClose={() => setCreatingRole(false)} onSaved={() => { setCreatingRole(false); setRevision(value => value + 1); }} />}
    {editingRole && options.data && <RoleEditor facilityId={facilityId} groups={options.data.permission_groups} templates={options.data.task_templates??[]} role={editingRole} onClose={() => setEditingRole(null)} onSaved={() => { setEditingRole(null); setRevision(value => value + 1); window.dispatchEvent(new Event("hospital-access-refresh")); }} />}
    {searchParams.get("role") && roles.data?.data.find(r => String(r.id) === searchParams.get("role")) && <RoleDetails role={roles.data.data.find(r => String(r.id) === searchParams.get("role"))!} onClose={() => router.replace(`/users?${query}`, { scroll: false })} onEdit={role => { router.replace(`/users?${query}`, { scroll: false }); setEditingRole(role); }} />}
    {canViewUsers && /^[1-9]\d*$/.test(searchParams.get("account") ?? "") && <AccountAccessEditor key={searchParams.get("account")} id={Number(searchParams.get("account"))} facility={facilityId} onClose={() => router.replace(`/users?${query}`, { scroll: false })} onSaved={() => { router.replace(`/users?${query}`, { scroll: false }); setRevision(value => value + 1); }} />}
    {pending && <ConfirmUserDelete member={pending} facilityId={facilityId} onClose={() => setPending(null)} onDeleted={() => { setPending(null); setRevision(value => value + 1); }} />}
  </>;
}

function UserEditor({ facilityId, roles, groups, templates, canCreateRole, onClose, onSaved }: { facilityId: number; roles: Role[]; groups: Group[]; templates: TaskTemplate[]; canCreateRole: boolean; onClose: () => void; onSaved: (member: Member) => void }) {
  const [fields, setFields] = useState({ username: "", name: "", email: "", password: "", role_id: roles[0] ? String(roles[0].id) : "" });
  const [choices, setChoices] = useState(roles);
  const [creatingRole, setCreatingRole] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AuthError | null>(null);
  const pending = useRef(false);
  const fieldError = (key: string) => error?.fields[key] ? <small id={`user-error-${key}`} className={styles.fieldError}>{error.fields[key]}</small> : null;
  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (pending.current) return;
    pending.current = true; setBusy(true); setError(null);
    try {
      const member = await apiRequest<Member>("users", { method: "POST", body: JSON.stringify({
        facility_id: facilityId, username: fields.username, name: fields.name, email: fields.email || null,
        password: fields.password, role_id: Number(fields.role_id),
      }) });
      onSaved(member);
    } catch (reason) {
      setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. حاول مجددًا."));
    } finally { pending.current = false; setBusy(false); }
  }
  return <Modal title="إضافة مستخدم" onClose={onClose} busy={busy || creatingRole}>
    <form onSubmit={save} className={styles.form}>
      <p className={styles.hint}>يُنشأ الحساب ويُربط بالمنشأة الحالية بدور واحد. يمكن إنشاء دور جديد وتحديد صلاحياته قبل الحفظ. كلمة المرور لا تُعرض لاحقًا.</p>
      {error && <p role="alert" className={styles.error}>{error.message}</p>}
      <fieldset disabled={busy || creatingRole} className={styles.fields}>
        <label>اسم المستخدم *<input autoFocus required maxLength={60} dir="auto" value={fields.username} onChange={e => setFields({ ...fields, username: e.target.value })} aria-invalid={!!error?.fields.username} aria-describedby={error?.fields.username ? "user-error-username" : undefined} />{fieldError("username")}</label>
        <label>الاسم *<input required maxLength={200} value={fields.name} onChange={e => setFields({ ...fields, name: e.target.value })} aria-invalid={!!error?.fields.name} aria-describedby={error?.fields.name ? "user-error-name" : undefined} />{fieldError("name")}</label>
        <label>البريد الإلكتروني<input type="email" maxLength={190} value={fields.email} onChange={e => setFields({ ...fields, email: e.target.value })} aria-invalid={!!error?.fields.email} aria-describedby={error?.fields.email ? "user-error-email" : undefined} />{fieldError("email")}</label>
        <label>كلمة المرور *<input type="password" required minLength={8} maxLength={100} autoComplete="new-password" value={fields.password} onChange={e => setFields({ ...fields, password: e.target.value })} aria-invalid={!!error?.fields.password} aria-describedby={error?.fields.password ? "user-error-password" : undefined} />{fieldError("password")}</label>
        {!choices.length && <p className={styles.hint}>لا يوجد دور فعال يمكنك إسناده ضمن صلاحياتك. تواصل مع مسؤول النظام.</p>}
        <label className={styles.full}>الدور *<select required value={fields.role_id} onChange={e => setFields({ ...fields, role_id: e.target.value })}>{choices.map(role => <option key={role.id} value={role.id}>{role.name_ar}</option>)}</select>{fieldError("role_id")}
          {canCreateRole && <button type="button" className={styles.textButton} onClick={() => setCreatingRole(true)}>إنشاء دور جديد وتحديد صلاحياته</button>}
        </label>
      </fieldset>
      <div className={styles.modalActions}><button className={styles.primary} type="submit" disabled={busy || creatingRole || !choices.length}>{busy ? "جارٍ الحفظ…" : "حفظ المستخدم"}</button><button type="button" className={styles.secondary} disabled={busy || creatingRole} onClick={onClose}>إلغاء</button></div>
    </form>
    {creatingRole && <RoleEditor facilityId={facilityId} groups={groups} templates={templates} nested onClose={() => setCreatingRole(false)} onSaved={role => { setChoices(current => [...current, role]); setFields(current => ({ ...current, role_id: String(role.id) })); setCreatingRole(false); }} />}
  </Modal>;
}

function RoleEditor({ facilityId, groups, templates, role: initialRole, nested = false, onClose, onSaved }: { facilityId: number; groups: Group[]; templates: TaskTemplate[]; role?: ManagedRole; nested?: boolean; onClose: () => void; onSaved: (role: ManagedRole) => void }) {
  const [role, setRole] = useState(initialRole);
  const [name, setName] = useState(role?.name_ar ?? "");
  const [reason, setReason] = useState("");
  const [review, setReview] = useState(false);
  const [selected, setSelected] = useState<number[]>(role?.permissions.map(item => item.id) ?? []);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AuthError | null>(null);
  const pending = useRef(false);
  const controller = useRef<AbortController | null>(null);
  const form = useRef<HTMLFormElement>(null);
  const [latest, setLatest] = useState<ManagedRole | null>(null);
  const [conflict, setConflict] = useState(false);
  const [previous, setPrevious] = useState("");
  useEffect(() => () => controller.current?.abort(), []);
  const missing = missingRequirements(groups, selected, !!role?.protected);
  const blocked = conflict ? "تغير الدور؛ اجلب أحدث نسخة وراجع الفرق قبل الحفظ." : !name.trim() ? "أدخل اسم الدور." : !selected.length ? "اختر صلاحية واحدة على الأقل." : missing.length ? "أكمل المتطلبات الناقصة الموضحة أعلاه قبل الحفظ." : role && reason.trim().length < 3 ? "أدخل سبب تعديل الصلاحيات (ثلاثة محارف على الأقل)." : "";
  async function reload() {
    if (!role || pending.current) return;
    pending.current = true; setBusy(true);
    const request = new AbortController(); controller.current = request;
    try { const current = await apiRequest<ManagedRole>(`users/roles/${role.id}?facility_id=${facilityId}`, { signal: request.signal }); if (!request.signal.aborted) setLatest(current); }
    catch (e) { if (!request.signal.aborted) setError(e instanceof AuthError ? e : new AuthError(0, "FAILED", "تعذّر جلب الدور؛ مسودتك محفوظة، حاول مجددًا.")); }
    finally { pending.current = false; if (!request.signal.aborted) setBusy(false); }
  }
  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (pending.current || blocked) return;
    if (!review) { setReview(true); return; }
    pending.current = true; setBusy(true); setError(null);
    const request = new AbortController(); controller.current = request;
    try {
      const saved = await apiRequest<ManagedRole>(role ? `users/roles/${role.id}${role.protected ? "/protected-permissions" : ""}` : "users/roles", {
        method: role ? "PUT" : "POST",
        signal: request.signal,
        body: JSON.stringify({ facility_id: facilityId, name_ar: name, permission_ids: selected, ...(role ? { lock_version: role.lock_version, reason } : {}) }),
      });
      if (!request.signal.aborted) onSaved(saved);
    } catch (reason) {
      if (!request.signal.aborted) { setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر حفظ الدور. حاول مجددًا.")); if (reason instanceof AuthError && reason.status === 409) { setConflict(true); setLatest(null); } }
    } finally { pending.current = false; if (!request.signal.aborted) setBusy(false); }
  }
  return <Modal title={role ? `تعديل ${role.name_ar}` : "إضافة دور"} onClose={onClose} busy={busy} size="wide">
    <form ref={form} onSubmit={save} className={styles.form}>
      <p className={styles.hint}>{nested ? "بعد حفظ الدور سيُختار تلقائيًا للمستخدم الجديد." : role?.protected ? "هذه الاختيارات هي سياسة المدير الفعلية. إدارة التفويض مستقلة عن تنفيذ العمل الطبي؛ راجع أثر السحب قبل الحفظ." : "اختر المهام المتاحة للتفويض. لا يتغير وصول الحساب عالميًا إلا بخطوة إسناد مستقلة."}</p>
      {error && <p role="alert" className={styles.error}>{error.message}</p>}
      {previous && <p className={styles.hint}>مسودتك السابقة للمقارنة: {previous}. الاختيارات أدناه هي النسخة الحالية؛ اختر التغييرات مجددًا.</p>}
      {conflict && <section className={styles.conflictReview} aria-label="مراجعة تعارض الدور"><p>لم يُحفظ التغيير. تبقى مسودتك حتى تراجع النسخة الجديدة.</p>{latest ? <><p>الصلاحيات الحالية: {latest.permissions.map(p => p.name_ar).join("، ")}</p><button type="button" className={styles.secondary} disabled={busy} onClick={() => { setPrevious(`${name}: ${groups.flatMap(g => g.permissions).filter(p => selected.includes(p.id)).map(p => p.name_ar).join("، ")}`); setRole(latest); setName(latest.name_ar); setSelected(latest.permissions.map(p => p.id)); setLatest(null); setConflict(false); setReview(false); setError(null); }}>بدء مراجعة الدور الحالي</button></> : <button type="button" className={styles.secondary} disabled={busy} onClick={() => void reload()}>جلب أحدث نسخة من الدور</button>}</section>}
      <fieldset disabled={busy || review} className={styles.fields}>
        <label className={styles.full}>اسم الدور *<input name="name_ar" autoFocus disabled={role?.protected} required maxLength={200} value={name} onChange={e => setName(e.target.value)} aria-invalid={!!error?.fields.name_ar || !name.trim()} />{error?.fields.name_ar && <small className={styles.fieldError}>{error.fields.name_ar}</small>}</label>
        <TaskPermissionPicker key={role?.lock_version ?? "new"} groups={groups} templates={templates} selected={selected} onChange={setSelected} lockedCodes={role?.locked_permissions} exact={role?.protected} />
        {role && <label className={styles.full}>سبب تعديل الصلاحيات *<textarea name="reason" required minLength={3} maxLength={255} value={reason} onChange={e => setReason(e.target.value)} aria-invalid={reason.trim().length < 3} /></label>}
      </fieldset>
      {review && <section className={styles.panel} aria-label="فرق صلاحيات الدور"><h3>معاينة التغيير</h3><p>إضافة: {groups.flatMap(g => g.permissions).filter(p => selected.includes(p.id) && !role?.permissions.some(old => old.id === p.id)).map(p => p.name_ar).join("، ") || "لا شيء"}</p><p>سحب: {role?.permissions.filter(p => !selected.includes(p.id)).map(p => p.name_ar).join("، ") || "لا شيء"}</p><p>الصلاحيات العالمية: {groups.flatMap(g => g.permissions).filter(p => selected.includes(p.id) && p.scope === "global").map(p => p.name_ar).join("، ") || "لا شيء"}. تؤثر في الدليل المشترك عبر المنشآت؛ ستراجع تفعيلها للحساب عند إسناد الدور من هنا. التعديل يؤثر فورًا في حسابات الدور المسند عالميًا بالفعل.</p><button type="button" className={styles.secondary} disabled={busy} onClick={() => setReview(false)}>العودة للاختيارات</button></section>}
      <div className={styles.modalActions}>
        {blocked && <div className={styles.hint} role="status" style={{ flexBasis: "100%" }}><p>{blocked}</p>{!review && (!name.trim() || (role && reason.trim().length < 3)) && <button type="button" className={styles.textButton} onClick={() => { const field = form.current?.querySelector<HTMLElement>(!name.trim() ? '[name="name_ar"]' : '[name="reason"]'); field?.focus(); field?.scrollIntoView({ block: "center" }); }}>الانتقال إلى الحقل الناقص</button>}</div>}
        <button className={styles.primary} type="submit" disabled={busy || !!blocked}>{busy ? "جارٍ الحفظ…" : review ? "تأكيد حفظ الدور" : role ? "معاينة وحفظ الدور" : "إنشاء الدور"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button>
      </div>
    </form>
  </Modal>;
}

function ConfirmUserDelete({ member, facilityId, onClose, onDeleted }: { member: Member; facilityId: number; onClose: () => void; onDeleted: () => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const pending = useRef(false);
  async function confirm() {
    if (pending.current) return;
    pending.current = true; setBusy(true); setError("");
    try {
      await apiRequest(`users/${member.id}?facility_id=${facilityId}`, { method: "DELETE" });
      onDeleted();
    } catch (reason) {
      setError(reason instanceof AuthError ? reason.message : "تعذّر حذف المستخدم. حاول مجددًا.");
    } finally { pending.current = false; setBusy(false); }
  }
  return <Modal title={`حذف ${member.username}`} onClose={onClose} busy={busy} size="compact">
    <p>سيُفك ارتباط هذا المستخدم بالمنشأة. إن كانت هذه عضويته الأخيرة يُعطّل الحساب.</p>
    {error && <p role="alert" className={styles.error}>{error}</p>}
    <div className={styles.modalActions}><button className={styles.primary} disabled={busy} onClick={() => void confirm()}>{busy ? "جارٍ الحذف…" : "تأكيد الحذف"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
  </Modal>;
}

function RoleDetails({ role, onClose, onEdit }: { role: ManagedRole; onClose: () => void; onEdit: (role: ManagedRole) => void }) {
  return <Modal title={`تفاصيل ${role.name_ar}`} onClose={onClose} size="wide"><p>{role.permissions.length} صلاحية محفوظة. {role.protected && "سياسة صريحة؛ لا تُضاف الصلاحيات الجديدة تلقائيًا ولا تعيد الأدوار الأخرى صلاحية ألغيتها هنا."}</p><ul>{role.permissions.map(p => <li key={p.id}><strong>{p.name_ar}</strong> — {p.scope === "global" ? "عالمي" : "داخل المشفى"}{role.locked_permissions?.includes(p.code) && " · محمية لمنع إغلاق إدارة الوصول"}</li>)}</ul><div className={styles.modalActions}>{role.manageable && <button className={styles.primary} onClick={() => onEdit(role)}>تعديل الصلاحيات</button>}<button className={styles.secondary} onClick={onClose}>إغلاق</button></div></Modal>;
}
