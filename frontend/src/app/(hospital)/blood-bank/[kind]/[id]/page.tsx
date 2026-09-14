import { Suspense } from "react";
import { notFound } from "next/navigation";
import BloodBankScreen from "@/features/blood-bank/BloodBankScreen";
export default async function Page({ params }: { params: Promise<{ kind: string; id: string }> }) { const { kind, id } = await params; if (kind !== "donor" && kind !== "recipient") notFound(); return <Suspense fallback={<p role="status">جارٍ تحميل الملف…</p>}><BloodBankScreen kind={kind} id={id} /></Suspense>; }
