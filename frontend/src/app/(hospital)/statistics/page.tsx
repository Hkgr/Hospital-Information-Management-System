import { Suspense } from "react";
import StatisticsScreen from "@/features/statistics/StatisticsScreen";
export default function Page() { return <Suspense fallback={null}><StatisticsScreen /></Suspense>; }
