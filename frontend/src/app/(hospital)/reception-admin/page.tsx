import { Suspense } from "react";
import ReceptionAdmin from "../../../features/reception/ReceptionAdmin";

export default function Page() {
  return <Suspense fallback={<p>جارٍ تحميل مراجعات الاستقبال…</p>}><ReceptionAdmin /></Suspense>;
}
