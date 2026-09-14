import { Suspense } from "react";
import DossierScreen from "@/features/dossiers/DossierScreen";
export default function Page() { return <Suspense fallback={<p role="status">جارٍ تحميل الإضبارات…</p>}><DossierScreen /></Suspense>; }
