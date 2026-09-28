"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { apiRequest } from "../auth/api";
import { useClinicRequest } from "../clinics/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import styles from "../clinics/clinics.module.css";
import reportStyles from "../reports/reports.module.css";
import statisticsStyles from "./statistics.module.css";

type Section = { key: string; title: string; definition: string; suppressed: boolean; patients: number | null; events: number | null; rows: { label: string; patients: number; events: number }[] };
type Data = { title: string; facility: { name_ar: string; timezone: string }; filters: { from_month: string; to_month: string }; privacy: { policy: string }; occupancy: { value: null; reason: string }; months: { month: string; sections: Section[] }[] };

export default function StatisticsScreen() {
  const identity = useIdentity(), params = useSearchParams();
  const { entry } = directoryFacility(identity.access, "statistics.view", params.get("facility_id"));
  if (!entry) return <section className={styles.panel}><h2>الإحصاءات المجهلة</h2><p role="alert">لا تتوفر صلاحية الإحصاء في المنشأة المطلوبة. يمكنك تسجيل الخروج من قائمة الحساب.</p></section>;
  // Default is merely a UI convenience; Laravel validates closed months in facility time.
  const parts = new Intl.DateTimeFormat("en", { timeZone: entry.facility.timezone, year: "numeric", month: "numeric" }).formatToParts(new Date());
  const previous = new Date(Date.UTC(Number(parts.find(p => p.type === "year")?.value), Number(parts.find(p => p.type === "month")?.value) - 2, 1)).toISOString().slice(0, 7);
  return <><div className={styles.context}><strong>{entry.facility.name_ar}</strong></div><Workspace key={`${entry.facility.id}:${params.toString()}`} facility={entry.facility.id} from={params.get("from_month") || previous} to={params.get("to_month") || previous} canExport={entry.permissions.includes("statistics.export")} /></>;
}

function Workspace({ facility, from, to, canExport }: { facility: number; from: string; to: string; canExport: boolean }) {
  const router = useRouter();
  const [start, setStart] = useState(from), [end, setEnd] = useState(to), [exporting, setExporting] = useState(false), [error, setError] = useState("");
  const pending = useRef(false);
  const controller = useRef<AbortController | null>(null);
  useEffect(() => { const value = new AbortController(); controller.current = value; return () => value.abort(); }, []);
  const query = new URLSearchParams({ facility_id: String(facility), from_month: from, to_month: to }).toString();
  const result = useClinicRequest<Data>(`statistics?${query}`);
  const ready = canExport && !result.loading && !result.error && !!result.data && start === from && end === to;
  async function download(format: "pdf" | "xlsx") {
    if (!ready || pending.current) return;
    pending.current = true; setExporting(true); setError("");
    try {
      const signal = controller.current?.signal;
      const { blob, filename } = await apiRequest<{ blob: Blob; filename?: string }>(`statistics/export/${format}?${query}`, { method: "POST", signal }, "blob");
      if (signal?.aborted) return;
      const url = URL.createObjectURL(blob), link = document.createElement("a");
      link.href = url; link.download = filename || `statistics.${format}`; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) { if (!controller.current?.signal.aborted) setError(e instanceof Error ? e.message : "تعذّر التصدير."); }
    finally { pending.current = false; setExporting(false); }
  }
  return <div className={styles.screen}><header className={styles.heading}><div><h2>الإحصاءات المجهلة</h2><p>{result.data?.facility.name_ar}</p></div></header>
    <section className={reportStyles.controls}><div className={reportStyles.custom}><label>من شهر مكتمل<input type="month" value={start} onChange={e => setStart(e.target.value)} /></label><label>إلى شهر مكتمل<input type="month" value={end} onChange={e => setEnd(e.target.value)} /></label></div><div className={styles.actions}><button className={styles.primary} onClick={() => router.replace(`/statistics?facility_id=${facility}&from_month=${encodeURIComponent(start)}&to_month=${encodeURIComponent(end)}`)}>عرض الإحصاءات</button>{canExport && <><button className={styles.secondary} disabled={!ready || exporting} onClick={() => void download("pdf")}>تصدير PDF</button><button className={styles.secondary} disabled={!ready || exporting} onClick={() => void download("xlsx")}>تصدير Excel</button></>}</div><p className={styles.hint}>اختر حتى 12 شهرًا مكتملًا. لا تتوفر روابط أو قوائم للمرضى.</p></section>
    {(error || result.error) && <p role="alert">{error || result.error}</p>}{result.error && <button className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button>}{result.loading && <p role="status">جارٍ حساب الإحصاءات…</p>}
    {result.data && <><p className={styles.hint}>{result.data.privacy.policy}</p><p className={styles.hint}>{result.data.occupancy.reason}</p>{result.data.months.map(month => <section key={month.month} className={styles.panel}><h3 className={statisticsStyles.month}>{month.month}</h3>{month.sections.map(section => <section key={section.key} className={statisticsStyles.metric}><div className={statisticsStyles.intro}><h4>{section.title}</h4><p className={styles.hint}>{section.definition}</p>{section.suppressed ? <p>محجوب لحماية الخصوصية</p> : <p className={statisticsStyles.total}>مرضى فريدون: {section.patients} — الوقائع: {section.events}</p>}</div>{!section.suppressed && <DirectoryTable label={`${section.title} ${month.month}`} headers={["الفئة", "مرضى فريدون", "الوقائع"]}>{section.rows.map((row, i) => <tr key={i}><td>{row.label}</td><td>{row.patients}</td><td>{row.events}</td></tr>)}{!section.rows.length && <tr><td colSpan={3}>لا توجد وقائع لهذا المؤشر في الشهر المحدد.</td></tr>}</DirectoryTable>}</section>)}</section>)}</>}
  </div>;
}
