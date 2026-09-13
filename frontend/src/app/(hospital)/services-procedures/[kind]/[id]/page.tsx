import { Suspense } from "react";
import CatalogScreen from "@/features/catalog/CatalogScreen";

export default async function Page({ params }: { params: Promise<{ kind: string; id: string }> }) {
  const { kind, id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل التفاصيل…</p>}><CatalogScreen kind={kind} itemId={id} /></Suspense>;
}
