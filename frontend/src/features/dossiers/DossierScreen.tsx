"use client";

import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { LuHospital, LuPlus, LuSearch } from "react-icons/lu";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack, DirectoryRowActions, DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination, LongText } from "../directory/Controls";
import { useClinicRequest, type Page } from "../clinics/api";
import useClinicSearch from "../clinics/useClinicSearch";
import { type Dossier, type DossierRow, type Visit, type ClinicalItem, historyLabels, treatmentLabels, sourceLabels } from "./api";
import styles from "../clinics/clinics.module.css";

export default function DossierScreen({ id }: { id?: string }) {
  const { access, user } = useIdentity(); const params = useSearchParams();
  const { entry } = directoryFacility(access, "dossiers.view", params.get("facility_id"));
  if (!entry || (id && !/^[1-9]\d*$/.test(id))) return <section className={styles.status}><h2>الإضبارات غير متاحة</h2><p role="alert">تعذّر تحديد مشفى مصرح لك بالوصول إلى إضباراته. راجع مسؤول الصلاحيات.</p></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>{id ? <Detail key={`${user.id}:${entry.facility.id}:${id}`} id={id} facility={entry.facility.id} /> : <Listing key={`${user.id}:${entry.facility.id}`} facility={entry.facility.id} />}</div>;
}
function Failure({ error, retry }: { error: string; retry: () => void }) { return <div className={styles.status}><p role="alert">{error}</p><button className={styles.secondary} onClick={retry}>إعادة المحاولة</button></div>; }
function StatusBadge({ status, visit = false }: { status: "draft" | "active" | "complete"; visit?: boolean }) {
  const text = status === "draft" ? "مسودة" : visit ? "مكتملة" : "فعالة";
  return <span className={status === "draft" ? styles.badge : styles.active} aria-label={`${visit ? "حالة الزيارة" : "حالة الإضبارة"}: ${text}`}>{text}</span>;
}
function Listing({ facility }: { facility: number }) {
  const params = useSearchParams(); const pathname = usePathname();
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facility);
  const q = new URLSearchParams({ facility_id: String(facility) });
  for (const key of ["status", "oncology", "visits", "from", "to", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) q.set(key, value); }
  if (committed) q.set("search", committed);
  const list = useClinicRequest<Page<DossierRow> & { totals: { dossiers: number } }>(`dossiers?${q}`, true, true);
  function filter(key: string, value: string) { cancel(); const next = new URLSearchParams(q); if (value) next.set(key, value); else next.delete(key); if (key !== "page") next.delete("page"); window.history.replaceState(null, "", `${pathname}?${next}`); }
  const headings = ["م", "كود الإضبارة", "اسم المريض", "تشخيصات آخر زيارة", "عيادات التشخيصات", "الأطباء المسؤولون", "عدد الزيارات", "الإجراءات"];
  return <><header className={styles.heading}><div><h2>الإضبارات</h2><p>ملفات المرضى في المشفى · عرض فقط</p></div><div><button type="button" className={styles.primary} disabled aria-describedby="add-dossier-note"><LuPlus aria-hidden="true" />إضافة إضبارة</button><p id="add-dossier-note" className={styles.hint}>ستتاح إضافة الإضبارة في المرحلة التالية.</p></div></header>
    <section className={styles.panel}><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث</span><input type="search" aria-label="البحث في الإضبارات" placeholder="كود الإضبارة أو المريض أو الاسم…" value={search} onChange={e => change(e.target.value)} /></label></div><div className={styles.filters}>
      <label>حالة الإضبارة<select value={params.get("status") ?? "all"} onChange={e => filter("status", e.target.value)}><option value="all">الكل</option><option value="draft">مسودة</option><option value="active">فعالة</option></select></label>
      <label>تاريخ الإضبارة من<input type="date" value={params.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label>
      <label>تاريخ الإضبارة إلى<input type="date" value={params.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
      <label>الحالة الورمية<select value={params.get("oncology") ?? ""} onChange={e => filter("oncology", e.target.value)}><option value="">الكل</option><option value="yes">ورمي</option><option value="no">غير ورمي</option></select></label>
      <label>الزيارات<select value={params.get("visits") ?? ""} onChange={e => filter("visits", e.target.value)}><option value="">الكل</option><option value="with">لديه زيارات</option><option value="without">دون زيارات</option></select></label>
      <label>الترتيب<select value={params.get("sort") ?? "opening_date"} onChange={e => filter("sort", e.target.value)}><option value="opening_date">تاريخ الإضبارة</option><option value="code">كود الإضبارة</option><option value="patient_name">اسم المريض</option><option value="visit_count">عدد الزيارات</option></select></label>
      <label>الاتجاه<select value={params.get("direction") ?? "desc"} onChange={e => filter("direction", e.target.value)}><option value="desc">تنازلي</option><option value="asc">تصاعدي</option></select></label>
    </div>
    {(list.loading || searching) && <p className={styles.status} role="status">{list.data ? "جارٍ تحديث النتائج؛ تظهر النتائج السابقة مؤقتًا." : "جارٍ تحميل الإضبارات…"}</p>}
    {list.error && <><Failure error={list.error} retry={list.retry} />{list.data && <p role="status">المعروض نتائج سابقة؛ أعد التحميل للحصول على النتائج الحالية.</p>}</>}
    {list.data && <><div className={styles.resultSummary}><strong>{list.data.totals.dossiers} إضبارة مطابقة</strong></div>{list.data.data.length ? <DirectoryTable label="جدول الإضبارات" busy={list.loading || searching} headers={headings}>{list.data.data.map((row, index) => <tr key={row.id}><td className={styles.identifierCell}>{(list.data!.meta.page - 1) * list.data!.meta.per_page + index + 1}</td><td className={styles.identifierCell}><bdi>{row.code}</bdi><div><StatusBadge status={row.status} /></div></td><td>{row.patient_name}<p className={styles.hint}><bdi>{row.patient_code}</bdi></p></td><td>{row.latest_visit_status && <div><StatusBadge status={row.latest_visit_status} visit /></div>}{row.diagnoses.length ? <LongText text={row.diagnoses.map(d => d.name).join(" · ")} /> : "لا توجد تشخيصات مسجلة"}</td><td>{[...new Set(row.diagnoses.map(d => d.clinic).filter(Boolean))].join(" · ") || "غير مسجلة"}</td><td>{[...new Set(row.diagnoses.map(d => d.doctor).filter(Boolean))].join(" · ") || "غير مسجل"}</td><td className={styles.identifierCell}>{row.visit_count}</td><td><DirectoryRowActions name={row.code} href={`/dossiers/${row.id}?${q}`} /></td></tr>)}</DirectoryTable> : <div className={styles.status}>لا توجد إضبارات مطابقة. وجود مريض في السجل لا يعني وجود إضبارة له.</div>}<Pagination meta={list.data.meta} onPage={p => filter("page", String(p))} onPageSize={s => filter("per_page", s)} /></>}
    </section></>;
}
const personalLabels: Record<string, string> = { patient_code: "كود المريض", first_name: "الاسم الأول", family_name: "اسم العائلة", father_name: "اسم الأب", mother_name: "اسم الأم", birth_date: "تاريخ الميلاد", birth_date_accuracy: "دقة الميلاد", gender: "الجنس", phone: "الهاتف", alt_phone: "هاتف بديل", governorate: "المحافظة", city: "المدينة", address_line: "عنوان السكن", displacement_status: "حالة النزوح" };
const valueLabels: Record<string, string> = { unknown: "غير معروف", male: "ذكر", female: "أنثى", exact: "دقيق", year_only: "السنة فقط", estimated: "تقديري", resident: "مقيم", idp: "نازح" };
function Detail({ id, facility }: { id: string; facility: number }) {
  const params = useSearchParams(); const router = useRouter(); const pathname = usePathname();
  const back = new URLSearchParams(params.toString()); back.set("facility_id", String(facility)); back.delete("visit"); back.delete("visits_page");
  const record = useClinicRequest<Dossier>(`dossiers/${id}?facility_id=${facility}`);
  const visitId = params.get("visit"); const validVisit = visitId && /^[1-9]\d*$/.test(visitId);
  const history = useClinicRequest<Page<Pick<Visit, "id" | "visit_no" | "visit_date" | "status">>>(`dossiers/${id}/visits?facility_id=${facility}&page=${params.get("visits_page") ?? 1}&per_page=10`, true);
  const prior = useClinicRequest<Visit>(validVisit ? `dossiers/${id}/visits/${visitId}?facility_id=${facility}` : null);
  function navigate(key: string, value: string) { const next = new URLSearchParams(params.toString()); next.set("facility_id", String(facility)); if (value) next.set(key, value); else next.delete(key); router.push(`${pathname}?${next}`, { scroll: false }); }
  const d = record.data; const selected = visitId ? prior.data : d?.latest_visit;
  return <><DirectoryBack href={`/dossiers?${back}`}>العودة إلى الإضبارات</DirectoryBack>{record.loading && <p role="status">جارٍ تحميل الإضبارة…</p>}{record.error && <Failure error={record.error} retry={record.retry} />}{d && <>
    <header className={styles.heading}><div><h2>الإضبارة <bdi>{d.code}</bdi></h2><StatusBadge status={d.status} /><p>{d.patient.first_name} {d.patient.family_name} · تاريخ الإضبارة {d.opening_date}</p></div><span className={styles.active}>{d.visit_count} زيارة</span></header>
    <section className={styles.detailPanel}><h2>بيانات المريض الحالية</h2><dl className={styles.facts}>{Object.entries(personalLabels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{valueLabels[d.patient[key] ?? ""] ?? d.patient[key] ?? "غير مسجل"}</dd></div>)}</dl></section>
    <section className={styles.detailPanel}><h2>المعلومات الطبية العامة</h2><dl className={styles.facts}><div><dt>الإعاقات</dt><dd><LongText text={d.disability_text} /></dd></div><div><dt>القصة المرضية المختصرة</dt><dd><LongText text={d.clinical_history} /></dd></div><div><dt>مريض ورمي</dt><dd>{d.is_oncology ? "نعم" : "لا"}</dd></div></dl>{d.oncology && <><h3>الملف الورمي الحالي</h3><dl className={styles.facts}><div><dt>أنواع السوابق</dt><dd>{d.oncology.selections.filter(s => s.selection_group === "history").map(s => historyLabels[s.code]).join(" · ") || "غير مسجلة"}</dd></div><div><dt>العلاجات السابقة</dt><dd>{d.oncology.selections.filter(s => s.selection_group === "treatment").map(s => treatmentLabels[s.code]).join(" · ") || "غير مسجلة"}</dd></div><div><dt>الفحوص السابقة</dt><dd><LongText text={d.oncology.previous_examinations} /></dd></div><div><dt>مصدر الدواء العام</dt><dd>{sourceLabels[d.oncology.medication_source ?? ""] ?? "غير مسجل"}{d.oncology.other_organization && ` · ${d.oncology.other_organization}`}</dd></div></dl></>}</section>
    <section className={styles.panel}><div className={styles.toolbar}><h2>{visitId ? "الزيارة المختارة" : "آخر زيارة فعلية"}</h2>{visitId && <button className={styles.secondary} onClick={() => navigate("visit", "")}>العودة إلى آخر زيارة</button>}</div>
      {visitId && !validVisit ? <p role="alert">مرجع الزيارة غير صالح.</p> : prior.loading ? <p role="status">جارٍ تحميل الزيارة…</p> : prior.error ? <Failure error={prior.error} retry={prior.retry} /> : selected ? <VisitSummary visit={selected} /> : <p className={styles.status}>لا توجد زيارات فعلية مرتبطة بهذه الإضبارة.</p>}
    </section>
    <section className={styles.panel}><div className={styles.toolbar}><h2>سجل الزيارات</h2><p>حسب التاريخ الفعلي، ثم معرّف الزيارة عند تساوي التاريخ.</p></div>{history.loading && <p role="status">جارٍ تحميل الزيارات…</p>}{history.error && <Failure error={history.error} retry={history.retry} />}{history.data && <><DirectoryTable label="زيارات الإضبارة" headers={["رقم الزيارة", "التاريخ الفعلي", "الاستعراض"]}>{history.data.data.map(v => <tr key={v.id}><td><bdi>{v.visit_no}</bdi><div><StatusBadge status={v.status} visit /></div></td><td>{v.visit_date}</td><td><button className={styles.secondary} onClick={() => navigate("visit", String(v.id))}>{v.id === d.latest_visit?.id ? "استعراض آخر زيارة" : "استعراض الزيارة"}</button></td></tr>)}{!history.data.data.length && <tr><td colSpan={3}>لا توجد زيارات مسجلة للإضبارة.</td></tr>}</DirectoryTable><Pagination meta={history.data.meta} onPage={p => navigate("visits_page", String(p))} /></>}</section>
  </>}</>;
}
function ClinicalTable({ title, items, draft }: { title: string; items: ClinicalItem[]; draft: boolean }) {
  return <><h3>{title}</h3>{draft && !items.length ? <p className={styles.hint}>لم تُسجّل بيانات هذا القسم بعد.</p> : <DirectoryTable label={title} headers={["الاسم", "التاريخ", "التفاصيل"]}>{items.map(item => <tr key={item.id}><td>{item.name}</td><td>{item.date}</td><td>{item.dose_text || ""} {item.quantity != null ? `${item.quantity} ${item.quantity_unit ?? ""}` : "—"}</td></tr>)}{!items.length && <tr><td colSpan={3}>لا توجد بيانات مسجلة.</td></tr>}</DirectoryTable>}</>;
}
function VisitSummary({ visit: v }: { visit: Visit }) {
  const draft = v.status === "draft";
  return <div className={styles.detailPanel}><h3>زيارة <bdi>{v.visit_no}</bdi> · {v.visit_date}</h3><StatusBadge status={v.status} visit />
    {draft && <p className={styles.hint}>هذه زيارة مسودة؛ المعروض هو البيانات المحفوظة حتى الآن، وقد تُستكمل أقسامها لاحقًا.</p>}
    {(v.visit_clinic || v.attending_doctor) && <p className={styles.hint}>سياق الزيارة المسجل: {v.visit_clinic ?? "عيادة غير مسجلة"} · {v.attending_doctor ?? "طبيب غير مسجل"}. لا يُنسب تلقائيًا إلى كل تشخيص.</p>}
    {draft && !v.diagnoses.length ? <p className={styles.hint}>لم تُسجّل تشخيصات لهذه المسودة بعد.</p> : <DirectoryTable label="تشخيصات الزيارة" headers={["التشخيص", "تاريخ التشخيص", "العيادة", "الطبيب المسؤول"]}>{v.diagnoses.map(d => <tr key={d.id}><td>{d.name} <bdi>({d.code})</bdi></td><td>{d.diagnosed_on ?? "غير معروف"}</td><td>{d.clinic ?? "غير مسجلة"}</td><td>{d.doctor ?? "غير مسجل"}</td></tr>)}{!v.diagnoses.length && <tr><td colSpan={4}>لا توجد تشخيصات مسجلة.</td></tr>}</DirectoryTable>}
    <ClinicalTable title="الخدمات المسجلة" items={v.services} draft={draft} /><ClinicalTable title="الإجراءات المسجلة" items={v.procedures} draft={draft} /><ClinicalTable title="الأدوية المصروفة" items={v.medications} draft={draft} /><ClinicalTable title="الأدوية المعطاة ضمن جلسات" items={v.administered_medications} draft={draft} /><ClinicalTable title="النتائج المسجلة" items={v.outcomes} draft={draft} />
  </div>;
}
