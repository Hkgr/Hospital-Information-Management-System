import { Suspense } from "react";
import DashboardScreen from "@/features/dashboards/DashboardScreen";

export default async function DashboardPage({ params }: { params: Promise<{ key: string }> }) {
  const { key } = await params;
  return <Suspense fallback={null}><DashboardScreen dashboardKey={key} /></Suspense>;
}
