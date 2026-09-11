"use client";

import Link from "next/link";
import dynamic from "next/dynamic";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuArrowRight, LuDownload, LuEye, LuFileText, LuHospital, LuLink, LuPlus, LuSearch, LuSquarePen, LuTrash2 } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { AuthError } from "@/features/auth/api";
import useClinicSearch from "../clinics/useClinicSearch";
import { ColumnMenu, LongText, Pagination } from "../directory/Controls";
import LifecycleDialog, { LifecycleActions, LinkHistory, type LifecycleAction } from "../directory/Lifecycle";
import RelationFilter from "../directory/RelationFilter";
import DoctorClinics, { ClinicList } from "./DoctorClinics";
import { columns, columnKeys, downloadReport, useDirectoryRequest, type Capabilities, type Column, type Doctor, type Options, type Page } from "./api";
import styles from "../clinics/clinics.module.css";

const DoctorEditor = dynamic(() => import("./DoctorEditor"), { loading: () => <p role="status">جارٍ فتح النموذج…</p> });

export default function DoctorScreen({ doctorId }: { doctorId?: string }) {
  const { access } = useIdentity(); const query = useSearchParams(); const router = useRouter(); const cancelRef = useRef<(() => void) | null>(null);
  const allowed = access.filter(entry => entry.permissions.includes("doctors.view"));
  const facilityId = query.has("facility_id") ? Number(query.get("facility_id")) : allowed[0]?.facility.id;
  const entry = allowed.find(item => item.facility.id === facilityId);
  if (!entry || (doctorId && !/^[1-9]\d*$/.test(doctorId))) return <section className={styles.status}><h2>الأطباء غير متاحين</h2><p role="alert">ليس لديك وصول إلى دليل الأطباء في المنشأة المطلوبة.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>سياق المنشأة</span>{allowed.length === 1 ? <strong>{entry.facility.name_ar}</strong> : <select aria-label="المنشأة" value={facilityId} onChange={e => { cancelRef.current?.(); router.push(`/doctors?facility_id=${e.target.value}`); }}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select>}<span className={styles.contextCaption}>الدليل مشترك · المؤشرات ضمن المنشأة</span></div>
    <DoctorWorkspace key={`${facilityId}:${doctorId ?? "list"}`} facilityId={entry.facility.id} doctorId={doctorId} cancelRef={cancelRef} />
  </div>;
}

