import { Suspense } from "react";
import StockDirectoryScreen from "@/features/stock/StockDirectoryScreen";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الموردين…</p>}><StockDirectoryScreen kind="suppliers" /></Suspense>;
}
