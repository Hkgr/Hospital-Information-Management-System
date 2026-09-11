"use client";

import { useCallback, useState } from "react";
import { usePathname } from "next/navigation";
import type { User } from "@/features/auth/api";
import Sidebar from "./Sidebar";
import Header from "./Header";
import Footer from "./Footer";
import MobileSidebar from "./MobileSidebar";
import { primaryNavigation } from "./navigation";
import { useMediaQuery } from "./useMediaQuery";
import styles from "./shell.module.css";

type Props = { user: User; canViewClinics: boolean; children: React.ReactNode; onLogout: () => Promise<void>; logoutPending: boolean; logoutError: string };

export default function AppShell({ user, canViewClinics, children, onLogout, logoutPending, logoutError }: Props) {
  const pathname = usePathname();
  const tablet = useMediaQuery("(min-width: 768px) and (max-width: 1279px)");
  const [collapsedOverride, setCollapsedOverride] = useState<boolean | null>(null);
  const [mobileOpen, setMobileOpen] = useState(false);
  const closeMobile = useCallback(() => setMobileOpen(false), []);
  const collapsed = collapsedOverride ?? tablet;
  const title = pathname.startsWith("/clinics") ? "العيادات" : pathname.startsWith("/dashboard/") ? "لوحة التحكم" : primaryNavigation.find(item => item.href === pathname)?.label ?? "نظام إدارة المشفى";

  return <div className={styles.shell} data-collapsed={collapsed}>
    <a className={styles.skipLink} href="#main-content">انتقل إلى المحتوى</a>
    <Sidebar pathname={pathname} canViewClinics={canViewClinics} collapsed={collapsed} onToggle={() => setCollapsedOverride(!collapsed)} />
    <MobileSidebar open={mobileOpen} onClose={closeMobile} pathname={pathname} canViewClinics={canViewClinics} />
    <div className={styles.workspace}>
      <Header title={title} user={user} navigationOpen={mobileOpen} onOpenNavigation={() => setMobileOpen(true)} onLogout={onLogout} logoutPending={logoutPending} logoutError={logoutError} />
      <main id="main-content" className={styles.main} tabIndex={-1} aria-labelledby="page-title"><div className={styles.contentCanvas}>{children}</div></main>
      <Footer />
    </div>
  </div>;
}
