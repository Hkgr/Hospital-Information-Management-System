import { Suspense } from "react";
import BloodBankScreen from "@/features/blood-bank/BloodBankScreen";
export default async function Page({ params }: { params: Promise<{ id: string; donationId: string }> }) { const { id, donationId } = await params; return <Suspense fallback={<p role="status">جارٍ تحميل التبرع…</p>}><BloodBankScreen kind="donor" id={id} donationId={donationId} /></Suspense>; }
