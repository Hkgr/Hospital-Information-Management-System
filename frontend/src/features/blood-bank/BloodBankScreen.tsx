"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useEffect, useRef, useState } from "react";
import { LuDownload, LuFileText, LuHospital, LuPlus, LuSearch, LuSquarePen } from "react-icons/lu";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest } from "../auth/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack, DirectoryRowActions, DirectoryTable } from "../directory/DirectoryPrimitives";
import { Pagination } from "../directory/Controls";
import useClinicSearch from "../clinics/useClinicSearch";
import { downloadReport } from "../clinics/api";
import ProfileEditor from "./ProfileEditor";
import DonationEditor from "./DonationEditor";
import { type Donation, type Kind, type Options, type Page, type Profile, type Row, bloodLabel, choices, kindName, personLabels, results, statuses, useBloodRequest } from "./api";
import styles from "../clinics/clinics.module.css";

export default function BloodBankScreen({ kind, id, donationId }: { kind?: Kind; id?: string; donationId?: string }) {
  const { access, user } = useIdentity(); const query = useSearchParams();
  const { entry } = directoryFacility(access, "blood_bank.view", query.get("facility_id"));
  if (!entry || (id && !/^[1-9]\d*$/.test(id)) || (donationId && !/^[1-9]\d*$/.test(donationId))) return <section className={styles.status}><h2>بنك الدم غير متاح</h2><p role="alert">تعذّر تحديد مشفى مصرح لك بالوصول إلى بنك الدم فيه. راجع مسؤول الصلاحيات.</p><Link href="/">العودة إلى لوحة التحكم</Link></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div><Workspace key={`${user.id}:${entry.facility.id}:${kind ?? "list"}:${id ?? ""}:${donationId ?? ""}`} facilityId={entry.facility.id} kind={kind} id={id} donationId={donationId} /></div>;
}

