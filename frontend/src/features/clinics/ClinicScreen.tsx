"use client";

import Link from "next/link";
import dynamic from "next/dynamic";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuArrowRight, LuDownload, LuEye, LuFileText, LuHospital, LuPlus, LuSearch, LuSquarePen, LuTrash2 } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { AuthError } from "@/features/auth/api";
import { columns, columnKeys, downloadReport, useClinicRequest, type Clinic, type Column, type Page, type Specialty } from "./api";
import LifecycleDialog, { LifecycleActions, LinkHistory, type LifecycleAction } from "../directory/Lifecycle";
import RelationFilter from "../directory/RelationFilter";
import ClinicDoctors, { DoctorList } from "./ClinicDoctors";
import useClinicSearch from "./useClinicSearch";
import { ColumnMenu, LongText, Pagination } from "../directory/Controls";
import styles from "./clinics.module.css";

const ClinicEditor = dynamic(() => import("./ClinicEditor"), { loading: () => <p role="status">جارٍ فتح النموذج…</p> });

export default function ClinicScreen({ clinicId }: { clinicId?: string }) {
  const { access } = useIdentity();
  const query = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const cancelSearchRef = useRef<(() => void) | null>(null);
  const allowed = access.filter(entry => entry.permissions.includes("clinics.view"));
  const requested = query.get("facility_id");
  const facilityId = requested ? Number(requested) : allowed[0]?.facility.id;
  const entry = allowed.find(item => item.facility.id === facilityId);
  if (!entry || (clinicId && !/^[1-9]\d*$/.test(clinicId))) return <section className={styles.status}><h2>العيادات غير متاحة</h2><p role="alert">ليس لديك وصول إلى العيادات في المنشأة المطلوبة.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>المنشأة</span>{allowed.length === 1 ? <strong>{entry.facility.name_ar}</strong> : <select aria-label="المنشأة" value={facilityId} onChange={event => { cancelSearchRef.current?.(); const next = new URLSearchParams(); next.set("facility_id", event.target.value); router.push(`/clinics?${next}`); }}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select>}</div>
    <ClinicWorkspace key={`${facilityId}:${clinicId ?? "list"}`} facilityId={entry.facility.id} permissions={entry.permissions} clinicId={clinicId} pathname={pathname} cancelSearchRef={cancelSearchRef} />
  </div>;
}

