"use client";

import { useState } from "react";
import { apiRequest } from "@/features/auth/api";
import type { Page } from "../clinics/api";
import styles from "../clinics/clinics.module.css";

export async function readPages<T extends { id: number }>(path: string, signal: AbortSignal): Promise<T[]> {
  const rows: T[] = [];
  for (let page = 1; ; page++) {
    const result = await apiRequest<Page<T>>(`${path}&per_page=100&page=${page}`, { signal }, "envelope");
    rows.push(...result.data);
    if (page >= result.meta.last_page) return rows;
  }
}

export type ChoiceSnapshot<T> = { choices: Record<number, T | undefined>; unavailable: Record<number, "INACTIVE" | "UNAVAILABLE"> };

export async function readChoices<T extends { id: number }>(path: string, touchedIds: number[], signal: AbortSignal): Promise<ChoiceSnapshot<T>> {
  const ids = [...new Set(touchedIds)];
  if (ids.length > 400 || ids.some(id => !Number.isSafeInteger(id) || id < 1)) throw new Error("معرّفات الارتباطات غير صالحة؛ مسودتك محفوظة.");
  const batches: number[][] = [];
  for (let start = 0; start < ids.length; start += 100) batches.push(ids.slice(start, start + 100));
  const pending = new AbortController();
  const cancel = () => pending.abort();
  signal.addEventListener("abort", cancel, { once: true });
  try {
    const results = await Promise.all(batches.map(async batch => {
      signal.throwIfAborted();
      const query = new URLSearchParams();
      batch.forEach(id => query.append("ids[]", String(id)));
      const result = await apiRequest<Page<T> & { unavailable: { id: number; reason: "INACTIVE" | "UNAVAILABLE" }[] }>(`${path}&${query}`, { signal: pending.signal }, "envelope");
      signal.throwIfAborted();
      const available = result.data.filter(row => batch.includes(row.id));
      const unavailable = (result.unavailable ?? []).filter(row => batch.includes(row.id));
      if (batch.some(id => !available.some(row => row.id === id) && !unavailable.some(row => row.id === id))) throw new Error("لم تكتمل بيانات الارتباطات؛ مسودتك محفوظة. أعد جلب أحدث نسخة.");
      return { available, unavailable };
    }));
    signal.throwIfAborted();
    return { choices: Object.fromEntries(results.flatMap(result => result.available.map(row => [row.id, row]))), unavailable: Object.fromEntries(results.flatMap(result => result.unavailable.map(row => [row.id, row.reason]))) };
  } finally {
    pending.abort();
    signal.removeEventListener("abort", cancel);
  }
}

export default function ConflictReview<F extends object, T extends { id: number; code: string; is_linked: boolean }>({ labels, initial, latest, draft, display, currentLinks, linkName, linkTitle, changes, touched, choices, unavailable, onAccept }: {
  labels: Record<keyof F, string>; initial: F; latest: F; draft: F;
  display: (key: keyof F, value: F[keyof F], source: "initial" | "latest" | "draft") => string;
  currentLinks: T[]; linkName: (item: T) => string; linkTitle: string;
  changes: Record<number, boolean>; touched: Record<number, T>; choices: Record<number, T | undefined>;
  unavailable: ChoiceSnapshot<T>["unavailable"];
  onAccept: (fields: F, changes: Record<number, boolean>) => void;
}) {
  const [selected, setSelected] = useState<Partial<Record<keyof F, boolean>>>({});
  const [selectedLinks, setSelectedLinks] = useState<Record<number, boolean>>({});
  const keys = Object.keys(labels) as (keyof F)[];
  function accept() {
    const fields = { ...latest };
    for (const key of keys) if (selected[key]) fields[key] = draft[key];
    const rebased: Record<number, boolean> = {};
    for (const [key, desired] of Object.entries(changes)) {
      const id = Number(key), current = choices[id];
      if (selectedLinks[id] && current && desired !== current.is_linked) rebased[id] = desired;
    }
    onAccept(fields, rebased);
  }
  return <section className={styles.conflictReview} aria-label="مراجعة تعارض التعديل">
    <h3>مراجعة أحدث نسخة مع مسودتك</h3><p className={styles.hint}>تُستخدم أحدث البيانات افتراضيًا. اختر فقط ما تريد تطبيقه من مسودتك، ثم راجع النموذج قبل الضغط على حفظ. لا تُحفظ أي تغييرات تلقائيًا.</p>
    {keys.map(key => <div className={styles.reviewField} key={String(key)}><strong>{labels[key]}</strong>
      <dl><div><dt>عند بدء التعديل</dt><dd>{display(key, initial[key], "initial")}</dd></div><div><dt>أحدث نسخة</dt><dd>{display(key, latest[key], "latest")}</dd></div><div><dt>مسودتك</dt><dd>{display(key, draft[key], "draft")}</dd></div></dl>
      {JSON.stringify(draft[key]) !== JSON.stringify(latest[key]) && <label><input type="checkbox" checked={!!selected[key]} onChange={e => setSelected({ ...selected, [key]: e.target.checked })} />تطبيق مسودتي: {labels[key]}</label>}
    </div>)}
    <div className={styles.reviewField}><strong>{linkTitle === "الأطباء" ? "الأطباء المرتبطون حاليًا" : "العيادات المرتبطة حاليًا"}</strong><ul className={styles.choices}>{currentLinks.map(item => <li key={item.id}>{linkName(item)} · <bdi>{item.code}</bdi></li>)}</ul>{!currentLinks.length && <p>لا توجد ارتباطات حالية.</p>}</div>
    {Object.entries(changes).map(([key, desired]) => {
      const id = Number(key), current = choices[id], item = touched[id], shown = current ?? item;
      return <div key={id} className={styles.reviewField}><strong>{linkName(shown)} · <bdi>{shown.code}</bdi></strong>
        {current && (current.code !== item.code || linkName(current) !== linkName(item)) && <p className={styles.hint}>تغيّر الكود أو الاسم. عند بدء التعديل: {linkName(item)} · <bdi>{item.code}</bdi>. الارتباط هو السجل نفسه.</p>}
        <p>أحدث نسخة: {current ? (current.is_linked ? "مرتبط" : "غير مرتبط") : "غير متاح للاختيار"} · مسودتك: {desired ? "إضافة الارتباط" : "إزالة الارتباط"}</p>
        {current && current.is_linked !== desired ? <label><input type="checkbox" checked={!!selectedLinks[id]} onChange={e => setSelectedLinks({ ...selectedLinks, [id]: e.target.checked })} />تطبيق اختياري {linkTitle === "الأطباء" ? "للطبيب" : "للعيادة"}: {linkName(current)}</label>
          : <p className={styles.hint}>{current ? "اختيارك يطابق الحالة الحالية؛ لا يلزم إرسال تغيير." : `${unavailable[id] === "INACTIVE" ? "السجل أو نوعه أصبح غير فعال." : "حُذف السجل أو لم يعد متاحًا ضمن النطاق المصرّح به."} لن يُرسل تغيير لهذا الارتباط. يبقى تاريخه كما هو.`}</p>}
      </div>;
    })}<button type="button" className={styles.secondary} onClick={accept}>اعتماد الاختيارات للمراجعة</button>
  </section>;
}
