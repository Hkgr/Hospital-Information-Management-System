"use client";
import { useState } from "react";
import { DirectoryTable } from "../directory/DirectoryPrimitives";
import styles from "../clinics/clinics.module.css";

export default function ConflictReview<T extends Record<string, string>>({ latest, draft, labels, format, onAccept }: { latest: T; draft: T; labels: Record<string, string>; format?: (key: string, value: string) => string; onAccept: (value: T) => void }) {
  const [selected, setSelected] = useState<string[]>([]);
  const keys = Object.keys(labels).filter(key => draft[key] !== latest[key]);
  const show = (key: string, value: string) => value ? (format?.(key, value) ?? value) : "غير محدد";
  return <section className={styles.panel}><h3>مراجعة أحدث نسخة ومسودتك</h3><p className={styles.hint}>تُعتمد أحدث البيانات افتراضيًا. اختر فقط ما تريد استبداله من مسودتك، ثم راجع النموذج واحفظه بنفسك.</p>
    <DirectoryTable label="مقارنة المسودة بأحدث البيانات" headers={["الحقل", "أحدث نسخة", "مسودتك", "الاختيار"]}>{keys.map(key => <tr key={key}><td>{labels[key]}</td><td>{show(key, latest[key])}</td><td>{show(key, draft[key])}</td><td><label><input type="checkbox" checked={selected.includes(key)} onChange={e => setSelected(previous => e.target.checked ? [...previous, key] : previous.filter(k => k !== key))} />تطبيق مسودتي: {labels[key]}</label></td></tr>)}</DirectoryTable>
    {!keys.length && <p>المسودة مطابقة لأحدث البيانات.</p>}<button type="button" className={styles.secondary} onClick={() => onAccept({ ...latest, ...Object.fromEntries(selected.map(key => [key, draft[key]])) })}>اعتماد الاختيارات للمراجعة</button>
  </section>;
}
