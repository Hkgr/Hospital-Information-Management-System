"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { LuChartNoAxesCombined, LuHospital } from "react-icons/lu";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import { useClinicRequest } from "../clinics/api";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import { BarChart, CounterCard, DonutChart, RankTable, Reveal } from "../dashboards/HomeVisuals";
import { downloadReportPdf, isFacilityReport, reportsPath, type FacilityReport, type NamedCount } from "./api";
import dash from "../dashboards/dashboard.module.css";
import clinic from "../clinics/clinics.module.css";
import styles from "./reports.module.css";

const periods = [
  { key: "day", label: "يوم" },
  { key: "week", label: "أسبوع" },
  { key: "custom", label: "فترة مخصصة" },
] as const;

export default function ReportsScreen() {
  const { access } = useIdentity();
  const params = useSearchParams();
  const pathname = usePathname();
  const router = useRouter();
  const { entry, allowed } = directoryFacility(access, null, params.get("facility_id"));
  const period = periods.some(item => item.key === params.get("period")) ? params.get("period")! : "day";
  const from = params.get("from") ?? "";
  const to = params.get("to") ?? "";
  const ready = !!entry && (period !== "custom" || (!!from && !!to));
  const path = ready && entry ? reportsPath(entry.facility.id, period, from, to) : null;
  const report = useClinicRequest<FacilityReport>(path, false);
  if (!entry) return <section className={clinic.status}><h2>التقارير غير متاحة</h2><p role="alert">{allowed.length ? "معرّف المنشأة غير صالح ضمن المنشآت المتاحة لك." : "لا منشأة مرتبطة بهذا الدخول لعرض التقارير."}</p></section>;
  function setQuery(next: Record<string, string>) {
    const q = new URLSearchParams(params.toString());
    q.set("facility_id", String(entry.facility.id));
    for (const [key, value] of Object.entries(next)) {
      if (value) q.set(key, value); else q.delete(key);
    }
    router.replace(`${pathname}?${q}`, { scroll: false });
  }
  return <div className={styles.screen}>
    <Reveal>
      <section className={dash.hero} aria-labelledby="reports-heading">
        <div className={dash.heroCopy}>
          <p className={dash.eyebrow}><LuChartNoAxesCombined aria-hidden="true" /> تقارير</p>
          <h2 id="reports-heading">نشاط المنشأة حسب الفترة</h2>
          <p>اختر يومًا أو أسبوعًا أو فترة مخصصة. الأرقام تُحتسب من تواريخ الوقائع داخل المنشأة، حسب صلاحياتك.</p>
        </div>
        <div className={dash.heroMark} aria-hidden="true"><LuChartNoAxesCombined /></div>
      </section>
    </Reveal>
    <div className={clinic.context}><LuHospital aria-hidden="true" /><span>المشفى</span><strong>{entry.facility.name_ar}</strong></div>
    {allowed.length > 1 && <label className={clinic.filters}>المنشأة<select aria-label="المنشأة" value={String(entry.facility.id)} onChange={e => setQuery({ facility_id: e.target.value })}>{allowed.map(item => <option key={item.facility.id} value={item.facility.id}>{item.facility.name_ar}</option>)}</select></label>}
    <Reveal delay={0.04}>
      <section className={styles.controls} aria-label="نطاق التقرير">
        <div role="radiogroup" aria-label="الفترة الزمنية" className={styles.switch}>
          {periods.map(item => <button key={item.key} type="button" role="radio" aria-checked={period === item.key}
            onClick={() => setQuery({ period: item.key, ...(item.key === "custom" ? { from, to } : { from: "", to: "" }) })}>{item.label}</button>)}
        </div>
        {period === "custom" && <div className={styles.custom}>
          <label>من تاريخ<input type="date" value={from} onChange={e => setQuery({ period: "custom", from: e.target.value, to })} /></label>
          <label>إلى تاريخ<input type="date" value={to} onChange={e => setQuery({ period: "custom", from, to: e.target.value })} /></label>
        </div>}
        <ExportButton path={path} ready={!!path && !!report.data && !report.loading && !report.error} />
      </section>
    </Reveal>
    {path && report.loading && <p className={dash.loading} role="status">جارٍ تحميل التقرير…</p>}
    {report.error && <div className={dash.status}><p role="alert">{report.error}</p><button type="button" onClick={report.retry}>إعادة المحاولة</button></div>}
    {!path && period === "custom" && <p className={styles.hint}>حدد تاريخ البداية والنهاية لعرض الفترة المخصصة.</p>}
    {report.data && isFacilityReport(report.data) && <ReportBody data={report.data} />}
  </div>;
}