function ClinicWorkspace({ facilityId, permissions, clinicId, pathname, cancelSearchRef }: { facilityId: number; permissions: string[]; clinicId?: string; pathname: string; cancelSearchRef: React.RefObject<(() => void) | null> }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [modal, setModal] = useState<{ type: "edit" | "doctors" | LifecycleAction; clinic?: Clinic } | null>(null);
  const [revision, setRevision] = useState(0);
  const { search, committed, change: setSearch, cancel, searching } = useClinicSearch(pathname, searchParams.toString(), facilityId);
  useEffect(() => { cancelSearchRef.current = cancel; return () => { cancelSearchRef.current = null; }; }, [cancel, cancelSearchRef]);
  const [visible, setVisible] = useState<Column[]>(columnKeys);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState("");
  const exportController = useRef<AbortController | null>(null);
  const exportPending = useRef(false);
  useEffect(() => () => exportController.current?.abort(), []);
  const query = new URLSearchParams();
  for (const key of ["status", "doctor_id", "specialty_id", "sort", "direction", "page", "per_page"]) {
    const value = searchParams.get(key); if (value) query.set(key, value);
  }
  query.set("facility_id", String(facilityId));
  if (committed) query.set("search", committed);
  const encoded = query.toString();
  // Share the keyed request snapshot with the table instead of accepting a
  // delayed readiness notification from a previous query or save revision.
  const list = useClinicRequest<Page<Clinic>>(clinicId ? null : `clinics?${encoded}`, true, true, revision);
  const exportReady = !searching && (!!clinicId || (!!list.data && !list.loading && !list.error));
  // Only allowlisted same-origin query values survive the return link.
  const returnPath = `/clinics?${encoded}`;
  const updateFilter = (key: string, value: string) => {
    const next = new URLSearchParams(encoded);
    cancel();
    if (search) next.set("search", search); else next.delete("search");
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  };
  const can = (action: string) => permissions.includes(`clinics.${action}`);
  const [notice, setNotice] = useState("");
  const saved = () => { setModal(null); setRevision(value => value + 1); };
  async function exportFile(format: "xlsx" | "pdf") {
    if (exportPending.current || !exportReady || !visible.length || !can("export")) return;
    exportPending.current = true; setExporting(true); setExportError("");
    const controller = new AbortController(); exportController.current = controller;
    const exportQuery = new URLSearchParams(encoded);
    for (const column of visible) exportQuery.append("columns[]", column);
    try { await downloadReport(clinicId ? `clinics/${clinicId}/report?facility_id=${facilityId}` : `clinics/export/${format}?${exportQuery}`, controller.signal); }
    catch (reason) { if (!controller.signal.aborted) setExportError(reason instanceof AuthError ? reason.message : "تعذّر تنزيل التقرير. حاول مجددًا."); }
    finally { if (!controller.signal.aborted) { exportPending.current = false; setExporting(false); } }
  }
  const exports = can("export") && <div className={styles.actions}>
    {!clinicId && <button className={styles.secondary} disabled={exporting || !visible.length || !exportReady} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />Excel</button>}
    <button className={styles.secondary} disabled={exporting || !visible.length || !exportReady} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />{clinicId ? "تصدير تقرير العيادة PDF" : "PDF"}</button>
    {exporting && <span role="status">جارٍ إعداد التقرير…</span>}
  </div>;
  return <>
    {clinicId ? <ClinicDetail key={revision} id={clinicId} facilityId={facilityId} can={can} onAction={(type, clinic) => setModal({ type, clinic })} returnPath={returnPath} exports={exports} /> : <>
      <div className={styles.heading}><div><p className={styles.eyebrow}>الدليل الطبي</p><h2>إدارة العيادات</h2><p>بيانات العيادات، الأطباء المرتبطون، وإحصاءات المرضى.</p></div>{can("create") && <button className={styles.primary} onClick={() => setModal({ type: "edit" })}><LuPlus aria-hidden="true" />إضافة عيادة جديدة</button>}</div>
      <section className={styles.panel} aria-label="قائمة العيادات">
        <div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" /> البحث في العيادات</span><input type="search" placeholder="اسم العيادة أو كودها أو توصيفها أو طبيبها…" value={search} onChange={event => setSearch(event.target.value)} /></label>{exports}</div>
        <div className={styles.filters}>
          <label>الحالة<select value={query.get("status") ?? ""} onChange={e => updateFilter("status", e.target.value)}><option value="">الفعال والمعطل</option><option value="active">فعالة</option><option value="inactive">غير فعالة</option><option value="archived">مؤرشف</option></select></label>
          <SpecialtyFilter facilityId={facilityId} value={query.get("specialty_id") ?? ""} onChange={value => updateFilter("specialty_id", value)} />
          <RelationFilter kind="doctor" facilityId={facilityId} value={query.get("doctor_id") ?? ""} onChange={value => updateFilter("doctor_id", value)} />
          <label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => updateFilter("sort", e.target.value)}><option value="code">كود العيادة</option><option value="name_ar">اسم العيادة</option><option value="doctor_count">عدد الأطباء</option><option value="patient_count">عدد المرضى</option><option value="is_active">الحالة</option></select></label>
          <label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => updateFilter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label>
          <ColumnMenu labels={columns} visible={visible} onChange={setVisible} />
        </div>
        <ClinicTable result={list} query={encoded} searching={searching} visible={visible} can={can} onAction={(type, clinic) => setModal({ type, clinic })} onPage={value => updateFilter("page", String(value))} onPageSize={value => updateFilter("per_page", value)} />
      </section>
      <p className={styles.hint}>عدد المرضى: المرضى المختلفون في الزيارات المكتملة وغير الملغاة المرتبطة مباشرة بالعيادة. لا يزيد العدد بتكرار الزيارة.</p>
    </>}
    {notice && <p role="status">{notice}</p>}
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {modal?.type === "edit" && <ClinicEditor clinic={modal.clinic} facilityId={facilityId} onClose={() => setModal(null)} onSaved={saved} onReloaded={() => setRevision(value => value + 1)} />}
    {modal?.type === "doctors" && modal.clinic && <ClinicDoctors clinic={modal.clinic} onClose={() => setModal(null)} />}
    {(modal?.type === "delete" || modal?.type === "deactivate" || modal?.type === "reactivate" || modal?.type === "restore") && modal.clinic && <LifecycleDialog kind="clinics" record={modal.clinic} name={modal.clinic.name_ar} facilityId={facilityId} action={modal.type} onClose={() => setModal(null)} onSaved={(message, action) => { setNotice(message); saved(); if (clinicId && action === "delete") router.push(returnPath); }} onRefresh={saved} />}
  </>;
}

