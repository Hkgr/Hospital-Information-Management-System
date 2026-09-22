"use client";

import { useRef, useState } from "react";
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

type Role = { id: number; code: string; name_ar: string };
type Member = { id: number; username: string; name: string; email: string | null; is_active: boolean; last_login_at: string | null; roles: Role[] };
type Options = { roles: Role[]; capabilities: { view: boolean; create: boolean; delete: boolean } };

export default function UsersScreen() {
  const { access } = useIdentity();
  const query = useSearchParams();
  const router = useRouter();
  const { allowed, facilityId, entry } = directoryFacility(access, "users.view", query.get("facility_id"));
  if (!entry) return <section className={styles.status}><h2>إدارة المستخدمين غير متاحة</h2><p role="alert">ليس لديك وصول إلى مستخدمي المنشأة المطلوبة.</p></section>;
  return <div className={styles.screen}>
    <div className={styles.context}><LuUsers aria-hidden="true" /><span>المنشأة</span>{allowed.length === 1 ? <strong>{entry.facility.name_ar}</strong> : <select aria-label="المنشأة" value={facilityId} onChange={event => { const next = new URLSearchParams(); next.set("facility_id", event.target.value); router.push(`/users?${next}`); }}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select>}</div>
    <UsersWorkspace key={entry.facility.id} facilityId={entry.facility.id} />
  </div>;
}

function UsersWorkspace({ facilityId }: { facilityId: number }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const pathname = usePathname();
  const [revision, setRevision] = useState(0);
  const [creating, setCreating] = useState(false);
  const [pending, setPending] = useState<Member | null>(null);
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, searchParams.toString(), facilityId);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  const page = searchParams.get("page"); const perPage = searchParams.get("per_page");
  if (page) query.set("page", page);
  if (perPage) query.set("per_page", perPage);
  if (committed) query.set("search", committed);
  const options = useClinicRequest<Options>(`users/options?facility_id=${facilityId}`);
  const list = useClinicRequest<Page<Member>>(`users?${query}`, true, true, revision);
  function filter(key: string, value: string) {
    cancel();
    const next = new URLSearchParams(query);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  }
  return <>
    <header className={styles.heading}><div><p className={styles.eyebrow}>الحسابات</p><h2>إدارة المستخدمين</h2><p>إضافة مستخدمين للمنشأة أو حذفهم منها.</p></div>{options.data?.capabilities.create && <button className={styles.primary} onClick={() => setCreating(true)}><LuPlus aria-hidden="true" />إضافة مستخدم</button>}</header>
    <section className={styles.panel} aria-label="قائمة المستخدمين">
      <div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث في المستخدمين</span><input type="search" placeholder="اسم المستخدم أو الاسم…" value={search} onChange={event => change(event.target.value)} /></label></div>
      {list.error && !list.data && <div className={styles.status}><p role="alert">{list.error}</p><button className={styles.secondary} onClick={list.retry}>إعادة المحاولة</button></div>}
      {!list.data && !list.error && <p className={styles.status} role="status">جارٍ تحميل المستخدمين…</p>}
      {list.data && <>
        <div className={styles.resultSummary}><strong>{list.data.meta.total} مستخدم</strong>{(searching || list.loading) && <span role="status">جارٍ تحديث النتائج…</span>}</div>
        <DirectoryTable label="جدول المستخدمين" busy={searching || list.loading} headers={["اسم المستخدم", "الاسم", "الدور", "الحالة", "الإجراءات"]}>
          {list.data.data.map(row => <tr key={row.id}>
            <td><bdi>{row.username}</bdi></td>
            <td>{row.name}</td>
            <td>{row.roles.map(role => role.name_ar).join("، ") || "بدون دور"}</td>
            <td><span className={row.is_active ? styles.active : styles.inactive}>{row.is_active ? "فعال" : "غير فعال"}</span></td>
            <td><DirectoryRowActions name={row.username} href={`/users?${query}`} onDelete={options.data?.capabilities.delete ? () => setPending(row) : undefined} /></td>
          </tr>)}
          {!list.data.data.length && <tr><td colSpan={5}><div className={styles.status}>لا يوجد مستخدمون مطابقون.</div></td></tr>}
        </DirectoryTable>
        <Pagination meta={list.data.meta} onPage={value => filter("page", String(value))} onPageSize={value => filter("per_page", value)} />
      </>}
    </section>
    {creating && options.data && <UserEditor facilityId={facilityId} roles={options.data.roles} onClose={() => setCreating(false)} onSaved={() => { setCreating(false); setRevision(value => value + 1); }} />}
    {pending && <ConfirmUserDelete member={pending} facilityId={facilityId} onClose={() => setPending(null)} onDeleted={() => { setPending(null); setRevision(value => value + 1); }} />}
  </>;
}

