import { Suspense } from "react";
import DoctorScreen from "@/features/doctors/DoctorScreen";

export default function DoctorsPage() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الأطباء…</p>}><DoctorScreen /></Suspense>;
}
