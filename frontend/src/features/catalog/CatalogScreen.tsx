"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuPlus, LuSearch, LuDownload, LuFileText, LuHospital, LuSquarePen } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { AuthError } from "@/features/auth/api";
import { ColumnMenu, LongText, Pagination } from "../directory/Controls";
import { DirectoryBack, DirectoryRowActions, DirectoryTable } from "../directory/DirectoryPrimitives";
import { directoryFacility } from "../directory/facilityContext";
import { LifecycleActions } from "../directory/Lifecycle";
import useClinicSearch from "../clinics/useClinicSearch";
import CatalogEditor from "./CatalogEditor";
import { CatalogLifecycle, CatalogRelated, type Action } from "./CatalogDialogs";
import CatalogEvents from "./CatalogEvents";
import { type Capabilities, type Column, type Item, type Kind, type Page, catalogHome, catalogHref, columns, columnKeys, kindName, stateName, downloadReport, useCatalogRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function CatalogScreen({ family = "clinical", kind, itemId }: { family?: "clinical" | "medication"; kind?: string; itemId?: string }) {
  const { access, user } = useIdentity(); const params = useSearchParams();
  const cancelSearchRef = useRef<(() => void) | null>(null);
  const { entry, facilityId } = directoryFacility(access, "catalog.view", params.get("facility_id"));
  const context = useCatalogRequest<{ facility: { id: number; name_ar: string } }>(entry ? `service-catalog/context?facility_id=${entry.facility.id}` : null);
  const resolvedKind = family === "medication" ? "medication" : kind;
  const title = family === "medication" ? "الأدوية" : "الخدمات والإجراءات";
  if (!entry || (itemId && (!/^[1-9]\d*$/.test(itemId) || (family === "medication" ? resolvedKind !== "medication" : !["service", "procedure"].includes(kind ?? ""))))) return <section className={styles.status}><h2>{title} غير متاحة</h2><p role="alert">تعذّر تحديد مشفى متاح لك في {title}. تحقق من الرابط أو راجع مسؤول الصلاحيات.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  if (context.loading) return <p role="status" className={styles.status}>جارٍ تحميل سياق المشفى…</p>;
  if (context.error || context.data?.facility.id !== facilityId) return <section className={styles.status}><h2>{title} غير متاحة</h2><p role="alert">تعذّر التحقق من إتاحة المشفى وصلاحية الوصول إليه. أعد المحاولة، أو راجع مسؤول النظام إذا استمرت المشكلة.</p><button className={styles.secondary} onClick={context.retry}>إعادة المحاولة</button></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>سياق المنشأة</span><strong>{entry.facility.name_ar}</strong><span className={styles.contextCaption}>الدليل مشترك · المؤشرات ضمن المنشأة</span></div>
    <Workspace key={`${facilityId}:${resolvedKind ?? ""}:${itemId ?? ""}:${user.id}`} family={family} facilityId={facilityId!} kind={resolvedKind as Kind | undefined} itemId={itemId} cancelSearchRef={cancelSearchRef} />
  </div>;
}

function Workspace({ family, facilityId, kind, itemId, cancelSearchRef }: { family: "clinical" | "medication"; facilityId: number; kind?: Kind; itemId?: string; cancelSearchRef: React.RefObject<(() => void) | null> }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const medications = family === "medication";
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facilityId);
  useEffect(() => { cancelSearchRef.current = cancel; return () => { cancelSearchRef.current = null; }; }, [cancel, cancelSearchRef]);
  const [revision, setRevision] = useState(0); const [visible, setVisible] = useState<Column[]>(columnKeys);
  const [modal, setModal] = useState<{ mode: "edit" | "patients" | "history" | Action; kind: Kind; item?: Item } | null>(null);
  const [exporting, setExporting] = useState(false); const [exportError, setExportError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  for (const key of ["kind", "status", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) query.set(key, value); }
  if (medications) query.set("kind", "medication");
  if (committed) query.set("search", committed);
  const encoded = query.toString(); const detail = !!kind && !!itemId;
  const list = useCatalogRequest<Page<Item> & { capabilities: Capabilities }>(detail ? null : `service-catalog?${encoded}`, true, true, revision);
  const record = useCatalogRequest<{ data: Item; capabilities: Capabilities }>(detail ? `service-catalog/${kind}/${itemId}?facility_id=${facilityId}` : null, true, false, revision);
  const request = detail ? record : list; const caps = request.data?.capabilities;
  const ready = !!request.data && !request.loading && !request.error && (detail || !searching);
  const refreshed = () => setRevision(value => value + 1);
  const saved = () => { setModal(null); refreshed(); };
  const home = (item?: Item) => catalogHome(item?.kind ?? (medications ? "medication" : "service"), encoded);
  function filter(key: string, value: string) {
    cancel(); const next = new URLSearchParams(encoded);
    if (search) next.set("search", search); else next.delete("search");
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    if (medications) next.set("kind", "medication");
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
  function lifecycle(item: Item) {
    return <div className={styles.actions}><LifecycleActions disabled={!ready} record={item} name={item.name_ar} canUpdate={!!caps?.update} onAction={action => { if (ready) open(action, item); }} />{caps?.delete && !item.archived_at && <button className={styles.textButton} disabled={!ready} aria-label={`أرشفة ${item.name_ar}`} onClick={() => open("archive", item)}>أرشفة</button>}</div>;
  }
  function actions(item: Item) {
    return <DirectoryRowActions name={item.name_ar} href={catalogHref(item, encoded)} disabled={!ready} onEdit={caps?.update && !item.archived_at ? () => open("edit", item) : undefined} onDelete={caps?.delete ? () => open("delete", item) : undefined}>{lifecycle(item)}</DirectoryRowActions>;
  }
  function detailActions(item: Item) {
    return <div className={styles.actions}>{caps?.update && !item.archived_at && <button className={styles.primary} disabled={!ready} onClick={() => open("edit", item)}><LuSquarePen aria-hidden="true" />تعديل {kindName(item.kind)}</button>}{caps?.delete && <button className={styles.secondary} disabled={!ready} onClick={() => open("delete", item)} aria-label={`حذف ${item.name_ar}`}>حذف أو أرشفة</button>}{lifecycle(item)}{exports}</div>;
  }
  function count(item: Item) { return caps?.beneficiaries ? <button className={styles.countButton} disabled={!ready} aria-label={`المستفيدون من ${item.name_ar}: ${item.patient_count}`} onClick={() => open("patients", item)}>{item.patient_count}</button> : <span>{item.patient_count}</span>; }
  const createKinds = (medications ? ["medication"] : ["service", "procedure"]) as Kind[];
  return <>
    {detail && <DirectoryBack href={home(record.data?.data)}>العودة إلى {medications ? "الأدوية" : "الخدمات والإجراءات"}</DirectoryBack>}
    <div className={styles.heading}><div><p className={styles.eyebrow}>{detail && record.data ? <>بطاقة {kindName(record.data.data.kind)} · <bdi>{record.data.data.code}</bdi></> : medications ? "الدليل الطبي / الأدوية" : "الدليل الطبي / الخدمات والإجراءات"}</p><h2>{detail ? record.data?.data.name_ar ?? "تفاصيل التعريف" : medications ? "الأدوية" : "الخدمات والإجراءات"}</h2><p>{detail && record.data ? <>{record.data.data.classification_name_ar || kindName(record.data.data.kind)} · {stateName(record.data.data)}</> : "تعريفات الدليل المشتركة، وإحصاءات المستفيدين ضمن المنشأة."}</p></div>{!detail && caps?.create && <div className={styles.actions}>{createKinds.map(createKind => <button key={createKind} className={styles.primary} disabled={!ready} onClick={() => setModal({ mode: "edit", kind: createKind })}><LuPlus aria-hidden="true" />إضافة {kindName(createKind)}</button>)}</div>}{detail && record.data && detailActions(record.data.data)}</div>
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {request.loading && <p role="status">{request.data ? "جارٍ تحديث النتائج؛ تظهر النتائج السابقة مؤقتًا والتصدير معطّل." : medications ? "جارٍ تحميل الأدوية…" : "جارٍ تحميل الخدمات والإجراءات…"}</p>}
    {request.error && <p role="alert" className={styles.error}>{request.error}{request.data && " المعروض نتائج سابقة؛ يتطلب التصدير نجاح إعادة التحميل."} <button onClick={request.retry}>إعادة التحميل</button></p>}
    {!detail && <section className={styles.panel} aria-label={medications ? "قائمة الأدوية" : "قائمة الخدمات والإجراءات"}><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث بالاسم أو الكود</span><input type="search" value={search} onChange={e => change(e.target.value)} placeholder={medications ? "ابحث عن دواء…" : "ابحث عن خدمة أو إجراء…"} /></label>{exports}</div>
      <div className={styles.filters}>{!medications && <label>النوع<select value={query.get("kind") ?? ""} onChange={e => filter("kind", e.target.value)}><option value="">الكل</option><option value="service">الخدمات</option><option value="procedure">الإجراءات</option></select></label>}<label>الحالة<select value={query.get("status") ?? ""} onChange={e => filter("status", e.target.value)}><option value="">الفعال والمعطل</option><option value="active">فعال</option><option value="inactive">غير فعال</option><option value="archived">مؤرشف</option></select></label><label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => filter("sort", e.target.value)}>{["code", "name_ar", "kind", "patient_count", "is_active"].map(key => <option key={key} value={key}>{columns[key as Column]}</option>)}</select></label><label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => filter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label><ColumnMenu labels={columns} visible={visible} onChange={setVisible} /></div>
      <div className={styles.resultSummary}><strong>{list.data?.meta.total ?? "—"} عنصر</strong><span>التصدير يشمل جميع النتائج المطابقة والأعمدة المختارة، حتى 1000 عنصر.</span></div>
      <DirectoryTable label={medications ? "جدول الأدوية" : "جدول الخدمات والإجراءات"} busy={list.loading || searching} headers={[...visible.map(key => columns[key]), "الإجراءات"]}>{list.data?.data.map((item, index) => <tr key={`${item.kind}:${item.id}`}>{visible.map(key => <td key={key} className={key === "code" || key === "kind" ? styles.identifierCell : undefined}>{key === "number" ? (list.data!.meta.page - 1) * list.data!.meta.per_page + index + 1 : key === "code" ? <Link className={styles.code} href={catalogHref(item, encoded)}><bdi>{item.code}</bdi></Link> : key === "kind" ? <span className={styles.badge}>{kindName(item.kind)}</span> : key === "patient_count" ? count(item) : key === "is_active" ? <span className={item.is_active && !item.archived_at ? styles.active : styles.inactive}>{stateName(item)}</span> : key === "description" ? <LongText text={item.description} /> : <div className={styles.clinicName}><strong>{item.name_ar}</strong>{item.classification_name_ar && <small>{item.classification_name_ar}</small>}</div>}</td>)}<td>{actions(item)}</td></tr>)}</DirectoryTable>
      {list.data && !list.data.data.length && <p className={styles.status}>لا توجد نتائج مطابقة. جرّب تغيير البحث أو الفلاتر.</p>}{list.data && <Pagination meta={list.data.meta} onPage={page => filter("page", String(page))} onPageSize={value => filter("per_page", value)} />}
    </section>}
    {detail && record.data && <><section className={styles.detailPanel}><h3>وصف {kindName(record.data.data.kind)}</h3><p className={styles.description}>{record.data.data.description || "لا يوجد وصف مسجل."}</p><dl className={styles.facts}><div><dt>الكود</dt><dd><bdi>{record.data.data.code}</bdi></dd></div><div><dt>{record.data.data.kind === "service" ? "فئة الخدمة" : record.data.data.kind === "medication" ? "فئة الدواء" : "نوع الإجراء"}</dt><dd>{record.data.data.classification_name_ar || "غير محدد"}</dd></div>{record.data.data.kind === "medication" && <><div><dt>التركيز</dt><dd>{record.data.data.strength || "غير محدد"}</dd></div><div><dt>الشكل الصيدلاني</dt><dd>{record.data.data.dosage_form || "غير محدد"}</dd></div><div><dt>الوحدة الافتراضية</dt><dd>{record.data.data.default_unit || "غير محدد"}</dd></div><div><dt>حد إعادة الطلب</dt><dd>{record.data.data.reorder_level || "لا تنبيه"}</dd></div></>}<div><dt>الحالة</dt><dd><span className={record.data.data.is_active && !record.data.data.archived_at ? styles.active : styles.inactive}>{stateName(record.data.data)}</span></dd></div><div><dt>عدد المرضى الفريدين</dt><dd>{record.data.data.patient_count}</dd></div></dl><p className={styles.hint}>{record.data.data.patient_count_definition}</p></section>
      <section className={styles.detailPanel}><h3>المرضى المستفيدون وتواريخ التقديم</h3>{caps?.beneficiaries ? <CatalogEvents item={record.data.data} facilityId={facilityId} revision={revision} /> : <p className={styles.hint}>لا تملك صلاحية استعراض المستفيدين في هذه المنشأة.</p>}</section></>}
    {modal?.mode === "edit" && <CatalogEditor canCreateCategory={caps?.create} kind={modal.kind} item={modal.item} facilityId={facilityId} onClose={() => setModal(null)} onSaved={saved} onRefresh={refreshed} />}
    {modal?.item && (modal.mode === "patients" || modal.mode === "history") && <CatalogRelated item={modal.item} facilityId={facilityId} history={modal.mode === "history"} onClose={() => setModal(null)} />}
    {modal?.item && ["delete", "archive", "restore", "deactivate", "reactivate"].includes(modal.mode) && <CatalogLifecycle item={modal.item} action={modal.mode as Action} facilityId={facilityId} onClose={() => { setModal(null); refreshed(); }} onSaved={action => { if (detail && action === "delete") router.push(home(modal.item)); saved(); }} />}
  </>;
}
