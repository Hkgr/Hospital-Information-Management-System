"use client";
import { useState } from "react";
import { LuCheck } from "react-icons/lu";
import { Pagination } from "../directory/Controls";
import { type Choice, type Page, useBloodRequest, useDebounced } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";

export default function Picker({ name, label, path, choices, eventChoices = false, loading, error, retry, emptyMessage = "لا توجد خيارات مطابقة متاحة.", selected, onSelect, compact = false, autoFocus = false }: { name?: string; label: string; path?: string; choices?: Choice[]; eventChoices?: boolean; loading?: boolean; error?: string; retry?: () => void; emptyMessage?: string; selected?: Choice | null; onSelect: (row: Choice) => void; compact?: boolean; autoFocus?: boolean }) {
  const [search, setSearch] = useState(""); const [page, setPage] = useState(1); const committed = useDebounced(search);
  const result = useBloodRequest<Page<Choice & { occurred_on?: string; component_name?: string }>>(path ? `${path}&search=${encodeURIComponent(committed)}&page=${page}` : null, true);
  const rows = path ? result.data?.data.map(row => eventChoices ? { ...row, name_ar: `${row.occurred_on ?? ""} · ${row.component_name ?? ""}` } : row) : choices?.filter(row => `${row.name_ar} ${row.code ?? ""}`.includes(search.trim()));
  const failure = path ? result.error : error;
  return <fieldset className={`${styles.picker} ${layout.picker}${compact ? ` ${layout.compactPanel}` : ""}`}><legend className={compact ? layout.pickerLegend : undefined}>{label}</legend>{!compact && selected && <p className={styles.hint}>المحدد: {selected.name_ar} {selected.code && <bdi>({selected.code})</bdi>}</p>}
    <input name={name} type="search" autoComplete="off" autoFocus={autoFocus} aria-label={`البحث: ${label}`} value={search} onChange={e => { setSearch(e.target.value); setPage(1); }} placeholder="اكتب الاسم أو الكود…" />
    {(path ? result.loading || search !== committed : loading) ? <p role="status">جارٍ تحميل الخيارات…</p> : failure ? <p role="alert">{failure} <button className={styles.secondary} type="button" onClick={path ? result.retry : retry}>إعادة المحاولة</button></p> : result.data?.doctor_types_configured === false ? <p role="alert">دليل أنواع الأطباء غير مهيأ. راجع مسؤول النظام لإعداد دليل الأطباء المستخدم في قسم العيادات.</p> : <><div className={`${styles.choices} ${layout.pickerChoices}${compact ? ` ${layout.compactChoices}` : ""}${!compact && !path && (rows?.length ?? 0) > 4 ? ` ${layout.choiceColumns}` : ""}`}>{rows?.map(row => <button className={layout.option} type="button" key={row.id} aria-pressed={row.id === selected?.id} onClick={() => onSelect(row)}><span>{row.name_ar}{row.code && <bdi>({row.code})</bdi>}</span>{row.id === selected?.id && <LuCheck aria-hidden="true" />}</button>)}</div>{!rows?.length && <p className={styles.hint}>{emptyMessage}</p>}{path && result.data && <div className={layout.pickerPagination}><Pagination meta={result.data.meta} onPage={setPage} /></div>}</>}
  </fieldset>;
}
