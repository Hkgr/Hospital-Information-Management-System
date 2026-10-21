"use client";

import { useMemo } from "react";
import { AuthError } from "../auth/api";
import { createPortal } from "react-dom";
import EventEditor, { type CardBenefitFormState } from "../blood-bank/EventEditor";
import { type BloodEvent } from "../blood-bank/events";
import { type Options } from "../blood-bank/api";
import { type SummaryPerson } from "../blood-bank/FormLayout";
import { useClinicRequest } from "../clinics/api";
import type { Choice } from "../blood-bank/api";
import styles from "../clinics/clinics.module.css";

export default function CardBloodBenefit({ facility, dossier, visit, date, event, initial, onDraft, onStage, onClose, serverError, errorPrefix }: {
  serverError?: AuthError | null; errorPrefix: string; facility: number; dossier: number; visit: number; date: string; event?: BloodEvent; initial?: CardBenefitFormState;
  onDraft: (state: CardBenefitFormState) => void; onStage: (payload: Record<string, unknown>, clinic: Choice, doctor: Choice) => void; onClose: () => void;
}) {
  const validationError = useMemo(() => {
    const fields = Object.fromEntries(Object.entries(serverError?.fields ?? {}).filter(([key]) => key.startsWith(errorPrefix+".")).map(([key,value]) => [key.slice(errorPrefix.length+1),value]));
    return Object.keys(fields).length ? new AuthError(422,"VALIDATION_FAILED","راجع حقول الاستفادة؛ المسودة محفوظة.",fields) : null;
  },[serverError,errorPrefix]);
  const path = `dossiers/${dossier}/visits/${visit}`;
  const result = useClinicRequest<{ patient: SummaryPerson; options: Options }>(`${path}/blood-benefit-context?facility_id=${facility}`);
  if (!result.data) return <div role={result.error ? "alert" : "status"}>{result.error ?? "جارٍ تحميل نموذج الاستفادة للمريض…"} {result.error && <button type="button" className={styles.secondary} onClick={result.retry}>إعادة المحاولة</button>} <button type="button" className={styles.secondary} onClick={onClose}>إلغاء</button></div>;
  return createPortal(<EventEditor kind="benefit" event={event} facilityId={facility} options={result.data.options} onClose={onClose} onSaved={() => {}} onRefresh={() => {}} card={{ validationError, date, patient: result.data.patient, initial, onDraft, onStage, issuesPath: `${path}/blood-benefit-issues?facility_id=${facility}` }} />, document.body);
}
