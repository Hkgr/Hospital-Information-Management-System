import type { Metadata } from "next";
import "./globals.css";

export const metadata: Metadata = {
  title: { default: "نظام إدارة المشفى | مشفى محمد بن زايد الإماراتي", template: "%s | مشفى محمد بن زايد الإماراتي" },
  description: "نظام إدارة مشفى محمد بن زايد الإماراتي",
  robots: { index: false, follow: false },
};

export default function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  return <html lang="ar" dir="rtl"><body>{children}</body></html>;
}
