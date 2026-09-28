import type { Metadata } from "next";
import { Suspense } from "react";
import DashboardScreen from "@/features/dashboards/DashboardScreen";
import PortalFrame from "@/features/settings/PortalFrame";

export const metadata: Metadata = { title: "الرئيسية" };

export default async function DashboardPage({ params }: { params: Promise<{ key: string }> }) {
  const { key } = await params;
  return <Suspense fallback={<p role="status">جارٍ تحميل لوحة التحكم…</p>}><PortalFrame><DashboardScreen dashboardKey={key} /></PortalFrame></Suspense>;
}
