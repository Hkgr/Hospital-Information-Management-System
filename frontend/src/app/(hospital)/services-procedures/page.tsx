import { Suspense } from "react";
import CatalogScreen from "@/features/catalog/CatalogScreen";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الخدمات والإجراءات…</p>}><CatalogScreen /></Suspense>;
}