function Workspace({ facilityId, kind, id, donationId }: { facilityId: number; kind?: Kind; id?: string; donationId?: string }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const [revision, setRevision] = useState(0); const refresh = () => setRevision(v => v + 1);
  const [editor, setEditor] = useState<{ kind: Kind; profile?: Profile } | null>(null);
  const [donationEditor, setDonationEditor] = useState<{ donation?: Donation } | null>(null);
  const [opening, setOpening] = useState(false); const [error, setError] = useState("");
  const controller = useRef<AbortController | null>(null);
  const exportController = useRef<AbortController | null>(null);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState("");
  useEffect(() => () => controller.current?.abort(), []);
  const options = useBloodRequest<Options>(`blood-bank/options?facility_id=${facilityId}`);
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facilityId);
  const query = new URLSearchParams(); query.set("facility_id", String(facilityId));
  for (const key of ["kind", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) query.set(key, value); }
  if (committed) query.set("search", committed);
  const encoded = query.toString(); const profileQuery = new URLSearchParams(encoded); for (const key of ["donation_search", "donation_page", "donation_per_page"]) { const v = params.get(key); if (v) profileQuery.set(key, v); } const returnPath = `/blood-bank?${encoded}`;
  const list = useBloodRequest<Page<Row>>(id ? null : `blood-bank?${encoded}`, true, true, revision);
  const detail = useBloodRequest<Profile>(id && kind ? `blood-bank/${kind}/${id}?facility_id=${facilityId}` : null, false, false, revision);
  const event = useBloodRequest<Donation>(id && donationId ? `blood-bank/donor/${id}/donations/${donationId}?facility_id=${facilityId}` : null, false, false, revision);
  const exportReady = id ? !!detail.data && !detail.loading && !detail.error && (!donationId || (!!event.data && !event.loading && !event.error)) : !searching && !!list.data && !list.loading && !list.error;
  useEffect(() => () => { exportController.current?.abort(); exportController.current = null; }, [encoded, revision]);
  async function exportFile(format: "pdf" | "xlsx") {
    if (!exportReady || !options.data?.capabilities.export || exportController.current) return;
    const active = new AbortController(); exportController.current = active; setExporting(true); setExportError("");
    const path = id ? donationId ? `blood-bank/donor/${id}/donations/${donationId}/report/${format}?facility_id=${facilityId}` : `blood-bank/${kind}/${id}/report/${format}?facility_id=${facilityId}` : `blood-bank/export/${format}?${encoded}`;
    try { await downloadReport(path, active.signal); }
    catch (e) { if (!active.signal.aborted) setExportError(e instanceof Error ? e.message : "تعذّر إنشاء التقرير. أعد المحاولة."); }
    finally { if (exportController.current === active) exportController.current = null; if (!exportController.current) setExporting(false); }
  }
  function filter(key: string, value: string) { cancel(); const next = new URLSearchParams(encoded); if (search) next.set("search", search); else next.delete("search"); if (value) next.set(key, value); else next.delete(key); if (key !== "page") next.delete("page"); router.replace(`${pathname}?${next}`, { scroll: false }); }
  async function edit(row: Row) {
    if (controller.current && !controller.current.signal.aborted) return;
    const active = new AbortController(); controller.current = active; setOpening(true); setError("");
    try { const profile = await apiRequest<Profile>(`blood-bank/${row.kind}/${row.id}?facility_id=${facilityId}`, { signal: active.signal }); if (!active.signal.aborted) setEditor({ kind: row.kind, profile }); }
    catch { if (!active.signal.aborted) setError("تعذّر فتح الملف. أعد المحاولة؛ لم تُغيّر أي بيانات."); }
    finally { if (!active.signal.aborted) { controller.current = null; setOpening(false); } }
  }
  if (options.error) return <RequestState error={options.error} retry={options.retry} />;
  if (!options.data) return <p role="status">جارٍ تحديد وصول بنك الدم…</p>;
  const caps = options.data.capabilities;
  const exports = caps.export && <div className={styles.actions}><button className={styles.secondary} disabled={exporting || !exportReady} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />{id ? "تصدير Excel" : "Excel"}</button><button className={styles.secondary} disabled={exporting || !exportReady} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />{id ? "تقرير PDF" : "PDF"}</button>{exporting && <span role="status">جارٍ إعداد التقرير…</span>}</div>;
  return <>
    {!id ? <><div className={styles.heading}><div><p className={styles.eyebrow}>بنك الدم</p><h2>المتبرعون والمستفيدون</h2><p>ملفات دائمة؛ إنشاء ملف المتبرع لا يسجّل واقعة تبرع.</p></div>{caps.create && <div className={styles.actions}>{(["donor", "recipient"] as Kind[]).map(k => <button key={k} className={styles.primary} onClick={() => setEditor({ kind: k })}><LuPlus aria-hidden="true" />إضافة {kindName(k)}</button>)}</div>}</div>
      <section className={styles.panel} aria-label="ملفات بنك الدم"><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث في بنك الدم</span><input type="search" placeholder="الاسم الكامل أو كود الملف…" value={search} onChange={e => change(e.target.value)} /></label>{exports}</div>
        <div className={styles.filters}><label>نوع الملف<select value={query.get("kind") ?? ""} onChange={e => filter("kind", e.target.value)}><option value="">الكل</option><option value="donor">المتبرعون</option><option value="recipient">المستفيدون</option></select></label><label>الترتيب<select value={query.get("sort") ?? "code"} onChange={e => filter("sort", e.target.value)}><option value="code">كود الملف</option><option value="name">الاسم</option><option value="updated_at">آخر تحديث</option></select></label><label>الاتجاه<select value={query.get("direction") ?? "asc"} onChange={e => filter("direction", e.target.value)}><option value="asc">تصاعدي</option><option value="desc">تنازلي</option></select></label></div>
        {list.error && <RequestState error={`${list.error}${list.data ? " المعروض نتائج سابقة؛ التصدير يتطلب نجاح إعادة التحميل." : ""}`} retry={list.retry} />}
        {list.loading && <p role="status">{list.data ? "جارٍ تحديث النتائج…" : "جارٍ تحميل الملفات…"}</p>}
        {list.data && <><div className={styles.resultSummary}><strong>{list.data.meta.total} ملف</strong>{searching && <span role="status">جارٍ اعتماد البحث…</span>}</div><DirectoryTable label="جدول بنك الدم" busy={list.loading || searching} headers={["م", "كود الملف", "الاسم", "النوع", "الزمرة", "العيادة", "الطبيب المسؤول", "الإجراءات"]}>{list.data.data.map((row, index) => <tr key={`${row.kind}:${row.id}`}><td>{(list.data!.meta.page - 1) * list.data!.meta.per_page + index + 1}</td><td><Link className={styles.code} href={`/blood-bank/${row.kind}/${row.id}?${encoded}`}>{row.code}</Link></td><td><strong>{row.name}</strong></td><td>{kindName(row.kind)}</td><td>{bloodLabel(row.blood_group, row.rh)}</td><td>{row.clinic_name || "غير مسجل"}</td><td>{row.doctor_name || "غير مسجل"}</td><td><DirectoryRowActions name={row.name} href={`/blood-bank/${row.kind}/${row.id}?${encoded}`} disabled={opening || list.loading} onEdit={caps.update ? () => void edit(row) : undefined} /></td></tr>)}{!list.data.data.length && <tr><td colSpan={8}><div className={styles.status}>لا توجد ملفات مطابقة.</div></td></tr>}</DirectoryTable><Pagination meta={list.data.meta} onPage={p => filter("page", String(p))} onPageSize={p => filter("per_page", p)} /></>}
      </section></> : <>
        <DirectoryBack href={donationId ? `/blood-bank/donor/${id}?${profileQuery}` : returnPath}>{donationId ? "العودة إلى ملف المتبرع" : "العودة إلى ملفات بنك الدم"}</DirectoryBack>
        {detail.error ? <RequestState error={detail.error} retry={detail.retry} /> : !detail.data ? <p role="status">جارٍ تحميل الملف…</p> : donationId ? event.error ? <RequestState error={event.error} retry={event.retry} /> : !event.data ? <p role="status">جارٍ تحميل التبرع…</p> : <><div className={styles.heading}><div><p className={styles.eyebrow}>واقعة تبرع · {detail.data.name}</p><h2><bdi>{event.data.donation_code}</bdi></h2></div>{caps.donations_update && event.data.status === "pending" && !event.data.voided_at && <button className={styles.primary} onClick={() => setDonationEditor({ donation: event.data })}><LuSquarePen aria-hidden="true" />تعديل التبرع</button>}{exports}</div><section className={styles.detailPanel}><dl className={styles.facts}><div><dt>تاريخ التبرع الفعلي</dt><dd>{event.data.donated_on}</dd></div><div><dt>الزمرة</dt><dd>{bloodLabel(event.data.blood_group, event.data.rh)}</dd></div><div><dt>عدد الوحدات</dt><dd>{event.data.units}</dd></div><div><dt>الحالة</dt><dd>{donationStatus(event.data)}</dd></div></dl><p className={styles.hint}>تصحيح التاريخ يحدّث الكود، مع إبقاء الكود السابق مرجعًا لهذه الواقعة نفسها.</p></section></> : <><div className={styles.heading}><div><p className={styles.eyebrow}>ملف {kindName(kind!)} · <bdi>{detail.data.code}</bdi></p><h2>{detail.data.name}</h2></div>{caps.update && <button className={styles.primary} onClick={() => setEditor({ kind: kind!, profile: detail.data })}><LuSquarePen aria-hidden="true" />تعديل الملف</button>}{exports}</div><ProfileDetails profile={detail.data} options={options.data} />{kind === "donor" && <Donations donorId={Number(id)} facilityId={facilityId} query={encoded} revision={revision} canCreate={caps.donations_create} onCreate={() => setDonationEditor({})} />}</>}
      </>}
    {exportError && <p role="alert" className={styles.error}>{exportError}</p>}
    {error && <p role="alert" className={styles.error}>{error}</p>}
    {opening && <p role="status">جارٍ فتح أحدث نسخة…</p>}
    {editor && <ProfileEditor kind={editor.kind} profile={editor.profile} facilityId={facilityId} options={options.data} onClose={() => setEditor(null)} onRefresh={refresh} onSaved={row => { setEditor(null); refresh(); if (!editor.profile) router.push(`/blood-bank/${row.kind}/${row.id}?${encoded}`); }} />}
    {donationEditor && id && <DonationEditor donorId={Number(id)} donation={donationEditor.donation} facilityId={facilityId} onClose={() => setDonationEditor(null)} onRefresh={refresh} onSaved={() => { setDonationEditor(null); refresh(); }} />}
  </>;
}

