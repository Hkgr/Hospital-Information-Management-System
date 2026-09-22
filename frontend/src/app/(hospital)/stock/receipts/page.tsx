import { Suspense } from "react";
import ReceiptsScreen from "@/features/stock/ReceiptsScreen";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل أذونات الاستلام…</p>}><ReceiptsScreen /></Suspense>;
}
