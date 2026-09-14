"use client";
import { useState } from "react";
import { Pagination } from "../directory/Controls";
import { type Choice, type Page, useBloodRequest, useDebounced } from "./api";
import styles from "../clinics/clinics.module.css";

export default function Picker({ label, path, choices, loading, error, retry, emptyMessage = "لا توجد خيارات مطابقة متاحة.", selected, onSelect }: { label: string; path?: string; choices?: Choice[]; loading?: boolean; error?: string; retry?: () => void; emptyMessage?: string; selected?: Choice | null; onSelect: (row: Choice) => void }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const committed = useDebounced(search);
  const result = useBloodRequest<Page<Choice>>(path ? `${path}&search=${encodeURIComponent(committed)}&page=${page}` : null, true);
  const rows = path ? result.data?.data : choices?.filter(row => `${row.name_ar} ${row.code ?? ""}`.includes(search.trim()));
  const failure = path ? result.error : error;
  return <fieldset className={styles.picker}><legend>{label}</legend>{selected && <p className={styles.hint}>المحدد: {selected.name_ar} {selected.code && <bdi>({selected.code})</bdi>}</p>}
    <input type="search" aria-label={`البحث: ${label}`} value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="الاسم أو الكود…" />
    {(path ? result.loading || search !== committed : loading) ? <p role="status">جارٍ تحميل الخيارات…</p> : failure ? <p role="alert">{failure} <button type="button" onClick={path ? result.retry : retry}>إعادة المحاولة</button></p> : result.data?.doctor_types_configured === false ? <p role="alert">دليل أنواع الأطباء غير مهيأ. راجع مسؤول النظام لإعداد دليل الأطباء المستخدم في قسم العيادات.</p> : <><div className={styles.choices}>{rows?.map(row => <button className={styles.secondary} type="button" key={row.id} aria-pressed={row.id === selected?.id} onClick={() => onSelect(row)}>{row.name_ar} {row.code && <bdi>({row.code})</bdi>}</button>)}</div>{!rows?.length && <p className={styles.hint}>{emptyMessage}</p>}{path && result.data && <Pagination meta={result.data.meta} onPage={setPage} />}</>}
  </fieldset>;
}
