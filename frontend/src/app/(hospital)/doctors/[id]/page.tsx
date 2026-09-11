import { Suspense } from "react";
import DoctorScreen from "@/features/doctors/DoctorScreen";

export default async function DoctorPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل الطبيب…</p>}><DoctorScreen doctorId={id} /></Suspense>;
}
