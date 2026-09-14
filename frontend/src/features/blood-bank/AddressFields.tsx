"use client";

import Picker from "./Picker";
import { type Choice, type Options, type Profile } from "./api";
import styles from "../clinics/clinics.module.css";
import layout from "./profile.module.css";

export default function AddressFields({ draft, profile, options, cities, change, fieldError }: {
  draft: Record<string, string>; profile?: Pick<Profile, "person" | "governorate_name" | "city_name">; options: Options;
  cities: { data?: Choice[]; loading: boolean; error?: string; retry: () => void };
  change: (key: string, value: string) => void; fieldError: (key: string) => React.ReactNode;
}) {
  const governorate = options.governorates.find(g => String(g.id) === draft.governorate_id)
    ?? (profile?.person.governorate_id && String(profile.person.governorate_id) === draft.governorate_id ? { id: Number(profile.person.governorate_id), name_ar: profile.governorate_name ?? "المحافظة المسجلة سابقًا" } : null);
  const city = cities.data?.find(c => String(c.id) === draft.city_id)
    ?? (profile?.person.city_id && String(profile.person.city_id) === draft.city_id ? { id: Number(profile.person.city_id), name_ar: profile.city_name ?? "المدينة المسجلة سابقًا" } : null);
  return <>
    <fieldset className={`${styles.picker} ${layout.addressField} ${draft.governorate_mode === "directory" && !draft.governorate_id ? styles.full : ""}`}><legend>المحافظة</legend>
      <div className={styles.actions}>{[["directory", "محافظة سورية"], ["foreign", "محافظة خارج سوريا"]].map(([v, label]) => <label className={styles.doctorChoice} key={v}><input type="radio" name="governorate-mode" value={v} checked={draft.governorate_mode === v} onChange={() => change("governorate_mode", v)} />{label}</label>)}</div>
      {draft.governorate_mode === "directory" ? <><Picker name="governorate_id" label="المحافظة السورية" choices={options.governorates} selected={governorate} onSelect={g => { if (draft.governorate_id !== String(g.id)) change("city_id", ""); change("governorate_id", String(g.id)); }} />{draft.governorate_id && <button type="button" className={styles.secondary} onClick={() => { change("governorate_id", ""); change("city_id", ""); }}>إلغاء تحديد المحافظة</button>}{fieldError("governorate_id")}</>
        : <label>اسم المحافظة خارج سوريا<input name="governorate_text" aria-label="اسم المحافظة خارج سوريا" required maxLength={120} value={draft.governorate_text} onChange={e => change("governorate_text", e.target.value)} />{fieldError("governorate_text")}</label>}
    </fieldset>
    {(draft.governorate_mode === "foreign" || draft.governorate_id) && <fieldset className={`${styles.picker} ${layout.addressField}`}><legend>المدينة</legend>
      {draft.governorate_mode === "directory" && <div className={styles.actions}>{[["directory", "مدينة من الدليل"], ["manual", "المدينة غير موجودة"]].map(([v, label]) => <label className={styles.doctorChoice} key={v}><input type="radio" name="city-mode" value={v} checked={draft.city_mode === v} onChange={() => change("city_mode", v)} />{label}</label>)}</div>}
      {draft.governorate_mode === "foreign" || draft.city_mode === "manual" ? <label>اسم المدينة<input name="city_text" aria-label="اسم المدينة" required={draft.city_mode === "manual"} maxLength={120} value={draft.city_text} onChange={e => change("city_text", e.target.value)} />{fieldError("city_text")}</label>
        : <><Picker name="city_id" key={draft.governorate_id} label="المدينة التابعة للمحافظة" choices={cities.data} loading={cities.loading} error={cities.error} retry={cities.retry} selected={city} onSelect={c => change("city_id", String(c.id))} emptyMessage="لا توجد مدينة مطابقة؛ يمكنك اختيار المدينة غير موجودة." />{draft.city_id && <button type="button" className={styles.secondary} onClick={() => change("city_id", "")}>إلغاء تحديد المدينة</button>}{fieldError("city_id")}</>}
    </fieldset>}
  </>;
}