type Action = "edit" | "clinics" | "links" | LifecycleAction;
function DoctorWorkspace({ facilityId, doctorId, cancelRef }: { facilityId: number; doctorId?: string; cancelRef: React.RefObject<(() => void) | null> }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const { search, committed, change, searching, cancel } = useClinicSearch(pathname, params.toString(), facilityId);
  useEffect(() => { cancelRef.current = cancel; return () => { cancelRef.current = null; }; }, [cancel, cancelRef]);
  const options = useDirectoryRequest<Options>(`doctors/options?facility_id=${facilityId}`);
  const [modal, setModal] = useState<{ type: Action; doctor?: Doctor } | null>(null); const [revision, setRevision] = useState(0);
  const [visible, setVisible] = useState<Column[]>(columnKeys); const [exporting, setExporting] = useState(false); const [exportError, setExportError] = useState("");
  const pending = useRef(false); const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  for (const key of ["status", "clinic_id", "specialty_id", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) query.set(key, value); }
  if (committed) query.set("search", committed);
  const encoded = query.toString();
  // One request snapshot drives both rows and exports; its key includes query,
  // retry, revision and session, so retained rows never imply export readiness.
  const list = useDirectoryRequest<Page<Doctor>>(doctorId ? null : `doctors?${encoded}`, true, true, revision);
  const exportReady = !searching && (!!doctorId || (!!list.data && !list.loading && !list.error));
  function filter(key: string, value: string) {
    const next = new URLSearchParams(encoded); cancel(); if (search) next.set("search", search); else next.delete("search");
    if (value) next.set(key, value); else next.delete(key); if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  }
  async function exportFile(format: "xlsx" | "pdf") {
    if (pending.current || !exportReady || !visible.length || !cap.export) return; pending.current = true; setExporting(true); setExportError("");
    const active = new AbortController(); controller.current = active; const exportQuery = new URLSearchParams(encoded); for (const key of visible) exportQuery.append("columns[]", key);
    try { await downloadReport(doctorId ? `doctors/${doctorId}/report?facility_id=${facilityId}` : `doctors/export/${format}?${exportQuery}`, active.signal); }
    catch (reason) { if (!active.signal.aborted) setExportError(reason instanceof AuthError ? reason.message : "تعذّر تنزيل التقرير."); }
    finally { if (!active.signal.aborted) { pending.current = false; setExporting(false); } }
  }
  const settings = options.data, cap = settings?.capabilities ?? { create: false, update: false, delete: false, link: false, export: false, view_clinics: false };
  const [notice, setNotice] = useState("");
  const saved = () => { setModal(null); setRevision(n => n + 1); };
  const exports = cap.export && <div className={styles.actions}>{!doctorId && <button className={styles.secondary} disabled={exporting || !exportReady || !visible.length} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />Excel</button>}<button className={styles.secondary} disabled={exporting || !exportReady || !visible.length} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />{doctorId ? "تصدير تقرير الطبيب PDF" : "PDF"}</button>{exporting && <span role="status">جارٍ إعداد التقرير…</span>}</div>;
  return <>
    {options.error && <p role="alert" className={styles.error}>{options.error} <button type="button" onClick={options.retry}>إعادة تحميل خيارات الدليل</button></p>}
    {settings && !settings.doctor_types_configured && <p role="status" className={styles.scopeNote}>لا يوجد نوع طبي فعال مطابق للإعداد المعتمد. راجع مسؤول النظام؛ لا تُستنتج الأنواع من أسمائها أو أرقامها.</p>}
    {settings?.doctor_types_configured && !settings.staff_types.length && <p role="status" className={styles.scopeNote}>لا توجد أنواع أطباء فعالة ضمن الإعداد الحالي. راجع مسؤول النظام قبل الإضافة.</p>}
    {doctorId ? <DoctorDetail key={revision} id={doctorId} facilityId={facilityId} cap={cap} onAction={(type, doctor) => setModal({ type, doctor })} exports={exports} returnPath={`/doctors?${encoded}`} /> : <>
      <div className={styles.heading}><div><p className={styles.eyebrow}>الدليل الطبي / الأطباء</p><h2>إدارة الأطباء</h2><p>البيانات المهنية، التخصصات، والعيادات الحالية في مكان واحد.</p></div>{cap.create && <button className={styles.primary} disabled={!settings?.staff_types.length} onClick={() => setModal({ type: "edit" })}><LuPlus aria-hidden="true" />إضافة طبيب جديد</button>}</div>
      <section className={styles.panel} aria-label="قائمة الأطباء"><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث في الأطباء</span><input type="search" value={search} placeholder="اسم الطبيب أو كوده أو توصيفه أو عيادته…" onChange={e => change(e.target.value)} /></label>{exports}</div>
        <div className={styles.filters}><label>الحالة<select value={query.get("status") ?? ""} onChange={e => filter("status", e.target.value)}><option value="">الفعال والمعطل</option><option value="active">فعال</option><option value="inactive">غير فعال</option><option value="archived">مؤرشف</option></select></label>
          <label>التخصص<select value={query.get("specialty_id") ?? ""} onChange={e => filter("specialty_id", e.target.value)}><option value="">كل التخصصات</option>{settings?.specialties.map(s => <option key={s.id} value={s.id}>{s.name_ar}</option>)}</select></label>
          <RelationFilter kind="clinic" facilityId={facilityId} value={query.get("clinic_id") ?? ""} onChange={value => filter("clinic_id", value)} />
          <label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => filter("sort", e.target.value)}>{["code", "name", "clinic_count", "patient_count", "is_active"].map(key => <option key={key} value={key}>{columns[key as Column]}</option>)}</select></label>
          <label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => filter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label><ColumnMenu labels={columns} visible={visible} onChange={setVisible} />
        </div><DoctorTable result={list} query={encoded} visible={visible} searching={searching} cap={cap} onAction={(type, doctor) => setModal({ type, doctor })} onPage={page => filter("page", String(page))} onPageSize={size => filter("per_page", size)} />
      </section><p className={styles.hint}>عدد المرضى: المرضى المختلفون في الزيارات المكتملة وغير الملغاة التي يكون الطبيب فيها الطبيب المعالج داخل المنشأة. لا يجمع مرضى عياداته أو الوصفات والإجراءات.</p>
    </>}
    {notice && <p role="status">{notice}</p>}
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {settings && (modal?.type === "edit" || modal?.type === "links") && <DoctorEditor doctor={modal.doctor} facilityId={facilityId} options={settings} linksOnly={modal.type === "links"} onClose={() => setModal(null)} onSaved={saved} onReloaded={() => setRevision(n => n + 1)} />}
    {modal?.type === "clinics" && modal.doctor && <DoctorClinics doctor={modal.doctor} facilityId={facilityId} onClose={() => setModal(null)} />}
    {(modal?.type === "delete" || modal?.type === "deactivate" || modal?.type === "reactivate" || modal?.type === "restore") && modal.doctor && <LifecycleDialog kind="doctors" record={modal.doctor} name={modal.doctor.name} facilityId={facilityId} action={modal.type} onClose={() => setModal(null)} onSaved={(message, action) => { setNotice(message); saved(); if (doctorId && action === "delete") router.push(`/doctors?${encoded}`); }} onRefresh={saved} />}
  </>;
}

