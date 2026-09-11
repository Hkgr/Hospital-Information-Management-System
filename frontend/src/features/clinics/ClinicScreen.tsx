"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuArrowRight, LuColumns3, LuDownload, LuEye, LuFileText, LuHospital, LuPlus, LuSearch, LuSquarePen, LuTrash2 } from "react-icons/lu";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { apiRequest, AuthError } from "@/features/auth/api";
import { columns, columnKeys, downloadReport, useClinicRequest, useDebounced, type Clinic, type Column, type Doctor, type Page, type Specialty } from "./api";
import ClinicEditor from "./ClinicEditor";
import ClinicDoctors, { DoctorList } from "./ClinicDoctors";
import Modal from "./Modal";
import styles from "./clinics.module.css";

export default function ClinicScreen({ clinicId }: { clinicId?: string }) {
  const { access } = useIdentity();
  const query = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const allowed = access.filter(entry => entry.permissions.includes("clinics.view"));
  const requested = query.get("facility_id");
  const facilityId = requested ? Number(requested) : allowed[0]?.facility.id;
  const entry = allowed.find(item => item.facility.id === facilityId);
  if (!entry || (clinicId && !/^[1-9]\d*$/.test(clinicId))) return <section className={styles.status}><h2>العيادات غير متاحة</h2><p role="alert">ليس لديك وصول إلى العيادات في المنشأة المطلوبة.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <div className={styles.screen}>
    <div className={styles.context}><LuHospital aria-hidden="true" /><span>المنشأة</span>{allowed.length === 1 ? <strong>{entry.facility.name_ar}</strong> : <select aria-label="المنشأة" value={facilityId} onChange={event => { const next = new URLSearchParams(); next.set("facility_id", event.target.value); router.push(`/clinics?${next}`); }}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select>}</div>
    <ClinicWorkspace key={`${facilityId}:${clinicId ?? "list"}`} facilityId={entry.facility.id} permissions={entry.permissions} clinicId={clinicId} pathname={pathname} />
  </div>;
}

