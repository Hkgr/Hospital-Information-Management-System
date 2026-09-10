import type { Metadata } from "next";
import { Suspense } from "react";
import DashboardScreen from "@/features/dashboards/DashboardScreen";

export const metadata: Metadata = { title: "لوحة التحكم" };

export default function DashboardPage() { return <Suspense fallback={null}><DashboardScreen /></Suspense>; }
