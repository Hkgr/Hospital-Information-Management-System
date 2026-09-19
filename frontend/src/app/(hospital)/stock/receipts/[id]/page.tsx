import { Suspense } from "react";
import ReceiptsScreen from "@/features/stock/ReceiptsScreen";

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل إذن الاستلام…</p>}><ReceiptsScreen receiptId={id} /></Suspense>;
}
