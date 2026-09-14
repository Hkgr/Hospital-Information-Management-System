import { Suspense } from "react";
import EventScreen from "@/features/blood-bank/EventScreen";
export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل الواقعة…</p>}><EventScreen eventId={id} /></Suspense>;
}
