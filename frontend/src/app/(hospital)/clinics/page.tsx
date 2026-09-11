import { Suspense } from "react";
import ClinicScreen from "@/features/clinics/ClinicScreen";

export default function ClinicsPage() {
  return <Suspense fallback={<p role="status">جارٍ تحميل العيادات…</p>}><ClinicScreen /></Suspense>;
}
