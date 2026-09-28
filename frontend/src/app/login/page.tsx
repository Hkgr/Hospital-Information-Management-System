import type { Metadata } from "next";
import LoginPage from "@/features/auth/LoginPage";
import { Suspense } from "react";

export const metadata: Metadata = { title: "تسجيل الدخول" };
export default function Page() { return <Suspense fallback={null}><LoginPage /></Suspense>; }
