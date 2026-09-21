import { Suspense } from "react";
import VisitsScreen from "@/features/dossiers/VisitsScreen";

export default function Page() {
  return <Suspense fallback={<p>جارٍ تحميل الزيارات…</p>}><VisitsScreen /></Suspense>;
}