function RequestState({ error, retry }: { error: string; retry: () => void }) { return <div className={styles.status}><p role="alert">{error}</p><button className={styles.secondary} onClick={retry}>إعادة المحاولة</button></div>; }
function donationStatus(d: Donation) { return d.voided_at ? "ملغى" : ({ pending: "بانتظار المراجعة", accepted: "مقبول", rejected: "مرفوض" }[d.status] ?? "حالة تاريخية"); }

function ProfileDetails({ profile: p, options }: { profile: Profile; options: Options }) {

  return <><section className={styles.detailPanel}><h3>البيانات الشخصية</h3>{p.patient_id && <p className={styles.hint}>مرتبطة بالمريض <bdi>{p.patient_code}</bdi>؛ تُقرأ من ملفه دون نسخ.</p>}<dl className={styles.facts}>{Object.entries(personLabels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{key === "governorate_id" ? p.governorate_name ?? "غير مسجل" : key === "city_id" ? p.city_name ?? "غير مسجل" : choices[key]?.[String(p.person[key])] ?? p.person[key] ?? "غير مسجل"}</dd></div>)}{p.kind === "recipient" && <div><dt>جهة المستفيد</dt><dd>{p.beneficiary_entity || "غير مسجلة"}</dd></div>}<div><dt>العيادة</dt><dd>{p.clinic_name || "غير مسجلة"}</dd></div><div><dt>الطبيب المسؤول</dt><dd>{p.doctor_name || "غير مسجل"}</dd></div><div><dt>الزمرة</dt><dd>{bloodLabel(p.blood_group, p.rh)}</dd></div><div><dt>المكوّن</dt><dd>{options.blood_components.find(c => c.id === p.blood_component_id)?.name_ar || p.component_name || "غير مسجل"}</dd></div></dl></section><section className={styles.panel}><div className={styles.toolbar}><h3>فحوص الملف</h3></div><DirectoryTable label="فحوص ملف بنك الدم" headers={["الفحص", "الطريقة", "الحالة", "النتيجة"]}>{p.screenings.map(s => <tr key={s.analyte}><td><bdi>{s.analyte}</bdi></td><td>{options.screening_tests.find(t => t.id === s.screening_test_id)?.name_ar || "غير محددة"}</td><td>{statuses[s.status]}</td><td>{s.result ? results[s.result] : "لا توجد نتيجة"}</td></tr>)}</DirectoryTable><p className={styles.hint}>هذه فحوص الملف؛ لا تُنسخ نتائجها إلى التبرعات ولا تدل وحدها على قبول التبرع.</p></section></>;
}

