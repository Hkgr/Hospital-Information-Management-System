import { Suspense } from "react";
import CatalogScreen from "@/features/catalog/CatalogScreen";

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل التفاصيل…</p>}><CatalogScreen family="medication" kind="medication" itemId={id} /></Suspense>;
}