function ClinicTable({ query, searching, visible, can, onAction, onPage, onPageSize, result }: { query: string; searching: boolean; visible: Column[]; can: (action: string) => boolean; onAction: (type: "edit" | "doctors" | LifecycleAction, clinic: Clinic) => void; onPage: (page: number) => void; onPageSize: (value: string) => void; result: ReturnType<typeof useClinicRequest<Page<Clinic>>> }) {

  if (result.error && !result.data) return <div className={styles.status}><p role="alert">{result.error}</p><button onClick={result.retry} className={styles.secondary}>إعادة المحاولة</button></div>;
  if (!result.data) return <div className={styles.status} role="status">جارٍ تحميل العيادات…</div>;
  const { data, meta } = result.data;
  return <>{result.error && <p role="alert" className={styles.error}>{result.error} المعروض نتائج سابقة؛ يلزم نجاح إعادة التحميل لإتاحة التصدير. <button onClick={result.retry}>إعادة المحاولة</button></p>}<div className={styles.resultSummary}><strong>{meta.total} عيادة</strong><span role={searching || result.loading ? "status" : undefined}>{searching || result.loading ? "جارٍ تحديث النتائج…" : "التصدير يشمل جميع النتائج المطابقة"}</span></div>
    <div className={styles.tableScroll} tabIndex={0} role="region" aria-label="جدول العيادات" aria-busy={searching || result.loading}><table><thead><tr>{visible.map(key => <th key={key} scope="col">{columns[key]}</th>)}<th scope="col">الإجراءات</th></tr></thead><tbody>
      {data.map((clinic, index) => <tr key={clinic.id}>{visible.map(key => <td key={key}>{key === "number" ? (meta.page - 1) * meta.per_page + index + 1
        : key === "code" ? <Link className={styles.code} href={`/clinics/${clinic.id}?${query}`}>{clinic.code}</Link>
        : key === "name_ar" ? <div className={styles.clinicName}><strong>{clinic.name_ar}</strong><span className={clinic.is_active ? styles.active : styles.inactive}>{clinic.archived_at ? "مؤرشفة" : clinic.is_active ? "فعالة" : "غير فعالة"}</span></div>
        : key === "description" ? <LongText text={clinic.description} />
        : key === "doctors" ? <button className={styles.textButton} onClick={() => onAction("doctors", clinic)}>{clinic.doctors_preview.map(doctor => doctor.name).join("، ") || "لا يوجد أطباء"}{clinic.doctor_count > 3 ? ` +${clinic.doctor_count - 3}` : ""}</button>
        : key === "doctor_count" ? <button className={styles.countButton} aria-label={`أطباء ${clinic.name_ar}: ${clinic.doctor_count}`} onClick={() => onAction("doctors", clinic)}>{clinic.doctor_count}</button>
        : clinic.patient_count}</td>)}<td><div className={styles.rowActions}><Link href={`/clinics/${clinic.id}?${query}`} className={styles.iconButton} aria-label={`استعراض ${clinic.name_ar}`} title="استعراض"><LuEye aria-hidden="true" /></Link>{can("update") && !clinic.archived_at && <button className={styles.iconButton} onClick={() => onAction("edit", clinic)} aria-label={`تعديل ${clinic.name_ar}`} title="تعديل"><LuSquarePen aria-hidden="true" /></button>}{can("delete") && <button className={`${styles.iconButton} ${styles.dangerText}`} onClick={() => onAction("delete", clinic)} aria-label={`حذف ${clinic.name_ar}`} title="حذف"><LuTrash2 aria-hidden="true" /></button>}<LifecycleActions record={clinic} name={clinic.name_ar} canUpdate={can("update")} onAction={action => onAction(action, clinic)} /></div></td></tr>)}
      {!data.length && <tr><td colSpan={visible.length + 1}><div className={styles.status}>لا توجد عيادات مطابقة. عدّل البحث والفلاتر أو أضف عيادة جديدة إن كانت لديك الصلاحية.</div></td></tr>}
    </tbody></table></div><Pagination meta={meta} onPage={onPage} onPageSize={onPageSize} />
  </>;
}

