"use client";

import { LuColumns3 } from "react-icons/lu";
import type { Page } from "../clinics/api";
import styles from "../clinics/clinics.module.css";

export function Pagination({ meta, onPage, onPageSize }: { meta: Page<unknown>["meta"]; onPage: (page: number) => void; onPageSize?: (value: string) => void }) {
  return <div className={styles.pagination}>{onPageSize && <label>عدد الصفوف<select value={meta.per_page} onChange={e => onPageSize(e.target.value)}>{[10, 20, 50, 100].map(n => <option key={n} value={n}>{n}</option>)}</select></label>}<span>صفحة {meta.page} من {meta.last_page}</span><button type="button" disabled={meta.page <= 1} onClick={() => onPage(meta.page - 1)}>السابق</button><button type="button" disabled={meta.page >= meta.last_page} onClick={() => onPage(meta.page + 1)}>التالي</button></div>;
}

export function ColumnMenu<K extends string>({ labels, visible, onChange }: { labels: Record<K, string>; visible: K[]; onChange: (keys: K[]) => void }) {
  const keys = Object.keys(labels) as K[];
  return <details className={styles.columnMenu}><summary><LuColumns3 aria-hidden="true" />الأعمدة</summary><fieldset><legend>الأعمدة الظاهرة في الجدول والتصدير</legend>{keys.map(key => <label key={key}><input type="checkbox" checked={visible.includes(key)} disabled={visible.length === 1 && visible.includes(key)} onChange={() => onChange(keys.filter(column => column === key ? !visible.includes(key) : visible.includes(column)))} />{labels[key]}</label>)}</fieldset></details>;
}

export function LongText({ text }: { text: string | null }) {
  if (!text) return <>—</>;
  return <details className={styles.longText}><summary><span className={styles.excerpt}>{text}</span><small>قراءة النص كاملًا</small></summary><p>{text}</p></details>;
}
