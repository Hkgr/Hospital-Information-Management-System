import { Suspense } from "react";
import DossierScreen from "@/features/dossiers/DossierScreen";
export default async function Page({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; return <Suspense fallback={<p role="status">جارٍ تحميل بطاقة المريض…</p>}><DossierScreen id={id} /></Suspense>; }
