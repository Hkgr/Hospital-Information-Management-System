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
import { type Choice, type Kind, type Options, bloodLabel, choices, personLabels, statuses, results, useBloodRequest } from "./api";
import { type BloodEvent, type EventKind, type EventPage, type Person, type Totals, eventLabel, unitLabel } from "./events";
import EventEditor from "./EventEditor";
import PersonEditor from "./PersonEditor";
import Picker from "./Picker";
import styles from "../clinics/clinics.module.css";

export default function EventScreen({ personId, eventId, legacy }: { personId?: string; eventId?: string; legacy?: { kind: Kind; id: string; donationId?: string } }) {
  const { access, user } = useIdentity(); const query = useSearchParams();
  const { entry } = directoryFacility(access, "blood_bank.view", query.get("facility_id"));
  if (!entry || [personId, eventId, legacy?.id, legacy?.donationId].some(id => id && !/^[1-9]\d*$/.test(id))) return <section className={styles.status}><h2>بنك الدم غير متاح</h2><p role="alert">تعذّر تحديد مشفى مصرح لك بالوصول إليه. راجع مسؤول الصلاحيات.</p></section>;
  return <div className={styles.screen}><div className={styles.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>{legacy ? <Legacy key={`${user.id}:${entry.facility.id}:${legacy.id}:${legacy.donationId}`} facilityId={entry.facility.id} legacy={legacy} /> : <Workspace key={`${user.id}:${entry.facility.id}:${personId}:${eventId}`} facilityId={entry.facility.id} personId={personId} eventId={eventId} />}</div>;
}
function Legacy({ facilityId, legacy }: { facilityId: number; legacy: { kind: Kind; id: string; donationId?: string } }) {
  const router = useRouter(); const params = useSearchParams();
  const link = useBloodRequest<{ person_id: number; event_id: number | null }>(`blood-bank/legacy/${legacy.kind}/${legacy.id}${legacy.donationId ? `/donations/${legacy.donationId}` : ""}?facility_id=${facilityId}`);
  useEffect(() => { if (link.data) { const q = new URLSearchParams(params.toString()); q.set("facility_id", String(facilityId)); router.replace(`/blood-bank/${link.data.event_id ? `events/${link.data.event_id}` : `people/${link.data.person_id}`}?${q}`); } }, [link.data, facilityId, params, router]);
  return link.error ? <RequestState error={link.error} retry={link.retry} /> : <p role="status">جارٍ فتح المرجع المحفوظ…</p>;
}
function Workspace({ facilityId, personId, eventId }: { facilityId: number; personId?: string; eventId?: string }) {
  const router = useRouter(); const pathname = usePathname(); const params = useSearchParams();
  const [revision, setRevision] = useState(0); const refresh = () => setRevision(v => v + 1);
  const [editor, setEditor] = useState<{ kind: EventKind; event?: BloodEvent } | null>(null); const [personEditor, setPersonEditor] = useState(false);
  const [opening, setOpening] = useState(false); const [error, setError] = useState("");
  const controller = useRef<AbortController | null>(null); const exportController = useRef<AbortController | null>(null); const [exporting, setExporting] = useState(false);
  useEffect(() => () => controller.current?.abort(), []);
  const options = useBloodRequest<Options>(`blood-bank/options?facility_id=${facilityId}`);
  const { search, committed, change, cancel, searching } = useClinicSearch(pathname, params.toString(), facilityId);
  const query = new URLSearchParams({ facility_id: String(facilityId) });
  for (const key of ["kind", "benefit_kind", "from", "to", "blood_component_id", "blood_group", "rh", "clinic_id", "sort", "direction", "page", "per_page"]) { const value = params.get(key); if (value) query.set(key, value); }
  if (committed) query.set("search", committed);
  const encoded = query.toString(); const eventQuery = new URLSearchParams(encoded); if (personId) eventQuery.set("person_id", personId);
  const list = useBloodRequest<EventPage>(eventId ? null : `blood-bank/events?${eventQuery}`, true, true, revision);
  const person = useBloodRequest<Person>(personId ? `blood-bank/people/${personId}?facility_id=${facilityId}` : null, false, false, revision);
  const detail = useBloodRequest<BloodEvent>(eventId ? `blood-bank/events/${eventId}?facility_id=${facilityId}` : null, false, false, revision);
  const [filterClinic, setFilterClinic] = useState<Choice | null>(null);
  const ready = eventId ? !!detail.data && !detail.loading && !detail.error : personId ? !!person.data && !person.loading && !person.error : !searching && !!list.data && !list.loading && !list.error;
  useEffect(() => () => { exportController.current?.abort(); exportController.current = null; }, [encoded, revision]);
  async function exportFile(format: "pdf" | "xlsx") {
    if (!ready || !options.data?.capabilities.export || exportController.current) return;
    const c = new AbortController(); exportController.current = c; setExporting(true); setError("");
    const path = eventId ? `blood-bank/events/${eventId}/report/${format}?facility_id=${facilityId}` : personId ? `blood-bank/people/${personId}/report/${format}?facility_id=${facilityId}` : `blood-bank/events/export/${format}?${encoded}`;
    try { await downloadReport(path, c.signal); } catch (e) { if (!c.signal.aborted) setError(e instanceof Error ? e.message : "تعذّر التصدير؛ أعد المحاولة."); }
    finally { if (exportController.current === c) exportController.current = null; if (!exportController.current) setExporting(false); }
  }
  function filter(key: string, value: string) { cancel(); const q = new URLSearchParams(encoded); if (search) q.set("search", search); else q.delete("search"); if (value) q.set(key, value); else q.delete(key); if (key !== "page") q.delete("page"); router.replace(`${pathname}?${q}`, { scroll: false }); }
  async function edit(row: BloodEvent) {
    if (controller.current) return; const c = new AbortController(); controller.current = c; setOpening(true); setError("");
    try { const value = await apiRequest<BloodEvent>(`blood-bank/events/${row.id}?facility_id=${facilityId}`, { signal: c.signal }); if (!c.signal.aborted) setEditor({ kind: value.kind, event: value }); }
    catch { if (!c.signal.aborted) setError("تعذّر فتح أحدث نسخة؛ أعد المحاولة."); }
    finally { if (!c.signal.aborted) { controller.current = null; setOpening(false); } }
  }
  if (options.error) return <RequestState error={options.error} retry={options.retry} />;
  if (!options.data) return <p role="status">جارٍ تحديد الوصول…</p>;
  const caps = options.data.capabilities;
  const canEdit = (e: BloodEvent) => !e.voided_at && (e.kind === "donation" ? caps.donations_update && e.status === "pending" : caps.benefits_update);
  const exports = caps.export && <div className={styles.actions}><button className={styles.secondary} disabled={!ready || exporting} onClick={() => void exportFile("xlsx")}><LuDownload aria-hidden="true" />Excel</button><button className={styles.secondary} disabled={!ready || exporting} onClick={() => void exportFile("pdf")}><LuFileText aria-hidden="true" />PDF</button>{exporting && <span role="status">جارٍ إعداد التقرير…</span>}</div>;
  const register = <div className={styles.actions}>{(["donation", "benefit"] as EventKind[]).map(kind => (kind === "donation" ? caps.donations_create : caps.benefits_create) && <button key={kind} className={styles.primary} disabled={!!personId && (!person.data || person.loading || !person.data.is_active)} onClick={() => setEditor({ kind })}><LuPlus aria-hidden="true" />تسجيل {kind === "donation" ? "تبرع" : "استفادة"}</button>)}</div>;
  return <>
    {(personId || eventId) && <DirectoryBack href={`/blood-bank?${encoded}`}>العودة إلى سجل بنك الدم</DirectoryBack>}
    {eventId ? <>{detail.error ? <RequestState error={detail.error} retry={detail.retry} /> : !detail.data ? <p role="status">جارٍ تحميل الواقعة…</p> : <><div className={styles.heading}><div><p className={styles.eyebrow}>{eventLabel(detail.data)}</p><h2><bdi>{detail.data.code}</bdi></h2><Link href={`/blood-bank/people/${detail.data.person_id}?${encoded}`}>ملف الشخص: {detail.data.name}</Link></div><div className={styles.actions}>{canEdit(detail.data) && <button className={styles.primary} onClick={() => setEditor({ kind: detail.data!.kind, event: detail.data! })}><LuSquarePen aria-hidden="true" />تعديل الواقعة</button>}{exports}</div></div><EventDetails event={detail.data} query={encoded} /></>}</> : <>
      {personId ? person.error ? <RequestState error={person.error} retry={person.retry} /> : !person.data ? <p role="status">جارٍ تحميل الشخص…</p> : <><div className={styles.heading}><div><p className={styles.eyebrow}>ملف الشخص · <bdi>{person.data.code}</bdi></p><h2>{person.data.name}</h2></div><div className={styles.actions}>{caps.update && <button className={styles.secondary} onClick={() => setPersonEditor(true)}><LuSquarePen aria-hidden="true" />تعديل بيانات الشخص</button>}{exports}</div></div><PersonDetails person={person.data} /><TotalsView totals={person.data.totals} />{register}</> : <div className={styles.heading}><div><p className={styles.eyebrow}>بنك الدم</p><h2>سجل التبرع والاستفادة</h2><p>شخص واحد ووقائع متعددة؛ الصرف والنقل المرتبط به مرحلتان لاستفادة واحدة.</p></div>{register}</div>}
      <section className={styles.panel} aria-label="سجل بنك الدم"><div className={styles.toolbar}><label className={styles.search}><span><LuSearch aria-hidden="true" />البحث في بنك الدم</span><input type="search" placeholder="الاسم أو كود الشخص أو المريض أو الواقعة…" value={search} onChange={e => change(e.target.value)} /></label>{!personId && exports}</div>
        <div className={styles.filters}><label>نوع الواقعة<select value={query.get("kind") ?? ""} onChange={e => filter("kind", e.target.value)}><option value="">الكل</option><option value="donation">تبرع</option><option value="benefit">استفادة</option></select></label><label>نوع الاستفادة<select value={query.get("benefit_kind") ?? ""} onChange={e => filter("benefit_kind", e.target.value)}><option value="">الكل</option><option value="issue">استلام/صرف</option><option value="transfusion">نقل فعلي</option></select></label>
          <label>من تاريخ<input type="date" value={query.get("from") ?? ""} onChange={e => filter("from", e.target.value)} /></label><label>إلى تاريخ<input type="date" value={query.get("to") ?? ""} onChange={e => filter("to", e.target.value)} /></label>
          <label>المكوّن<select value={query.get("blood_component_id") ?? ""} onChange={e => filter("blood_component_id", e.target.value)}><option value="">الكل</option>{options.data.blood_components.map(c => <option key={c.id} value={c.id}>{c.name_ar}</option>)}</select></label><label>ABO<select value={query.get("blood_group") ?? ""} onChange={e => filter("blood_group", e.target.value)}><option value="">الكل</option>{["A", "B", "AB", "O"].map(g => <option key={g}>{g}</option>)}</select></label><label>Rh<select value={query.get("rh") ?? ""} onChange={e => filter("rh", e.target.value)}><option value="">الكل</option>{Object.entries(choices.rh).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select></label>
          <label>الترتيب<select value={query.get("sort") ?? "occurred_on"} onChange={e => filter("sort", e.target.value)}><option value="occurred_on">التاريخ الفعلي</option><option value="code">كود الواقعة</option><option value="name">الاسم</option></select></label><label>الاتجاه<select value={query.get("direction") ?? "desc"} onChange={e => filter("direction", e.target.value)}><option value="desc">تنازلي</option><option value="asc">تصاعدي</option></select></label>
        </div>
        <details className={styles.filterDisclosure}><summary>فلتر العيادة{query.get("clinic_id") ? " · مفعّل" : ""}</summary><Picker label="العيادة للفلترة" path={`blood-bank/clinics?facility_id=${facilityId}`} selected={String(filterClinic?.id) === query.get("clinic_id") ? filterClinic : null} onSelect={c => { setFilterClinic(c); filter("clinic_id", String(c.id)); }} />{query.get("clinic_id") && <button className={styles.secondary} onClick={() => filter("clinic_id", "")}>إلغاء فلتر العيادة</button>}</details>
        {list.error && <RequestState error={`${list.error}${list.data ? " المعروض نتائج سابقة؛ التصدير يتطلب نجاح إعادة التحميل." : ""}`} retry={list.retry} />}{list.loading && <p role="status">{list.data ? "جارٍ تحديث النتائج…" : "جارٍ تحميل السجل…"}</p>}{searching && <p role="status">جارٍ اعتماد البحث…</p>}
        {list.data && <><TotalsView totals={list.data.totals} /><DirectoryTable label="جدول وقائع بنك الدم" busy={list.loading || searching} headers={["الكود والتاريخ", "النوع", "الشخص", "الزمرة", "المكوّن", "الكمية", "العيادة والطبيب", "الإجراءات"]}>{list.data.data.map(row => <tr key={row.id}><td><Link className={styles.code} href={`/blood-bank/events/${row.id}?${encoded}`}>{row.code}</Link><p>{row.occurred_on}</p></td><td><span className={styles.badge}>{eventLabel(row)}</span>{row.issue_event_id && <small>استكمال صرف #{row.issue_event_id}</small>}{row.voided_at && <small>ملغاة · خارج الإجماليات</small>}</td><td><Link href={`/blood-bank/people/${row.person_id}?${encoded}`}><strong>{row.name}</strong></Link><p><bdi>{row.person_code}</bdi>{row.patient_code && <> · <bdi>{row.patient_code}</bdi></>}</p></td><td>{bloodLabel(row.blood_group, row.rh)}</td><td>{row.component_name ?? "غير مسجل"}</td><td><bdi>{row.quantity}</bdi> {unitLabel(row.quantity_unit)}</td><td>{row.clinic_name ?? "غير مسجلة"}<p>{row.doctor_name ?? "غير مسجل"}</p></td><td><DirectoryRowActions name={row.code} href={`/blood-bank/events/${row.id}?${encoded}`} disabled={opening || list.loading} onEdit={canEdit(row) ? () => void edit(row) : undefined} /></td></tr>)}{!list.data.data.length && <tr><td colSpan={8}><div className={styles.status}>لا توجد وقائع مطابقة. الملفات القديمة دون واقعة لا تدخل في هذا السجل.</div></td></tr>}</DirectoryTable><Pagination meta={list.data.meta} onPage={p => filter("page", String(p))} onPageSize={p => filter("per_page", p)} /></>}
      </section>
    </>}
    {error && <p className={styles.error} role="alert">{error}</p>}{opening && <p role="status">جارٍ فتح أحدث نسخة…</p>}
    {editor && <EventEditor kind={editor.kind} event={editor.event} selectedPerson={personId ? person.data : undefined} facilityId={facilityId} options={options.data} onClose={() => setEditor(null)} onRefresh={refresh} onSaved={row => { setEditor(null); refresh(); if (!editor.event) router.push(`/blood-bank/events/${row.id}?${encoded}`); }} />}
    {personEditor && person.data && <PersonEditor person={person.data} facilityId={facilityId} options={options.data} onClose={() => setPersonEditor(false)} onRefresh={refresh} onSaved={() => { setPersonEditor(false); refresh(); }} />}
  </>;
}
function RequestState({ error, retry }: { error: string; retry: () => void }) { return <div className={styles.status}><p role="alert">{error}</p><button className={styles.secondary} onClick={retry}>إعادة المحاولة</button></div>; }
function TotalsView({ totals }: { totals: Totals }) { return <div className={styles.resultSummary}><strong>{totals.donations} تبرع</strong><strong>{totals.benefits} استفادة</strong><strong>{totals.unique_people} شخص فريد</strong></div>; }
function PersonDetails({ person: p }: { person: Person }) {
  return <section className={styles.detailPanel}><h3>بيانات الشخص الحالية</h3>{p.patient_id && <p>مرتبطة بالمريض <bdi>{p.patient_code}</bdi> وتُقرأ دون نسخ.</p>}<dl className={styles.facts}>{Object.entries(personLabels).map(([key, label]) => <div key={key}><dt>{label}</dt><dd>{key === "governorate_id" ? p.governorate_name ?? "غير مسجل" : key === "city_id" ? p.city_name ?? "غير مسجل" : choices[key]?.[String(p.person[key])] ?? p.person[key] ?? "غير مسجل"}</dd></div>)}<div><dt>الزمرة الحالية</dt><dd>{bloodLabel(p.blood_group, p.rh)}</dd></div></dl>{p.aliases.length > 0 && <p>الأكواد السابقة: <bdi>{p.aliases.join(" · ")}</bdi></p>}{p.legacy_screenings.length > 0 && <details><summary>فحوص ملف قديم غير منسوبة إلى واقعة</summary><p>تبقى محفوظة دون استنتاج تاريخ واقعة أو نتيجة جديدة.</p><DirectoryTable label="فحوص تاريخية للملف" headers={["الفحص", "الحالة", "النتيجة المحفوظة"]}>{p.legacy_screenings.map((s, i) => <tr key={i}><td>{s.analyte}</td><td>{statuses[s.status]}</td><td>{s.result ? results[s.result] ?? s.result : "غير مسجلة"}</td></tr>)}</DirectoryTable></details>}</section>;
}
function EventDetails({ event: e, query }: { event: BloodEvent; query: string }) {
  return <><section className={styles.detailPanel}><dl className={styles.facts}>{Object.entries({ "نوع الواقعة": eventLabel(e), "التاريخ الفعلي": e.occurred_on, "الكمية": `${e.quantity} ${unitLabel(e.quantity_unit)}`, "الزمرة المثبتة للواقعة": bloodLabel(e.blood_group, e.rh), "المكوّن": e.component_name, "العيادة": e.clinic_name, "الطبيب المسؤول": e.doctor_name, ...(e.kind === "benefit" ? { "جهة المستفيد": e.beneficiary_entity, "عنوان الجهة": e.entity_address } : {}), "حالة الواقعة": e.voided_at ? "ملغاة" : e.status === "pending" ? "بانتظار المراجعة" : e.status === "accepted" ? "مقبولة" : e.status === "rejected" ? "مرفوضة" : "مسجلة" }).map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || "غير مسجل"}</dd></div>)}</dl>{e.issue_event_id && <p><Link href={`/blood-bank/events/${e.issue_event_id}?${query}`}>الصرف السابق المرتبط</Link> · مرحلتان لاستفادة واحدة.</p>}{e.linked_transfusion_id && <p><Link href={`/blood-bank/events/${e.linked_transfusion_id}?${query}`}>النقل الفعلي المرتبط</Link></p>}{e.legacy_address && <p>عنوان تاريخي غير محدد الدلالة: {e.legacy_address}</p>}<p>الأكواد الحالية والسابقة: <bdi>{e.aliases.join(" · ")}</bdi></p><p className={styles.hint}>تصحيح التاريخ يحفظ الكود السابق كمرجع للواقعة نفسها.</p></section><section className={styles.panel}><div className={styles.toolbar}><h3>فحوص الواقعة</h3></div><DirectoryTable label="فحوص الواقعة" headers={["الفحص", "الحالة", "النتيجة السابقة المحفوظة"]}>{e.screenings.map(s => <tr key={s.analyte}><td>{s.analyte}</td><td>{statuses[s.status]}</td><td>{s.result ? results[s.result] ?? s.result : "غير مسجلة"}</td></tr>)}{!e.screenings.length && <tr><td colSpan={3}>لم تُضف فحوص.</td></tr>}</DirectoryTable>{e.legacy_screenings.length > 0 && <p>مراجع فحوص التبرع التاريخية المثبتة: {e.legacy_screenings.map(s => `${s.name_ar} (${s.tested_on ?? "دون تاريخ"})`).join(" · ")}</p>}</section></>;
}
