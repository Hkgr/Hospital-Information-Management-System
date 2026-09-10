import type { Metadata } from "next";
import LoginPage from "@/features/auth/LoginPage";

export const metadata: Metadata = { title: "تسجيل الدخول" };
export default function Page() { return <LoginPage />; }
