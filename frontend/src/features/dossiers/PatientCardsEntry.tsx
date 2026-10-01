"use client";

import { useSearchParams } from "next/navigation";
import Link from "next/link";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import PatientCardRegistration from "./PatientCardRegistration";
import PatientCardReviews from "./PatientCardReviews";
import DossierScreen from "./DossierScreen";
import DossierWizard from "./DossierWizard";
import styles from "../clinics/clinics.module.css";

/** Both views use the same patient, local card and saved visit. */
export default function PatientCardsEntry({ id, workspace = false }: { id?: string; workspace?: boolean }) {
  const { access } = useIdentity();
  const params = useSearchParams();
  const { entry } = directoryFacility(access.filter(e => e.permissions.some(p => ["patients.basic.view", "dossiers.medical.view", "identity_corrections.review", "patient_duplicates.review"].includes(p))), null, params.get("facility_id"));
  if (!entry || params.getAll("facility_id").length > 1 || (params.has("facility_id") && !/^[1-9]\d*$/.test(params.get("facility_id")!))) return <section className={styles.status}><h2>بطاقات المرضى</h2><p role="alert">لا يتوفر وصول إلى بطاقات المرضى في المشفى المحدد. راجع المسؤول، أو سجل الخروج من قائمة الحساب.</p></section>;
  const medical = entry.permissions.includes("dossiers.medical.view");
  const selected = id ?? params.get("card") ?? undefined;
  const reviews = entry.permissions.some(p => ["identity_corrections.review", "patient_duplicates.review"].includes(p));
  if (!medical && !entry.permissions.includes("patients.basic.view")) return <PatientCardReviews />;
  const content = workspace && medical && (selected || params.get("intent") === "visit") ? <DossierWizard id={selected} />
    : !workspace && medical && !["registration", "basic"].includes(params.get("view") ?? "") ? <DossierScreen id={id} />
    : <PatientCardRegistration id={selected} create={workspace && !selected} />;
  return <div className={styles.screen}>{reviews && <nav className={styles.actions} aria-label="مراجعات بطاقات المرضى"><Link className={styles.secondary} href={`/patient-cards/reviews?facility_id=${entry.facility.id}`}>تصحيحات البيانات ومراجعة التكرار</Link></nav>}{content}</div>;
}