function UserEditor({ facilityId, roles, onClose, onSaved }: { facilityId: number; roles: Role[]; onClose: () => void; onSaved: () => void }) {
  const [fields, setFields] = useState({ username: "", name: "", email: "", password: "", role_id: roles[0] ? String(roles[0].id) : "" });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<AuthError | null>(null);
  const pending = useRef(false);
  const fieldError = (key: string) => error?.fields[key] ? <small id={`user-error-${key}`} className={styles.fieldError}>{error.fields[key]}</small> : null;
  async function save(event: React.FormEvent) {
    event.preventDefault();
    if (pending.current) return;
    pending.current = true; setBusy(true); setError(null);
    try {
      await apiRequest<Member>("users", { method: "POST", body: JSON.stringify({
        facility_id: facilityId, username: fields.username, name: fields.name, email: fields.email || null,
        password: fields.password, role_id: Number(fields.role_id),
      }) });
      onSaved();
    } catch (reason) {
      setError(reason instanceof AuthError ? reason : new AuthError(0, "FAILED", "تعذّر الحفظ. حاول مجددًا."));
    } finally { pending.current = false; setBusy(false); }
  }
  return <Modal title="إضافة مستخدم" onClose={onClose} busy={busy}>
    <form onSubmit={save} className={styles.form}>
      <p className={styles.hint}>يُنشأ الحساب ويُربط بالمنشأة الحالية بدور واحد. كلمة المرور لا تُعرض لاحقًا.</p>
      {error && <p role="alert" className={styles.error}>{error.message}</p>}
      <fieldset disabled={busy} className={styles.fields}>
        <label>اسم المستخدم *<input autoFocus required maxLength={60} dir="auto" value={fields.username} onChange={e => setFields({ ...fields, username: e.target.value })} aria-invalid={!!error?.fields.username} aria-describedby={error?.fields.username ? "user-error-username" : undefined} />{fieldError("username")}</label>
        <label>الاسم *<input required maxLength={200} value={fields.name} onChange={e => setFields({ ...fields, name: e.target.value })} aria-invalid={!!error?.fields.name} aria-describedby={error?.fields.name ? "user-error-name" : undefined} />{fieldError("name")}</label>
        <label>البريد الإلكتروني<input type="email" maxLength={190} value={fields.email} onChange={e => setFields({ ...fields, email: e.target.value })} aria-invalid={!!error?.fields.email} aria-describedby={error?.fields.email ? "user-error-email" : undefined} />{fieldError("email")}</label>
        <label>كلمة المرور *<input type="password" required minLength={8} maxLength={100} autoComplete="new-password" value={fields.password} onChange={e => setFields({ ...fields, password: e.target.value })} aria-invalid={!!error?.fields.password} aria-describedby={error?.fields.password ? "user-error-password" : undefined} />{fieldError("password")}</label>
        <label>الدور *<select required value={fields.role_id} onChange={e => setFields({ ...fields, role_id: e.target.value })}>{roles.map(role => <option key={role.id} value={role.id}>{role.name_ar}</option>)}</select>{fieldError("role_id")}</label>
      </fieldset>
      <div className={styles.modalActions}><button className={styles.primary} type="submit" disabled={busy || !roles.length}>{busy ? "جارٍ الحفظ…" : "حفظ المستخدم"}</button><button type="button" className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button></div>
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