function Donations({ donorId, facilityId, query, revision, canCreate, onCreate }: { donorId: number; facilityId: number; query: string; revision: number; canCreate: boolean; onCreate: () => void }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  // Namespace the donation table state so returning to the profile preserves the file list filters.
  const donationParams = new URLSearchParams(); donationParams.set("facility_id", String(facilityId));
  for (const key of ["search", "page", "per_page"]) { const value = params.get(`donation_${key}`); if (value) donationParams.set(key, value); }
  const { search, change, cancel } = useClinicSearch(pathname, params.toString(), facilityId, "donation_");
  function update(key: string, value: string) { cancel(); const next = new URLSearchParams(params.toString()); next.set("facility_id", String(facilityId)); if (search) next.set("donation_search", search); else next.delete("donation_search"); if (value) next.set(`donation_${key}`, value); else next.delete(`donation_${key}`); if (key !== "page") next.delete("donation_page"); router.replace(`${pathname}?${next}`, { scroll: false }); }
  const list = useBloodRequest<Page<Donation>>(`blood-bank/donor/${donorId}/donations?${donationParams}`, true, true, revision);
  const links = new URLSearchParams(query); for (const key of ["search", "page", "per_page"]) { const v = params.get(`donation_${key}`); if (v) links.set(`donation_${key}`, v); }
  return <section className={styles.panel} aria-label="تبرعات المتبرع"><div className={styles.toolbar}><h3>وقائع التبرع</h3>{canCreate && <button className={styles.primary} onClick={onCreate}><LuPlus aria-hidden="true" />تسجيل تبرع</button>}</div><div className={styles.toolbar}><label className={styles.search}><span>البحث بكود التبرع الحالي أو السابق</span><input type="search" value={search} onChange={e => change(e.target.value)} /></label></div>{list.error && <RequestState error={list.error} retry={list.retry} />}{list.loading && <p role="status">جارٍ تحديث التبرعات…</p>}{list.data && <><DirectoryTable label="جدول وقائع التبرع" busy={list.loading} headers={["كود التبرع", "التاريخ الفعلي", "الزمرة", "الوحدات", "الحالة", "الإجراءات"]}>{list.data.data.map(d => <tr key={d.id}><td><bdi>{d.donation_code}</bdi></td><td>{d.donated_on}</td><td>{bloodLabel(d.blood_group, d.rh)}</td><td>{d.units}</td><td>{donationStatus(d)}</td><td><DirectoryRowActions name={d.donation_code} href={`/blood-bank/donor/${donorId}/donations/${d.id}?${links}`} /></td></tr>)}{!list.data.data.length && <tr><td colSpan={6}><div className={styles.status}>لا توجد تبرعات مطابقة. ملف المتبرع مستقل عن تسجيل التبرع.</div></td></tr>}</DirectoryTable><Pagination meta={list.data.meta} onPage={p => update("page", String(p))} onPageSize={p => update("per_page", p)} /></>}</section>;
}
