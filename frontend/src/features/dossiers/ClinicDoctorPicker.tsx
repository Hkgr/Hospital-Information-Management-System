"use client";

import { useEffect, useRef } from "react";
import Picker from "./DossierPicker";
import type { Choice } from "../blood-bank/api";
import styles from "../clinics/clinics.module.css";

/** A date change invalidates the previous selection, even if the clinic is unchanged. */
export default function ClinicDoctorPicker({ facility, clinic, date, selected, name, label, onSelect }: {
  facility: number; clinic: Choice | null; date: string; selected: Choice | null; name: string; label: string; onSelect: (doctor: Choice | null) => void;
}) {
  const scope = `${facility}:${clinic?.id ?? ""}:${date}`;
  const previous = useRef(scope);
  const callback = useRef(onSelect);
  useEffect(() => { callback.current = onSelect; }, [onSelect]);
  useEffect(() => {
    if (previous.current === scope) return;
    previous.current = scope;
    // No old request can restore a selection: the picker is keyed by this scope.
    callback.current(null);
  }, [scope]);
  if (!clinic || !date) return <p className={styles.hint}>حدد تاريخ الزيارة والعيادة أولًا لعرض الأطباء المرتبطين بها.</p>;
  return <Picker key={scope} name={name} label={label} path={`dossiers/options/doctors?facility_id=${facility}&clinic_id=${clinic.id}&visit_date=${date}`} selected={selected}
    emptyMessage="لا يوجد طبيب مؤهل مرتبط بهذه العيادة في التاريخ المحدد. راجع ارتباطات العيادة أو صحح التاريخ."
    onSelect={onSelect} />;
}
