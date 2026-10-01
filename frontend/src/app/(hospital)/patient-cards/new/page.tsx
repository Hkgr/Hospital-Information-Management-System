import { Suspense } from "react";
import PatientCardsEntry from "@/features/dossiers/PatientCardsEntry";
export default function Page() { return <Suspense fallback={<p role="status">جارٍ تحميل بطاقة المريض…</p>}><PatientCardsEntry workspace /></Suspense>; }