function ClinicWorkspace({ facilityId, permissions, clinicId, pathname }: { facilityId: number; permissions: string[]; clinicId?: string; pathname: string }) {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [modal, setModal] = useState<{ type: "edit" | "doctors" | "delete" | "deactivate"; clinic?: Clinic } | null>(null);
  const [revision, setRevision] = useState(0);
  const [search, setSearch] = useState(searchParams.get("search") ?? "");
  const debounced = useDebounced(search);
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
  if (debounced) query.set("search", debounced);
  const encoded = query.toString();
  // Only allowlisted same-origin query values survive the return link.
  const returnPath = `/clinics?${encoded}`;
  const updateFilter = (key: string, value: string) => {
    const next = new URLSearchParams(encoded);
    if (value) next.set(key, value); else next.delete(key);
    if (key !== "page") next.delete("page");
    router.replace(`${pathname}?${next}`, { scroll: false });
  };
  useEffect(() => {
    if (clinicId || debounced === (searchParams.get("search") ?? "")) return;
    const next = new URLSearchParams(searchParams.toString());
    next.set("facility_id", String(facilityId)); next.delete("page");
    if (debounced) next.set("search", debounced); else next.delete("search");
    router.replace(`${pathname}?${next}`, { scroll: false });
  }, [debounced, clinicId, facilityId, pathname, router, searchParams]);
  const can = (action: string) => permissions.includes(`clinics.${action}`);
  const saved = () => { setModal(null); setRevision(value => value + 1); };
  async function exportFile(format: "xlsx" | "pdf") {
    if (exportPending.current) return;
    exportPending.current = true; setExporting(true); setExportError("");
    const controller = new AbortController(); exportController.current = controller;
    const exportQuery = new URLSearchParams(encoded);
    for (const column of visible) exportQuery.append("columns[]", column);
    try { await downloadReport(clinicId ? `clinics/${clinicId}/report?facility_id=${facilityId}` : `clinics/export/${format}?${exportQuery}`, controller.signal); }
    catch (reason) { if (!controller.signal.aborted) setExportError(reason instanceof AuthError ? reason.message : "تعذّر تنزيل التقرير. حاول مجددًا."); }
    finally { if (!controller.signal.aborted) { exportPending.current = false; setExporting(false); } }
  }
  const exports = can("export") && <div className={styles.actions}>
    {!clinicId && <button className={styles.secondary} disabled={exporting || !visible.length || search !== debounced} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />Excel</button>}
    <button className={styles.secondary} disabled={exporting || !visible.length || search !== debounced} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />{clinicId ? "تصدير تقرير العيادة PDF" : "PDF"}</button>
    {exporting && <span role="status">جارٍ إعداد التقرير…</span>}
  </div>;
  return <>
    {clinicId ? <ClinicDetail key={revision} id={clinicId} facilityId={facilityId} canEdit={can("update")} onEdit={clinic => setModal({ type: "edit", clinic })} returnPath={returnPath} exports={exports} /> : <>
      <div className={styles.heading}><div><p className={styles.eyebrow}>الدليل الطبي</p><h2>إدارة العيادات</h2><p>بيانات العيادات، الأطباء المرتبطون، وإحصاءات المرضى.</p></div>{can("create") && <button className={styles.primary} onClick={() => setModal({ type: "edit" })}><LuPlus aria-hidden="true" />إضافة عيادة جديدة</button>}</div>
      <section className={styles.panel} aria-label="قائمة العيادات">
        <div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" /> البحث في العيادات</span><input type="search" placeholder="ابحث بالكود أو الاسم أو التوصيف…" value={search} onChange={event => setSearch(event.target.value)} /></label>{exports}</div>
        <div className={styles.filters}>
          <label>الحالة<select value={query.get("status") ?? ""} onChange={e => updateFilter("status", e.target.value)}><option value="">كل الحالات</option><option value="active">فعالة</option><option value="inactive">غير فعالة</option></select></label>
          <SpecialtyFilter facilityId={facilityId} value={query.get("specialty_id") ?? ""} onChange={value => updateFilter("specialty_id", value)} />
          <DoctorFilter facilityId={facilityId} value={query.get("doctor_id") ?? ""} onChange={value => updateFilter("doctor_id", value)} />
          <label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => updateFilter("sort", e.target.value)}><option value="code">كود العيادة</option><option value="name_ar">اسم العيادة</option><option value="doctor_count">عدد الأطباء</option><option value="patient_count">عدد المرضى</option><option value="is_active">الحالة</option></select></label>
          <label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => updateFilter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label>
          <details className={styles.columnMenu}><summary><LuColumns3 aria-hidden="true" />الأعمدة</summary><fieldset><legend>الأعمدة الظاهرة في الجدول والتصدير</legend>{columnKeys.map(key => <label key={key}><input type="checkbox" checked={visible.includes(key)} disabled={visible.length === 1 && visible.includes(key)} onChange={() => setVisible(current => columnKeys.filter(column => column === key ? !current.includes(key) : current.includes(column)))} />{columns[key]}</label>)}</fieldset></details>
        </div>
        <ClinicTable key={revision} query={encoded} searching={search !== debounced} visible={visible} can={can} onAction={(type, clinic) => setModal({ type, clinic })} onPage={value => updateFilter("page", String(value))} onPageSize={value => updateFilter("per_page", value)} />
      </section>
      <p className={styles.hint}>عدد المرضى: المرضى المختلفون في الزيارات المكتملة وغير الملغاة المرتبطة مباشرة بالعيادة. لا يزيد العدد بتكرار الزيارة.</p>
    </>}
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {modal?.type === "edit" && <ClinicEditor clinic={modal.clinic} facilityId={facilityId} onClose={() => setModal(null)} onSaved={saved} />}
    {modal?.type === "doctors" && modal.clinic && <ClinicDoctors clinic={modal.clinic} onClose={() => setModal(null)} />}
    {(modal?.type === "delete" || modal?.type === "deactivate") && modal.clinic && <ConfirmClinic clinic={modal.clinic} kind={modal.type} onClose={() => setModal(null)} onSaved={saved} />}
  </>;
}

