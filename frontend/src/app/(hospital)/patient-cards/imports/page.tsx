import { Suspense } from "react";
import DossierImports from "@/features/dossiers/DossierImports";

export default function Page() {
  return <Suspense fallback={<p role="status">جارٍ تحميل الاستيراد…</p>}><DossierImports /></Suspense>;
}
