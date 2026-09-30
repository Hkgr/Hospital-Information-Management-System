"use client";

import { useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { directoryFacility } from "../directory/facilityContext";
import ReceptionScreen from "../reception/ReceptionScreen";
import DossierScreen from "./DossierScreen";
import DossierWizard from "./DossierWizard";
import styles from "../clinics/clinics.module.css";

/** Both views use the same patient, local card and saved visit. */
export default function PatientCardsEntry({ id, workspace = false }: { id?: string; workspace?: boolean }) {
  const { access } = useIdentity();
  const params = useSearchParams();
  const { entry } = directoryFacility(access.filter(e => e.permissions.some(p => ["patients.basic.view", "dossiers.medical.view"].includes(p))), null, params.get("facility_id"));
  if (!entry || params.getAll("facility_id").length > 1 || (params.has("facility_id") && !/^[1-9]\d*$/.test(params.get("facility_id")!))) return <section className={styles.status}><h2>بطاقات المرضى</h2><p role="alert">لا يتوفر وصول إلى بطاقات المرضى في المشفى المحدد. راجع المسؤول، أو سجل الخروج من قائمة الحساب.</p></section>;
  const medical = entry.permissions.includes("dossiers.medical.view");
  const selected = id ?? params.get("card") ?? undefined;
  if (workspace && medical && (selected || params.get("intent") === "visit")) return <DossierWizard id={selected} />;
  if (!workspace && medical && params.get("view") !== "registration") return <DossierScreen id={id} />;
  return <ReceptionScreen id={selected} />;
}