function ClinicTable({ query, searching, visible, can, onAction, onPage, onPageSize }: { query: string; searching: boolean; visible: Column[]; can: (action: string) => boolean; onAction: (type: "edit" | "doctors" | "delete" | "deactivate", clinic: Clinic) => void; onPage: (page: number) => void; onPageSize: (value: string) => void }) {
  const result = useClinicRequest<Page<Clinic>>(`clinics?${query}`, true);
  if (result.error) return <div className={styles.status}><p role="alert">{result.error}</p><button onClick={result.retry} className={styles.secondary}>إعادة المحاولة</button></div>;
  if (!result.data || searching) return <div className={styles.status} role="status">جارٍ تحميل العيادات…</div>;
  const { data, meta } = result.data;
  return <><div className={styles.resultSummary}><strong>{meta.total} عيادة</strong><span>التصدير يشمل جميع النتائج المطابقة</span></div>
    <div className={styles.tableScroll} tabIndex={0} role="region" aria-label="جدول العيادات"><table><thead><tr>{visible.map(key => <th key={key} scope="col">{columns[key]}</th>)}<th scope="col">الإجراءات</th></tr></thead><tbody>
      {data.map((clinic, index) => <tr key={clinic.id}>{visible.map(key => <td key={key}>{key === "number" ? (meta.page - 1) * meta.per_page + index + 1
        : key === "code" ? <Link className={styles.code} href={`/clinics/${clinic.id}?${query}`}>{clinic.code}</Link>
        : key === "name_ar" ? <div className={styles.clinicName}><strong>{clinic.name_ar}</strong><span className={clinic.is_active ? styles.active : styles.inactive}>{clinic.is_active ? "فعالة" : "غير فعالة"}</span></div>
        : key === "description" ? <span className={styles.excerpt} title={clinic.description ?? undefined}>{clinic.description || "—"}</span>
        : key === "doctors" ? <button className={styles.textButton} onClick={() => onAction("doctors", clinic)}>{clinic.doctors_preview.map(doctor => doctor.name).join("، ") || "لا يوجد أطباء"}{clinic.doctor_count > 3 ? ` +${clinic.doctor_count - 3}` : ""}</button>
        : key === "doctor_count" ? <button className={styles.countButton} aria-label={`أطباء ${clinic.name_ar}: ${clinic.doctor_count}`} onClick={() => onAction("doctors", clinic)}>{clinic.doctor_count}</button>
        : clinic.patient_count}</td>)}<td><div className={styles.rowActions}><Link href={`/clinics/${clinic.id}?${query}`} className={styles.iconButton} aria-label={`استعراض ${clinic.name_ar}`} title="استعراض"><LuEye aria-hidden="true" /></Link>{can("update") && <button className={styles.iconButton} onClick={() => onAction("edit", clinic)} aria-label={`تعديل ${clinic.name_ar}`} title="تعديل"><LuSquarePen aria-hidden="true" /></button>}{can("delete") && <button className={`${styles.iconButton} ${styles.dangerText}`} onClick={() => onAction("delete", clinic)} aria-label={`حذف ${clinic.name_ar}`} title="حذف"><LuTrash2 aria-hidden="true" /></button>}{can("update") && clinic.is_active && <button className={styles.textButton} onClick={() => onAction("deactivate", clinic)} aria-label={`تعطيل ${clinic.name_ar}`}>تعطيل</button>}</div></td></tr>)}
      {!data.length && <tr><td colSpan={visible.length + 1}><div className={styles.status}>لا توجد عيادات مطابقة. عدّل البحث والفلاتر أو أضف عيادة جديدة إن كانت لديك الصلاحية.</div></td></tr>}
    </tbody></table></div><div className={styles.pagination}><label>عدد الصفوف<select value={meta.per_page} onChange={e => onPageSize(e.target.value)}>{[10, 20, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}</select></label><span>صفحة {meta.page} من {meta.last_page}</span><button disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>السابق</button><button disabled={meta.page >= meta.last_page} onClick={() => onPage(meta.page + 1)}>التالي</button></div>
  </>;
}

function ClinicDetail({ id, facilityId, canEdit, onEdit, returnPath, exports }: { id: string; facilityId: number; canEdit: boolean; onEdit: (clinic: Clinic) => void; returnPath: string; exports: React.ReactNode }) {
  const result = useClinicRequest<Clinic>(`clinics/${id}?facility_id=${facilityId}`);
  if (result.error) return <div className={styles.status}><p role="alert">{result.error}</p><button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button><Link href={returnPath}>العودة إلى القائمة</Link></div>;
  if (!result.data) return <p role="status" className={styles.status}>جارٍ تحميل العيادة…</p>;
  const clinic = result.data;
  return <><Link href={returnPath} className={styles.back}><LuArrowRight aria-hidden="true" />العودة إلى قائمة العيادات</Link><div className={styles.heading}><div><p className={styles.eyebrow}>بطاقة العيادة · <bdi>{clinic.code}</bdi></p><h2>{clinic.name_ar}</h2><p>{clinic.specialty?.name_ar || "دون تخصص محدد"} · {clinic.is_active ? "فعالة" : "غير فعالة"}</p></div><div className={styles.actions}>{canEdit && <button className={styles.primary} onClick={() => onEdit(clinic)}><LuSquarePen aria-hidden="true" />تعديل العيادة</button>}{exports}</div></div>
    <section className={styles.detailPanel}><h3>توصيف العيادة</h3><p className={styles.description}>{clinic.description || "لا يوجد توصيف مسجل لهذه العيادة."}</p><div className={styles.metrics}><div><span>الأطباء الحاليون</span><strong>{clinic.doctor_count}</strong></div><div><span>المرضى المختلفون</span><strong>{clinic.patient_count}</strong></div></div><p className={styles.hint}>{clinic.patient_count_definition}</p></section>
    <section className={styles.detailPanel}><h3>أطباء العيادة الحاليون</h3><DoctorList clinic={clinic} /></section>
  </>;
}

function SpecialtyFilter({ facilityId, value, onChange }: { facilityId: number; value: string; onChange: (value: string) => void }) {
  const result = useClinicRequest<Specialty[]>(`clinics/options/specialties?facility_id=${facilityId}`);
  return <label>التخصص<select value={value} onChange={e => onChange(e.target.value)}><option value="">كل التخصصات</option>{result.data?.map(s => <option key={s.id} value={s.id}>{s.name_ar}</option>)}</select>{result.error && <button type="button" onClick={result.retry}>إعادة تحميل التخصصات</button>}</label>;
}

function DoctorFilter({ facilityId, value, onChange }: { facilityId: number; value: string; onChange: (value: string) => void }) {
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const debounced = useDebounced(search);
  const result = useClinicRequest<Page<Doctor>>(`clinics/options/doctors?${new URLSearchParams({ facility_id: String(facilityId), search: debounced, page: String(page) })}`, true);
  return <details className={styles.doctorFilter}><summary>الطبيب: {value ? `محدد (${value})` : "الكل"}</summary><div><label>ابحث عن طبيب<input type="search" value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} /></label><button type="button" onClick={() => onChange("")}>كل الأطباء</button>{result.error ? <button onClick={result.retry}>تعذّر التحميل؛ أعد المحاولة</button> : !result.data || search !== debounced ? <p role="status">جارٍ البحث…</p> : <><ul>{result.data.data.map(doctor => <li key={doctor.id}><button type="button" aria-pressed={value === String(doctor.id)} onClick={event => { onChange(String(doctor.id)); event.currentTarget.closest("details")?.removeAttribute("open"); }}>{doctor.name} · {doctor.code}</button></li>)}</ul>{!result.data.data.length && <p>لا توجد نتائج</p>}<div className={styles.pagination}><button disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button><button disabled={page >= result.data.meta.last_page} onClick={() => setPage(page + 1)}>التالي</button></div></>}</div></details>;
}

