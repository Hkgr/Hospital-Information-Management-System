import { Suspense } from "react";
import DossierWizard from "@/features/dossiers/DossierWizard";
export default function Page() { return <Suspense fallback={<p role="status">جارٍ تحميل المعالج…</p>}><DossierWizard /></Suspense>; }
