import { Suspense } from "react";
import BloodBankScreen from "@/features/blood-bank/BloodBankScreen";
export default async function Page({ params }: { params: Promise<{ id: string }> }) { const { id } = await params; return <Suspense fallback={<p role="status">جارٍ تحميل الملف…</p>}><BloodBankScreen kind="donor" id={id} /></Suspense>; }