function DoctorTable({ query, visible, searching, cap, onAction, onPage, onPageSize, result }: { query: string; visible: Column[]; searching: boolean; cap: Capabilities; onAction: (action: Action, doctor: Doctor) => void; onPage: (n: number) => void; onPageSize: (value: string) => void; result: ReturnType<typeof useDirectoryRequest<Page<Doctor>>> }) {

  if (result.error && !result.data) return <div className={styles.status}><p role="alert">{result.error}</p><button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button></div>;
  if (!result.data) return <p role="status" className={styles.status}>جارٍ تحميل الأطباء…</p>;
  const { data, meta } = result.data;
  return <>{result.error && <p role="alert" className={styles.error}>{result.error} المعروض نتائج سابقة؛ يلزم نجاح إعادة التحميل لإتاحة التصدير. <button onClick={result.retry}>إعادة المحاولة</button></p>}<div className={styles.resultSummary}><strong>{meta.total} طبيب</strong><span role={searching || result.loading ? "status" : undefined}>{searching || result.loading ? "جارٍ تحديث النتائج…" : "التصدير يشمل جميع النتائج المطابقة"}</span></div><div className={styles.tableScroll} tabIndex={0} role="region" aria-label="جدول الأطباء" aria-busy={searching || result.loading}><table><thead><tr>{visible.map(key => <th key={key} scope="col">{columns[key]}</th>)}<th scope="col">الإجراءات</th></tr></thead><tbody>
    {data.map((doctor, index) => <tr key={doctor.id}>{visible.map(key => <td key={key}>{key === "number" ? (meta.page - 1) * meta.per_page + index + 1 : key === "code" ? <Link className={styles.code} href={`/doctors/${doctor.id}?${query}`}><bdi>{doctor.code}</bdi></Link>
      : key === "name" ? <div className={styles.clinicName}><strong>{doctor.name}</strong><small>{doctor.staff_type.name_ar}</small></div>
      : key === "specialties" ? <div className={styles.badges}>{doctor.specialties.map(s => <span className={styles.badge} key={s.id}>{s.name_ar}</span>)}</div>
      : key === "description" ? <LongText text={doctor.description} />
      : key === "clinics" ? <button className={styles.textButton} onClick={() => onAction("clinics", doctor)}>{doctor.clinics_preview.map(c => c.name_ar).join("، ") || "لا توجد عيادات"}{doctor.clinic_count > 3 ? ` +${doctor.clinic_count - 3}` : ""}</button>
      : key === "clinic_count" ? <button className={styles.countButton} aria-label={`عيادات ${doctor.name}: ${doctor.clinic_count}`} onClick={() => onAction("clinics", doctor)}>{doctor.clinic_count}</button>
      : key === "is_active" ? <span className={doctor.is_active ? styles.active : styles.inactive}>{doctor.archived_at ? "مؤرشف" : doctor.is_active ? "فعال" : "غير فعال"}</span> : doctor.patient_count}</td>)}
      <td><div className={styles.rowActions}><Link href={`/doctors/${doctor.id}?${query}`} className={styles.iconButton} aria-label={`استعراض ${doctor.name}`} title="استعراض"><LuEye aria-hidden="true" /></Link>{cap.update && !doctor.archived_at && <button className={styles.iconButton} onClick={() => onAction("edit", doctor)} aria-label={`تعديل ${doctor.name}`} title="تعديل الدليل المشترك"><LuSquarePen aria-hidden="true" /></button>}{cap.link && !cap.update && !doctor.archived_at && <button className={styles.iconButton} onClick={() => onAction("links", doctor)} aria-label={`إدارة عيادات ${doctor.name}`} title="إدارة الارتباطات"><LuLink aria-hidden="true" /></button>}{cap.delete && <button className={`${styles.iconButton} ${styles.dangerText}`} onClick={() => onAction("delete", doctor)} aria-label={`حذف ${doctor.name}`} title="حذف"><LuTrash2 aria-hidden="true" /></button>}<LifecycleActions record={doctor} name={doctor.name} canUpdate={cap.update} onAction={action => onAction(action, doctor)} /></div></td></tr>)}
    {!data.length && <tr><td colSpan={visible.length + 1}><div className={styles.status}>لا يوجد أطباء مطابقون. عدّل البحث والفلاتر أو أضف طبيبًا إن كانت لديك الصلاحية.</div></td></tr>}
  </tbody></table></div><Pagination meta={meta} onPage={onPage} onPageSize={onPageSize} /></>;
}

