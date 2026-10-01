import { Suspense } from "react";
import PatientCardReviews from "@/features/dossiers/PatientCardReviews";

export default function Page() { return <Suspense fallback={<p role="status">جارٍ تحميل مراجعات البطاقة…</p>}><PatientCardReviews /></Suspense>; }
