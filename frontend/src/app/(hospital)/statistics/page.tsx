import PortalFrame from "@/features/settings/PortalFrame";
import { Suspense } from "react";
import StatisticsScreen from "@/features/statistics/StatisticsScreen";
export default function Page() { return <Suspense fallback={null}><PortalFrame><StatisticsScreen /></PortalFrame></Suspense>; }
