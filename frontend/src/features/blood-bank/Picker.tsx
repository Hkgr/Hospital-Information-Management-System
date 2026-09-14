"use client";
import { useState } from "react";
import { Pagination } from "../directory/Controls";
import { type Choice, type Page, useBloodRequest, useDebounced } from "./api";
import styles from "../clinics/clinics.module.css";

export default function Picker({ label, path, selected, onSelect }: { label: string; path: string; selected?: Choice | null; onSelect: (row: Choice) => void }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const committed = useDebounced(search);
  const result = useBloodRequest<Page<Choice>>(`${path}&search=${encodeURIComponent(committed)}&page=${page}`, true);
  return <fieldset className={styles.picker}><legend>{label}</legend>{selected && <p className={styles.hint}>المحدد: {selected.name_ar} {selected.code && <bdi>({selected.code})</bdi>}</p>}
    <input type="search" aria-label={`البحث: ${label}`} value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="الاسم أو الكود…" />
    {result.loading || search !== committed ? <p role="status">جارٍ تحميل الخيارات…</p> : result.error ? <p role="alert">{result.error} <button type="button" onClick={result.retry}>إعادة المحاولة</button></p> : <><div className={styles.choices}>{result.data?.data.map(row => <button className={styles.secondary} type="button" key={row.id} aria-pressed={row.id === selected?.id} onClick={() => onSelect(row)}>{row.name_ar} {row.code && <bdi>({row.code})</bdi>}</button>)}</div>{!result.data?.data.length && <p className={styles.hint}>لا توجد خيارات مطابقة متاحة.</p>}{result.data && <Pagination meta={result.data.meta} onPage={setPage} />}</>}
  </fieldset>;
}