function DoctorDetail({ id, facilityId, cap, onAction, exports, returnPath }: { id: string; facilityId: number; cap: Capabilities; onAction: (action: Action, doctor: Doctor) => void; exports: React.ReactNode; returnPath: string }) {
  const result = useDirectoryRequest<Doctor>(`doctors/${id}?facility_id=${facilityId}`);
  if (result.error) return <div className={styles.status}><p role="alert">{result.error}</p><button onClick={result.retry}>إعادة المحاولة</button><Link href={returnPath}>العودة إلى القائمة</Link></div>;
  if (!result.data) return <p role="status" className={styles.status}>جارٍ تحميل الطبيب…</p>;
  const doctor = result.data;
  return <><Link href={returnPath} className={styles.back}><LuArrowRight aria-hidden="true" />العودة إلى قائمة الأطباء</Link><div className={styles.heading}><div><p className={styles.eyebrow}>بطاقة الطبيب · <bdi>{doctor.code}</bdi></p><h2>{doctor.name}</h2><p>{doctor.staff_type.name_ar} · {doctor.archived_at ? "مؤرشف" : doctor.is_active ? "فعال" : "غير فعال"}</p></div><div className={styles.actions}>{cap.update && !doctor.archived_at && <button className={styles.primary} onClick={() => onAction("edit", doctor)}><LuSquarePen aria-hidden="true" />تعديل الطبيب</button>}{cap.link && !cap.update && !doctor.archived_at && <button className={styles.primary} onClick={() => onAction("links", doctor)}><LuLink aria-hidden="true" />إدارة العيادات</button>}{cap.delete && <button className={styles.secondary} onClick={() => onAction("delete", doctor)} aria-label={`حذف ${doctor.name}`}>حذف أو أرشفة</button>}<LifecycleActions record={doctor} name={doctor.name} canUpdate={cap.update} onAction={action => onAction(action, doctor)} />{exports}</div></div>
    <section className={styles.detailPanel}><h3>الملف المهني</h3><div className={styles.badges}>{doctor.specialties.map(s => <span className={styles.badge} key={s.id}>{s.name_ar}</span>)}</div><p className={styles.description}>{doctor.description || "لا يوجد توصيف مسجل."}</p><dl className={styles.facts}><div><dt>رقم الترخيص</dt><dd><bdi>{doctor.license_no || "—"}</bdi></dd></div><div><dt>الهاتف</dt><dd><bdi>{doctor.phone || "—"}</bdi></dd></div><div><dt>العيادات الحالية</dt><dd>{doctor.clinic_count}</dd></div><div><dt>المرضى المختلفون</dt><dd>{doctor.patient_count}</dd></div></dl><p className={styles.hint}>{doctor.patient_count_definition}</p></section>
    <LinkHistory kind="doctors" id={doctor.id} facilityId={facilityId} />
    <section className={styles.detailPanel}><h3>العيادات الحالية</h3><ClinicList doctor={doctor} facilityId={facilityId} /></section></>;
}
