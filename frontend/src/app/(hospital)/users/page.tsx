import { Suspense } from "react";
import UsersScreen from "@/features/users/UsersScreen";

export default function UsersPage() {
  return <Suspense fallback={<p role="status">جارٍ تحميل المستخدمين…</p>}><UsersScreen /></Suspense>;
}
