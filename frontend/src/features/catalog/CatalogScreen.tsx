"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuPlus, LuSearch, LuDownload, LuFileText, LuHospital } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { AuthError } from "@/features/auth/api";
import { ColumnMenu, LongText, Pagination } from "../directory/Controls";
import useClinicSearch from "../clinics/useClinicSearch";
import CatalogEditor from "./CatalogEditor";
import { CatalogLifecycle, CatalogRelated, actionNames, type Action } from "./CatalogDialogs";
import { type Capabilities, type Column, type Item, type Kind, type Page, columns, columnKeys, kindName, stateName, downloadReport, useCatalogRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function CatalogScreen({ kind, itemId }: { kind?: string; itemId?: string }) {
  const { access, user } = useIdentity(); const params = useSearchParams(); const router = useRouter();
  const cancelSearchRef = useRef<(() => void) | null>(null);
  const allowed = access.filter(entry => entry.permissions.includes("catalog.view"));
  const facilityId = params.has("facility_id") ? Number(params.get("facility_id")) : allowed[0]?.facility.id;
  const entry = allowed.find(entry => entry.facility.id === facilityId);
  if (!entry || (itemId && (!/^[1-9]\d*$/.test(itemId) || !["service", "procedure"].includes(kind ?? "")))) return <section className={styles.status}><h2>الخدمات والإجراءات غير متاحة</h2><p role="alert">ليس لديك وصول إلى الدليل في المنشأة المطلوبة، أو الرابط غير صالح.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><label>المنشأة<select aria-label="المنشأة" value={facilityId} onChange={event => { cancelSearchRef.current?.(); router.push(`/services-procedures?facility_id=${event.target.value}`); }}>{allowed.map(entry => <option key={entry.facility.id} value={entry.facility.id}>{entry.facility.name_ar}</option>)}</select></label></div>
    <Workspace key={`${facilityId}:${kind ?? ""}:${itemId ?? ""}:${user.id}`} facilityId={facilityId!} kind={kind as Kind | undefined} itemId={itemId} cancelSearchRef={cancelSearchRef} />
  </div>;
}

function Workspace({ facilityId, kind, itemId, cancelSearchRef }: { facilityId: number; kind?: Kind; itemId?: string; cancelSearchRef: React.RefObject<(() => void) | null> }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facilityId);
  useEffect(() => { cancelSearchRef.current = cancel; return () => { cancelSearchRef.current = null; }; }, [cancel, cancelSearchRef]);
  const [revision, setRevision] = useState(0); const [visible, setVisible] = useState<Column[]>(columnKeys);
  const [modal, setModal] = useState<{ mode: "edit" | "patients" | "history" | Action; kind: Kind; item?: Item } | null>(null);
  const [exporting, setExporting] = useState(false); const [exportError, setExportError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  for (const key of ["kind", "status", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) query.set(key, value); }
  if (committed) query.set("search", committed);
  const encoded = query.toString(); const detail = !!kind && !!itemId;
  const list = useCatalogRequest<Page<Item> & { capabilities: Capabilities }>(detail ? null : `service-catalog?${encoded}`, true, true, revision);
  const record = useCatalogRequest<{ data: Item; capabilities: Capabilities }>(detail ? `service-catalog/${kind}/${itemId}?facility_id=${facilityId}` : null, true, false, revision);
  const request = detail ? record : list; const caps = request.data?.capabilities;
  const ready = !!request.data && !request.loading && !request.error && (detail || !searching);
  const refreshed = () => setRevision(value => value + 1);
  const saved = () => { setModal(null); refreshed(); };
  function filter(key: string, value: string) {
    cancel(); const next = new URLSearchParams(encoded);
    if (search) next.set("search", search); else next.delete("search");
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  }
  async function exportFile(format: "xlsx" | "pdf") {
    if (!ready || !caps?.export || pending.current || !visible.length) return;
    pending.current = true; setExporting(true); setExportError(""); const active = new AbortController(); controller.current = active;
    const q = new URLSearchParams(encoded); visible.forEach(column => q.append("columns[]", column));
    try { await downloadReport(detail ? `service-catalog/${kind}/${itemId}/report?facility_id=${facilityId}` : `service-catalog/export/${format}?${q}`, active.signal); }
    catch (reason) { if (!active.signal.aborted) setExportError(reason instanceof AuthError ? reason.message : "تعذّر تنزيل التقرير. حاول مجددًا."); }
    finally { if (!active.signal.aborted) { pending.current = false; setExporting(false); } }
  }
  const exports = caps?.export && <div className={styles.actions}>{!detail && <button className={styles.secondary} disabled={!ready || exporting} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />Excel</button>}<button className={styles.secondary} disabled={!ready || exporting} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />{detail ? "تقرير التفاصيل PDF" : "PDF"}</button></div>;
  const open = (mode: "edit" | "patients" | "history" | Action, item: Item) => setModal({ mode, kind: item.kind, item });
  function actions(item: Item) {
    const available: Action[] = item.archived_at ? ["restore"] : item.is_active ? ["deactivate"] : ["reactivate"];
    return <div className={styles.rowActions}><details><summary>إجراءات {item.name_ar}</summary><div className={styles.actions}>
      {!detail && <Link href={`/services-procedures/${item.kind}/${item.id}?${encoded}`}>التفاصيل</Link>}
      {caps?.update && !item.archived_at && <button className={styles.textButton} disabled={!ready} onClick={() => open("edit", item)}>تعديل</button>}
      {caps?.update && available.map(action => <button className={styles.textButton} key={action} disabled={!ready} onClick={() => open(action, item)}>{actionNames[action]}</button>)}
      {caps?.delete && !item.archived_at && <button className={styles.textButton} disabled={!ready} onClick={() => open("archive", item)}>أرشفة</button>}
      {caps?.delete && <button className={`${styles.textButton} ${styles.dangerText}`} disabled={!ready} onClick={() => open("delete", item)}>حذف</button>}
    </div></details></div>;
  }
  function count(item: Item) { return caps?.beneficiaries ? <button className={styles.countButton} disabled={!ready} aria-label={`المستفيدون من ${item.name_ar}: ${item.patient_count}`} onClick={() => open("patients", item)}>{item.patient_count}</button> : <span>{item.patient_count}</span>; }
  return <>
    {detail && <Link className={styles.back} href={`/services-procedures?${encoded}`}>العودة إلى الخدمات والإجراءات</Link>}
    <div className={styles.heading}><div><p className={styles.eyebrow}>الدليل الطبي</p><h2>{detail ? record.data?.data.name_ar ?? "تفاصيل التعريف" : "الخدمات والإجراءات"}</h2><p>تعريفات الدليل المشتركة، وإحصاءات المستفيدين ضمن المنشأة.</p></div>{!detail && caps?.create && <div className={styles.actions}>{(["service", "procedure"] as Kind[]).map(kind => <button key={kind} className={styles.primary} disabled={!ready} onClick={() => setModal({ mode: "edit", kind })}><LuPlus aria-hidden="true" />إضافة {kindName(kind)}</button>)}</div>}</div>
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {request.loading && <p role="status">{request.data ? "جارٍ تحديث النتائج؛ تظهر النتائج السابقة مؤقتًا والتصدير معطّل." : "جارٍ تحميل الخدمات والإجراءات…"}</p>}
    {request.error && <p role="alert" className={styles.error}>{request.error}{request.data && " المعروض نتائج سابقة؛ يتطلب التصدير نجاح إعادة التحميل."} <button onClick={request.retry}>إعادة التحميل</button></p>}
    {!detail && <section className={styles.panel} aria-label="قائمة الخدمات والإجراءات"><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث بالاسم أو الكود</span><input type="search" value={search} onChange={e => change(e.target.value)} placeholder="ابحث عن خدمة أو إجراء…" /></label>{exports}</div>
      <div className={styles.filters}><label>النوع<select value={query.get("kind") ?? ""} onChange={e => filter("kind", e.target.value)}><option value="">الكل</option><option value="service">الخدمات</option><option value="procedure">الإجراءات</option></select></label><label>الحالة<select value={query.get("status") ?? ""} onChange={e => filter("status", e.target.value)}><option value="">الفعال والمعطل</option><option value="active">فعال</option><option value="inactive">غير فعال</option><option value="archived">مؤرشف</option></select></label><label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => filter("sort", e.target.value)}>{["code", "name_ar", "kind", "patient_count", "is_active"].map(key => <option key={key} value={key}>{columns[key as Column]}</option>)}</select></label><label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => filter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label><ColumnMenu labels={columns} visible={visible} onChange={setVisible} /></div>
      <div className={styles.resultSummary}><strong>{list.data?.meta.total ?? "—"} عنصر</strong><span>التصدير يشمل جميع النتائج المطابقة والأعمدة المختارة، حتى 1000 عنصر.</span></div>
      <div className={styles.tableScroll} aria-busy={list.loading}><table><thead><tr>{visible.map(key => <th key={key}>{columns[key]}</th>)}<th>الإجراءات المتاحة</th></tr></thead><tbody>{list.data?.data.map((item, index) => <tr key={`${item.kind}:${item.id}`}>{visible.map(key => <td key={key}>{key === "number" ? (list.data!.meta.page - 1) * list.data!.meta.per_page + index + 1 : key === "code" ? <Link className={styles.code} href={`/services-procedures/${item.kind}/${item.id}?${encoded}`}><bdi>{item.code}</bdi></Link> : key === "kind" ? <span className={styles.badge}>{kindName(item.kind)}</span> : key === "patient_count" ? count(item) : key === "is_active" ? <span className={item.is_active && !item.archived_at ? styles.active : styles.inactive}>{stateName(item)}</span> : key === "description" ? <LongText text={item.description} /> : item.name_ar}</td>)}<td>{actions(item)}</td></tr>)}</tbody></table></div>
      {list.data && !list.data.data.length && <p className={styles.status}>لا توجد نتائج مطابقة. جرّب تغيير البحث أو الفلاتر.</p>}{list.data && <Pagination meta={list.data.meta} onPage={page => filter("page", String(page))} onPageSize={value => filter("per_page", value)} />}
    </section>}
    {detail && record.data && <section className={styles.detailPanel}><div className={styles.actions}>{actions(record.data.data)}{exports}<button className={styles.secondary} onClick={refreshed}>إعادة تحميل البيانات</button></div><dl className={styles.facts}><div><dt>الكود</dt><dd><bdi>{record.data.data.code}</bdi></dd></div><div><dt>النوع</dt><dd>{kindName(record.data.data.kind)}</dd></div><div><dt>الحالة</dt><dd>{stateName(record.data.data)}</dd></div><div><dt>عدد المستفيدين</dt><dd>{count(record.data.data)}</dd></div></dl><h3>الوصف</h3><p className={styles.description}>{record.data.data.description || "لا يوجد وصف."}</p><p className={styles.hint}>{record.data.data.patient_count_definition}</p><h3>الارتباطات</h3><p className={styles.hint}>لا توجد ارتباطات تعريف مباشرة بالعيادات؛ تقديم الخدمة أو الإجراء يُسجّل كحدث علاجي مستقل، وتحفظ الأرشفة أحداثه السابقة.</p>{caps?.audit && <button className={styles.secondary} onClick={() => open("history", record.data!.data)}>سجل التغييرات</button>}</section>}
    {modal?.mode === "edit" && <CatalogEditor kind={modal.kind} item={modal.item} facilityId={facilityId} onClose={() => setModal(null)} onSaved={saved} onRefresh={refreshed} />}
    {modal?.item && (modal.mode === "patients" || modal.mode === "history") && <CatalogRelated item={modal.item} facilityId={facilityId} history={modal.mode === "history"} onClose={() => setModal(null)} />}
    {modal?.item && ["delete", "archive", "restore", "deactivate", "reactivate"].includes(modal.mode) && <CatalogLifecycle item={modal.item} action={modal.mode as Action} facilityId={facilityId} onClose={() => { setModal(null); refreshed(); }} onSaved={action => { if (detail && action === "delete") router.push(`/services-procedures?${encoded}`); saved(); }} />}
  </>;
}
