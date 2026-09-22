import { Suspense } from "react";
import AuditEventScreen from "@/features/audit/AuditEventScreen";

export const metadata = { title: "حركة السجل" };

export default async function Page({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل الحركة…</p>}><AuditEventScreen id={id} /></Suspense>;
}
