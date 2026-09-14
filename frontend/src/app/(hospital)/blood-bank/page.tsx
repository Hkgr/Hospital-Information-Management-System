import { Suspense } from "react";
import BloodBankScreen from "@/features/blood-bank/BloodBankScreen";
export default function Page() { return <Suspense fallback={<p role="status">جارٍ تحميل بنك الدم…</p>}><BloodBankScreen /></Suspense>; }
