import PortalFrame from "@/features/settings/PortalFrame";
import { Suspense } from "react";
import ReceptionScreen from "@/features/reception/ReceptionScreen";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الاستقبال…</p>}><PortalFrame><ReceptionScreen /></PortalFrame></Suspense>;
}
