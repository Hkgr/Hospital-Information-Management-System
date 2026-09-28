import { Suspense } from "react";
import SettingsScreen from "@/features/settings/SettingsScreen";

export default function SettingsPage() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الإعدادات…</p>}><SettingsScreen /></Suspense>;
}
