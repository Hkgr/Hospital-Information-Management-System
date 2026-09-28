import { Suspense } from "react";
import GuideScreen from "@/features/settings/GuideScreen";

export default function GuidePage() {
  return <Suspense fallback={<p role="status">جارٍ تحميل دليل الاستخدام…</p>}><GuideScreen /></Suspense>;
}
