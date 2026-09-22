import { Suspense } from "react";
import ReportsScreen from "@/features/reports/ReportsScreen";

export const metadata = { title: "تقارير" };

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل التقارير…</p>}><ReportsScreen /></Suspense>;
}
