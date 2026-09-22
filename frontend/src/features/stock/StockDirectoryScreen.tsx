"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useRef, useState } from "react";
import { LuPlus, LuSearch, LuHospital } from "react-icons/lu";
import { apiRequest, AuthError } from "@/features/auth/api";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { DirectoryRowActions, DirectoryTable } from "../directory/DirectoryPrimitives";
import { directoryFacility } from "../directory/facilityContext";
import Modal from "../clinics/Modal";
import { type Capabilities, type DirectoryKind, type DirectoryRow, type Page, directoryName, statusName, useStockRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function StockDirectoryScreen({ kind }: { kind: DirectoryKind }) {
  const { access } = useIdentity();
  const params = useSearchParams();
  const { entry, facilityId } = directoryFacility(access, "stock.view", params.get("facility_id"));
  const title = directoryName(kind);
  if (!entry) return <section className={styles.status}><h2>{title} غير متاحة</h2><p role="alert">تعذّر تحديد مشفى متاح لك في المخزون.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <Workspace key={`${facilityId}:${kind}`} kind={kind} facilityId={facilityId!} name={entry.facility.name_ar} />;
}

function Workspace({ kind, facilityId, name }: { kind: DirectoryKind; facilityId: number; name: string }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const [revision, setRevision] = useState(0);
  const [editor, setEditor] = useState<DirectoryRow | "new" | null>(null);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  for (const key of ["search", "status", "page"]) { const value = params.get(key); if (value) query.set(key, value); }
  const list = useStockRequest<Page<DirectoryRow>>(`stock/${kind}?${query}`, true, true, revision);
  const caps = list.data?.capabilities as Capabilities | undefined;
  const ready = !!list.data && !list.loading && !list.error;
  const filter = (key: string, value: string) => {
    const next = new URLSearchParams(query);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  };
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>سياق المنشأة</span><strong>{name}</strong></div>
    <header className={styles.header}><h1>{directoryName(kind)}</h1>
      <nav className={styles.actions}><Link className={styles.secondary} href={`/stock/receipts?facility_id=${facilityId}`}>أذونات الاستلام</Link>
        <Link className={styles.secondary} href={`/${kind === "stores" ? "stock/suppliers" : "stock/stores"}?facility_id=${facilityId}`}>{kind === "stores" ? "الموردون" : "المستودعات"}</Link>
        {caps?.manage && <button className={styles.primary} onClick={() => setEditor("new")}><LuPlus aria-hidden="true" />إضافة</button>}</nav>
    </header>
    <label className={styles.search}><LuSearch aria-hidden="true" /><input value={params.get("search") ?? ""} onChange={event => filter("search", event.target.value)} placeholder="بحث" aria-label="بحث" /></label>
    {list.error && <p role="alert">{list.error}</p>}
    <DirectoryTable label={directoryName(kind)} busy={!ready} headers={["الكود", "الاسم", "الحالة", "إجراءات"]}>
      {(list.data?.data ?? []).map(row => <tr key={row.id}><td><Link href={`/stock/${kind}?facility_id=${facilityId}`}>{row.code}</Link></td><td>{row.name_ar}</td><td>{statusName(row)}</td>
        <td><DirectoryRowActions name={row.name_ar} href={`/stock/${kind}?facility_id=${facilityId}`} onEdit={caps?.manage && !row.archived_at ? () => setEditor(row) : undefined} /></td></tr>)}
    </DirectoryTable>
    {editor && <DirectoryEditor kind={kind} facilityId={facilityId} row={editor === "new" ? undefined : editor} onClose={() => setEditor(null)} onSaved={() => { setEditor(null); setRevision(v => v + 1); }} />}
  </div>;
}

function DirectoryEditor({ kind, facilityId, row, onClose, onSaved }: { kind: DirectoryKind; facilityId: number; row?: DirectoryRow; onClose: () => void; onSaved: () => void }) {
  const [code, setCode] = useState(row?.code ?? "");
  const [nameAr, setNameAr] = useState(row?.name_ar ?? "");
  const [extra, setExtra] = useState(kind === "stores" ? (row?.location ?? "") : (row?.contact_person ?? ""));
  const [active, setActive] = useState(row?.is_active ?? true);
  const [error, setError] = useState<AuthError | null>(null);
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  async function save(event: React.FormEvent) {
    event.preventDefault(); if (pending.current) return;
    pending.current = true; setBusy(true); setError(null);
    const body: Record<string, unknown> = { facility_id: facilityId, code, name_ar: nameAr, is_active: active };
    if (kind === "stores") body.location = extra || null; else body.contact_person = extra || null;
    if (row) body.lock_version = row.lock_version;
    try {
      await apiRequest(`stock/${kind}${row ? `/${row.id}` : ""}`, { method: row ? "PUT" : "POST", body: JSON.stringify(body) });
      onSaved();
    } catch (reason) { setError(reason instanceof AuthError ? reason : null); }
    finally { pending.current = false; setBusy(false); }
  }
  return <Modal title={row ? `تعديل ${row.name_ar}` : `إضافة إلى ${directoryName(kind)}`} onClose={onClose}>
    <form className={styles.form} onSubmit={save}>
      {error && <p role="alert">{error.message}</p>}
      <label>الكود<input value={code} onChange={e => setCode(e.target.value)} required maxLength={50} /></label>
      <label>الاسم<input value={nameAr} onChange={e => setNameAr(e.target.value)} required maxLength={200} /></label>
      <label>{kind === "stores" ? "الموقع" : "جهة الاتصال"}<input value={extra} onChange={e => setExtra(e.target.value)} /></label>
      <label>الحالة<select value={active ? "1" : "0"} onChange={e => setActive(e.target.value === "1")}><option value="1">فعال</option><option value="0">غير فعال</option></select></label>
      <div className={styles.actions}><button type="button" className={styles.secondary} onClick={onClose}>إلغاء</button><button className={styles.primary} disabled={busy}>{row ? "حفظ" : "إضافة"}</button></div>
    </form>
  </Modal>;
}