function ReportBody({ data }: { data: FacilityReport }) {
  const empty = !data.counters.length && !data.clinics.length && !data.doctors.length;
  return <div className={dash.overview}>
    <p className={styles.range}>{data.period.label}</p>
    {empty && <p className={styles.hint}>لا توجد مؤشرات متاحة لصلاحياتك في هذه المنشأة خلال الفترة المحددة.</p>}
    {data.counters.length > 0 && <Reveal delay={0.06}><section className={dash.counters} aria-label="مؤشرات الفترة">
      {data.counters.map((item, index) => <CounterCard key={item.key} item={item} index={index} />)}
    </section></Reveal>}
    {data.series.length > 1 && <Reveal delay={0.08}><section className={dash.panel} aria-labelledby="series-heading">
      <h2 id="series-heading">الزيارات حسب اليوم</h2>
      <ActivityChart title="الزيارات حسب اليوم" items={data.series} />
    </section></Reveal>}
    {(data.visit_status.length > 0 || data.mix.length > 0) && <Reveal delay={0.1}>
      <div className={dash.chartGrid}>
        {data.visit_status.length > 0 && <section className={dash.panel} aria-labelledby="visit-status-heading">
          <h2 id="visit-status-heading" className={dash.visuallyHidden}>توزيع الزيارات</h2>
          <DonutChart title="توزيع الزيارات" items={data.visit_status} />
        </section>}
        {data.mix.length > 0 && <section className={dash.panel} aria-labelledby="mix-heading">
          <h2 id="mix-heading" className={dash.visuallyHidden}>مزيج النشاط</h2>
          <DonutChart title="مزيج النشاط" items={data.mix} />
        </section>}
      </div>
    </Reveal>}
    {data.clinics.length > 0 && <Reveal delay={0.12}><section className={dash.panel} aria-labelledby="clinic-bars-heading">
      <h2 id="clinic-bars-heading">العيادات الأكثر نشاطًا</h2>
      <BarChart title="العيادات الأكثر نشاطًا" items={data.clinics} hrefFor={id => `/clinics/${id}`} />
    </section></Reveal>}
    {(data.clinics.length > 0 || data.doctors.length > 0 || data.procedures.length > 0) && <Reveal delay={0.14}><div className={dash.tableGrid}>
      {data.clinics.length > 0 && <RankTable title="ترتيب العيادات" items={data.clinics} hrefFor={id => `/clinics/${id}`} countLabel="الزيارات المكتملة" />}
      {data.doctors.length > 0 && <RankTable title="الأطباء الأكثر نشاطًا" items={data.doctors} hrefFor={id => `/doctors/${id}`} countLabel="الزيارات المكتملة" />}
      {data.procedures.length > 0 && <RankTable title="الإجراءات الأكثر تنفيذًا" items={data.procedures} countLabel="عدد الإجراءات" />}
    </div></Reveal>}
    {data.patients.length > 0 && <Reveal delay={0.16}><section className={dash.panel} aria-labelledby="patients-heading">
      <h2 id="patients-heading">جدول المرضى</h2>
      <p className={styles.hint}>{data.patients_definition}</p>
      <DirectoryTable label="جدول المرضى" headers={["كود المريض", "اسم المريض", "عدد الزيارات", "آخر زيارة"]}>
        {data.patients.map(row => <tr key={row.id}>
          <td><bdi>{row.patient_code}</bdi></td>
          <td>{row.dossier_id ? <Link href={`/patient-cards/${row.dossier_id}`}>{row.patient_name}</Link> : row.patient_name}</td>
          <td>{row.visit_count.toLocaleString("ar-SY")}</td>
          <td><bdi>{row.last_visit_on}</bdi></td>
        </tr>)}
      </DirectoryTable>
    </section></Reveal>}
  </div>;
}

function ActivityChart({ title, items }: { title: string; items: NamedCount[] }) {
  const max = Math.max(1, ...items.map(item => item.value));
  return <div className={styles.spark} role="img" aria-label={title}>
    {items.map(item => <div key={item.key} className={styles.sparkCol}>
      <strong>{item.value.toLocaleString("ar-SY")}</strong>
      <span className={styles.sparkTrack}><span className={styles.sparkBar} style={{ height: `${(item.value / max) * 100}%` }} /></span>
      <span>{item.label.slice(5)}</span>
    </div>)}
  </div>;
}

function ExportButton({ path, ready }: { path: string | null; ready: boolean }) {
  const pending = useRef<AbortController | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useEffect(() => () => pending.current?.abort(), [path]);
  async function download() {
    if (!path || !ready || pending.current) return;
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true); setError("");
    try { await downloadReportPdf(path, controller.signal); }
    catch (reason) { if (!controller.signal.aborted) setError(reason instanceof Error ? reason.message : "تعذّر تصدير PDF."); }
    finally { if (pending.current === controller) pending.current = null; setBusy(false); }
  }
  return <div className={styles.export}>
    <button type="button" className={clinic.primary} disabled={!ready || busy} onClick={() => void download()}>{busy ? "جارٍ إنشاء PDF…" : "تصدير PDF"}</button>
    {error && <p role="alert">{error}</p>}
  </div>;
}
