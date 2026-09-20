import { Suspense } from "react";
import DossierWizard from "@/features/dossiers/DossierWizard";
export default async function Page({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; return <Suspense fallback={<p role="status">جارٍ تحميل بطاقة المريض…</p>}><DossierWizard id={id} /></Suspense>; }