function ConfirmClinic({ clinic, kind, onClose, onSaved }: { clinic: Clinic; kind: "delete" | "deactivate"; onClose: () => void; onSaved: () => void }) {
  const [busy, setBusy] = useState(false);
  const pending = useRef(false);
  const [error, setError] = useState("");
  const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  async function confirm() {
    if (pending.current) return; pending.current = true; setBusy(true); setError("");
    const active = new AbortController(); controller.current = active;
    try {
      await apiRequest(`clinics/${clinic.id}${kind === "deactivate" ? "/deactivate" : ""}`, { method: kind === "delete" ? "DELETE" : "POST", signal: active.signal, body: JSON.stringify({ facility_id: clinic.facility_id, lock_version: clinic.lock_version }) });
      if (!active.signal.aborted) onSaved();
    } catch (reason) { if (!active.signal.aborted) setError(reason instanceof AuthError ? reason.message : "تعذّر إتمام العملية."); }
    finally { if (!active.signal.aborted) { pending.current = false; setBusy(false); } }
  }
  return <Modal title={kind === "delete" ? "تأكيد حذف العيادة" : "تأكيد تعطيل العيادة"} busy={busy} onClose={onClose}><div className={styles.confirm}><p><strong>{clinic.name_ar}</strong> · <bdi>{clinic.code}</bdi></p><p>{kind === "delete" ? "الحذف نهائي ومتاح فقط إذا لم ترتبط العيادة بسجلات أو بتاريخ أطباء." : "سيتم تعطيل العيادة مع الاحتفاظ ببياناتها وتاريخها الطبي."}</p>{error && <p role="alert" className={styles.error}>{error}</p>}<div className={styles.modalActions}><button className={styles.secondary} disabled={busy} onClick={onClose}>إلغاء</button><button className={kind === "delete" ? styles.danger : styles.primary} disabled={busy} onClick={() => void confirm()}>{busy ? "جارٍ التنفيذ…" : kind === "delete" ? "حذف نهائي" : "تعطيل العيادة"}</button></div></div></Modal>;
}
