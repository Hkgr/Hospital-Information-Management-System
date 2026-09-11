import { Suspense } from "react";
import ClinicScreen from "@/features/clinics/ClinicScreen";

export default async function ClinicPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل العيادة…</p>}><ClinicScreen clinicId={id} /></Suspense>;
}
