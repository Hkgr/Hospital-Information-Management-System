"use client";

import { useState } from "react";
import { type Profile, statuses } from "./api";
import styles from "../clinics/clinics.module.css";

const analytes = ["HBsAg", "HCV", "HIV"];
export default function ScreeningFields({ draft, profile, change, fieldError }: { draft: Record<string, string>; profile?: Pick<Profile, "screenings">; change: (key: string, value: string) => void; fieldError: (key: string) => React.ReactNode }) {
  const [adding, setAdding] = useState(false);
  const active = analytes.filter(a => draft[a]);
  return <div className={styles.full}>
    <p className={styles.hint}>أضف الفحوص عند الحاجة. الحالة فقط؛ لا تعني أهلية أو قبول تبرع. النتائج والطرق السابقة تبقى محفوظة.</p>
    {active.length === 0 && !adding && <p className={styles.hint}>لم تُضف فحوص.</p>}
    {active.map((a, index) => {
      const saved = profile?.screenings.some(s => s.analyte === a);
      return <fieldset key={a} className={styles.picker}><legend>فحص {a}</legend><div className={styles.fields}>
        <label>الفحص<select aria-label={`الفحص ${a}`} disabled={saved} value={a} onChange={e => { change(a, ""); change(e.target.value, draft[a]); }}>{analytes.filter(v => v === a || !draft[v]).map(v => <option key={v}>{v}</option>)}</select></label>
        <label>حالة الفحص<select aria-label={`حالة ${a}`} value={draft[a]} onChange={e => change(a, e.target.value)}>{Object.entries(statuses).map(([v, name]) => <option key={v} value={v}>{name}</option>)}</select>{fieldError(`screenings.${index}.status`)}</label>
        {!saved && <button type="button" className={styles.secondary} onClick={() => change(a, "")}>إزالة فحص {a}</button>}
      </div></fieldset>;
    })}
    {adding && <fieldset className={styles.picker}><legend>فحص جديد</legend><label>نوع الفحص<select aria-label="نوع الفحص الجديد" value="" onChange={e => { if (e.target.value) { change(e.target.value, "not_requested"); setAdding(false); } }}><option value="">اختر الفحص</option>{analytes.filter(a => !draft[a]).map(a => <option key={a}>{a}</option>)}</select></label><button type="button" className={styles.secondary} onClick={() => setAdding(false)}>إلغاء إضافة الفحص</button></fieldset>}
    <button type="button" className={styles.secondary} disabled={adding || active.length === 3} onClick={() => setAdding(true)}>إضافة فحص</button>{fieldError("screenings")}
  </div>;
}
