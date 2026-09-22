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

type Props = { user: User; canViewClinics: boolean; canViewDoctors: boolean; canViewCatalog: boolean; canViewBloodBank?: boolean; canViewDossiers?: boolean; canViewStock?: boolean; children: React.ReactNode; onLogout: () => Promise<void>; logoutPending: boolean; logoutError: string };

export default function AppShell({ user, canViewClinics, canViewDoctors, canViewCatalog, canViewDossiers = false, canViewBloodBank = false, canViewStock = false, children, onLogout, logoutPending, logoutError }: Props) {
  const pathname = usePathname();
  const tablet = useMediaQuery("(min-width: 768px) and (max-width: 1279px)");
  const [collapsedOverride, setCollapsedOverride] = useState<boolean | null>(null);
  const [mobileOpen, setMobileOpen] = useState(false);
  const closeMobile = useCallback(() => setMobileOpen(false), []);
  const collapsed = collapsedOverride ?? tablet;
  const title = pathname.startsWith("/stock") ? "المخزون" : pathname.startsWith("/blood-bank") ? "بنك الدم" : pathname.startsWith("/doctors") ? "الأطباء" : pathname.startsWith("/clinics") ? "العيادات" : pathname.startsWith("/services-procedures") ? "الخدمات والإجراءات" : pathname.startsWith("/medications") ? "الأدوية" : pathname.startsWith("/dashboard/") ? "الرئيسية" : pathname.startsWith("/audit") ? "السجل" : pathname.startsWith("/reports") ? "تقارير" : primaryNavigation.find(item => item.href === pathname)?.label ?? "نظام إدارة المشفى";

  return <div className={styles.shell} data-collapsed={collapsed}>
    <a className={styles.skipLink} href="#main-content">انتقل إلى المحتوى</a>
    <Sidebar pathname={pathname} canViewClinics={canViewClinics} canViewDoctors={canViewDoctors} canViewCatalog={canViewCatalog} canViewDossiers={canViewDossiers} canViewBloodBank={canViewBloodBank} canViewStock={canViewStock} collapsed={collapsed} onToggle={() => setCollapsedOverride(!collapsed)} />
    <MobileSidebar open={mobileOpen} onClose={closeMobile} pathname={pathname} canViewClinics={canViewClinics} canViewDoctors={canViewDoctors} canViewCatalog={canViewCatalog} canViewDossiers={canViewDossiers} canViewBloodBank={canViewBloodBank} canViewStock={canViewStock} />
    <div className={styles.workspace}>
      <Header title={title} user={user} navigationOpen={mobileOpen} onOpenNavigation={() => setMobileOpen(true)} onLogout={onLogout} logoutPending={logoutPending} logoutError={logoutError} />
      <main id="main-content" className={styles.main} tabIndex={-1} aria-labelledby="page-title"><div className={styles.contentCanvas}>{children}</div></main>
      <Footer />
    </div>
  </div>;
}
