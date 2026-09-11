"use client";

import { useState } from "react";
import { useClinicRequest, useDebounced, type Page } from "../clinics/api";
import { Pagination } from "./Controls";
import styles from "../clinics/clinics.module.css";

type Choice = { id: number; code: string; name?: string; name_ar?: string };
type Choices = Page<Choice> & { unavailable?: { id: number; reason: string }[] };

export default function RelationFilter({ kind, facilityId, value, onChange }: { kind: "doctor" | "clinic"; facilityId: number; value: string; onChange: (value: string) => void }) {
  const [open, setOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [labels, setLabels] = useState<Record<string, string>>({});
  const debounced = useDebounced(search);
  const endpoint = kind === "doctor" ? "clinics/options/doctors" : "doctors/options/clinics";
  const singular = kind === "doctor" ? "الطبيب" : "العيادة";
  const plural = kind === "doctor" ? "الأطباء" : "العيادات";
  const query = new URLSearchParams({ facility_id: String(facilityId), search: debounced, page: String(page) });
  const result = useClinicRequest<Choices>(open ? `${endpoint}?${query}` : null, true);
  const selectedQuery = new URLSearchParams({ facility_id: String(facilityId), "ids[]": value });
  const selected = useClinicRequest<Choices>(value && !labels[value] ? `${endpoint}?${selectedQuery}` : null, true);
  const selectedChoice = selected.data?.data.find(item => String(item.id) === value);
  const label = labels[value] ?? (selectedChoice ? `${selectedChoice.name ?? selectedChoice.name_ar} · ${selectedChoice.code}` : selected.error ? "تعذّر جلب الاختيار" : selected.loading ? "جارٍ تحميل الاختيار…" : "الاختيار غير متاح");
  function choose(choice?: Choice) {
    if (choice) setLabels(previous => ({ ...previous, [choice.id]: `${choice.name ?? choice.name_ar} · ${choice.code}` }));
    onChange(choice ? String(choice.id) : ""); setOpen(false);
  }
  return <details className={styles.doctorFilter} open={open} onToggle={event => setOpen(event.currentTarget.open)} onKeyDown={event => {
    if (event.key === "Escape") { event.stopPropagation(); setOpen(false); event.currentTarget.querySelector("summary")?.focus(); }
  }}>
    <summary>{singular}: {value ? label : "الكل"}</summary>
    {open && <div><label>ابحث عن {kind === "doctor" ? "طبيب" : "عيادة"}<input type="search" value={search} onChange={event => { setSearch(event.target.value); setPage(1); }} /></label>
      <button type="button" onClick={() => choose()}>كل {plural}</button>
      {selected.error && <button type="button" onClick={selected.retry}>إعادة جلب الاختيار</button>}
      {result.error ? <p role="alert">{result.error}<button type="button" onClick={result.retry}>إعادة المحاولة</button></p> : !result.data || search !== debounced ? <p role="status">جارٍ البحث…</p> : <>
        {result.data.doctor_types_configured === false ? <p role="status">لا يوجد نوع طبي فعال مطابق للإعداد المعتمد؛ راجع مسؤول النظام.</p> : !result.data.data.length && <p>لا توجد نتائج مطابقة.</p>}
        <ul>{result.data.data.map(choice => <li key={choice.id}><button type="button" aria-pressed={value === String(choice.id)} onClick={() => choose(choice)}>{choice.name ?? choice.name_ar} · <bdi>{choice.code}</bdi></button></li>)}</ul>
        <Pagination meta={result.data.meta} onPage={setPage} />
      </>}
    </div>}
  </details>;
}
