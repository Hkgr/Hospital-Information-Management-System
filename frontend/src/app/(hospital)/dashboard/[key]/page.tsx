import type { Metadata } from "next";
import { Suspense } from "react";
import DashboardScreen from "@/features/dashboards/DashboardScreen";

export const metadata: Metadata = { title: "الرئيسية" };

export default async function DashboardPage({ params }: { params: Promise<{ key: string }> }) {
  const { key } = await params;
  return <Suspense fallback={null}><DashboardScreen dashboardKey={key} /></Suspense>;
}
