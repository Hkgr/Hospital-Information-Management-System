"use client";

import { usePathname, useSearchParams } from "next/navigation";
import DossierReports from "./DossierReports";
import styles from "../clinics/clinics.module.css";

export default function DossierHistoryReport({ dossier, facility, ready }: { dossier: string; facility: number; ready: boolean }) {
  const params = useSearchParams(), pathname = usePathname();
  const from = params.get("report_from") ?? "", to = params.get("report_to") ?? "";
  const filters = new URLSearchParams({ facility_id: String(facility) });
  if (from) filters.set("from", from); if (to) filters.set("to", to);
  function filter(key: string, value: string) {
    const next = new URLSearchParams(params.toString());
    if (value) next.set(`report_${key}`, value); else next.delete(`report_${key}`);
    window.history.replaceState(null, "", `${pathname}?${next}`);
  }
  const invalid = !!from && !!to && to < from;
  return <section className={styles.panel} aria-label="تقرير بطاقة المريض"><h2>تقرير بطاقة المريض</h2><p className={styles.hint}>يشمل الزيارات ووقائعها المحفوظة، بما فيها الملغاة وأسبابها. المدة اختيارية وتطبق على تاريخ الزيارة الفعلي. بيانات الشخص والملف الطبي هي الحالية؛ القيم السابقة للتصحيحات في سجل التغييرات بصلاحيته المستقلة.</p>
    <div className={styles.filters}><label>تقرير الزيارات من<input type="date" value={from} onChange={e => filter("from", e.target.value)} /></label><label>تقرير الزيارات إلى<input type="date" value={to} onChange={e => filter("to", e.target.value)} /></label></div>
    {invalid && <p role="alert">تاريخ النهاية يجب ألا يسبق تاريخ البداية.</p>}
    <DossierReports path={`dossiers/${dossier}/report`} filters={filters.toString()} ready={ready && !invalid} allowed />
  </section>;
}
