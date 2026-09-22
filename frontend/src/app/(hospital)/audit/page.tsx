import { Suspense } from "react";
import AuditLogScreen from "@/features/audit/AuditLogScreen";

export const metadata = { title: "السجل" };

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل السجل…</p>}><AuditLogScreen /></Suspense>;
}