function ClinicDetail({ id, facilityId, can, onAction, returnPath, exports }: { id: string; facilityId: number; can: (action: string) => boolean; onAction: (action: "edit" | LifecycleAction, clinic: Clinic) => void; returnPath: string; exports: React.ReactNode }) {
  const result = useClinicRequest<Clinic>(`clinics/${id}?facility_id=${facilityId}`);
  if (result.error) return <div className={styles.status}><p role="alert">{result.error}</p><button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button><Link href={returnPath}>العودة إلى القائمة</Link></div>;
  if (!result.data) return <p role="status" className={styles.status}>جارٍ تحميل العيادة…</p>;
  const clinic = result.data;
  return <><Link href={returnPath} className={styles.back}><LuArrowRight aria-hidden="true" />العودة إلى قائمة العيادات</Link><div className={styles.heading}><div><p className={styles.eyebrow}>بطاقة العيادة · <bdi>{clinic.code}</bdi></p><h2>{clinic.name_ar}</h2><p>{clinic.specialty?.name_ar || "دون تخصص محدد"} · {clinic.archived_at ? "مؤرشفة" : clinic.is_active ? "فعالة" : "غير فعالة"}</p></div><div className={styles.actions}>{can("update") && !clinic.archived_at && <button className={styles.primary} onClick={() => onAction("edit", clinic)}><LuSquarePen aria-hidden="true" />تعديل العيادة</button>}{can("delete") && <button className={styles.secondary} onClick={() => onAction("delete", clinic)} aria-label={`حذف ${clinic.name_ar}`}>حذف أو أرشفة</button>}<LifecycleActions record={clinic} name={clinic.name_ar} canUpdate={can("update")} onAction={action => onAction(action, clinic)} />{exports}</div></div>
    <section className={styles.detailPanel}><h3>توصيف العيادة</h3><p className={styles.description}>{clinic.description || "لا يوجد توصيف مسجل لهذه العيادة."}</p><div className={styles.metrics}><div><span>الأطباء الحاليون</span><strong>{clinic.doctor_count}</strong></div><div><span>المرضى المختلفون</span><strong>{clinic.patient_count}</strong></div></div><p className={styles.hint}>{clinic.patient_count_definition}</p></section>
    <LinkHistory kind="clinics" id={clinic.id} facilityId={facilityId} />
    <section className={styles.detailPanel}><h3>أطباء العيادة الحاليون</h3><DoctorList clinic={clinic} /></section>
  </>;
}

function SpecialtyFilter({ facilityId, value, onChange }: { facilityId: number; value: string; onChange: (value: string) => void }) {
  const [needed, setNeeded] = useState(false);
  const result = useClinicRequest<Specialty[]>(needed || value ? `clinics/options/specialties?facility_id=${facilityId}` : null);
  return <label>التخصص<select value={value} onFocus={() => setNeeded(true)} onPointerDown={() => setNeeded(true)} onChange={e => onChange(e.target.value)}><option value="">كل التخصصات</option>{value && !result.data?.some(s => String(s.id) === value) && <option value={value}>{result.loading ? "جارٍ تحميل التخصص…" : "التخصص غير متاح"}</option>}{result.data?.map(s => <option key={s.id} value={s.id}>{s.name_ar}</option>)}</select>{result.error && <button type="button" onClick={result.retry}>إعادة تحميل التخصصات</button>}</label>;
}
