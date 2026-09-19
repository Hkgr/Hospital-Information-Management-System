import { Suspense } from "react";
import StockDirectoryScreen from "@/features/stock/StockDirectoryScreen";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل المستودعات…</p>}><StockDirectoryScreen kind="stores" /></Suspense>;
}
