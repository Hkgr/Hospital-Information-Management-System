import { LuHouse, LuDroplet, LuFolderHeart, LuStethoscope, LuHospital, LuClipboardPlus, LuPill, LuPackage, LuChartNoAxesCombined, LuHistory, LuUsers, LuSettings, LuBookOpen } from "react-icons/lu";
import type { IconType } from "react-icons";

type NavigationItem = { label: string; icon: IconType; href?: string; permission?: string; anyPermission?: string[] };

// Missing routes are presentation-only, not permissions or fabricated modules.
export const primaryNavigation: NavigationItem[] = [
  { label: "الرئيسية", icon: LuHouse, href: "/dashboard/general", permission: "dashboards.view" },
  { label: "الإحصاءات المجهلة", icon: LuChartNoAxesCombined, href: "/statistics", permission: "statistics.view" },
  { label: "مراجعة بيانات المرضى", icon: LuClipboardPlus, href: "/reception-admin", anyPermission: ["identity_corrections.review", "reception_accounts.manage", "patient_duplicates.review"] },
  { label: "بطاقات المرضى", icon: LuFolderHeart, href: "/patient-cards", anyPermission: ["patients.basic.view", "dossiers.medical.view"] },
  { label: "الزيارات", icon: LuClipboardPlus, href: "/visits", permission: "dossiers.visits.view" },
  { label: "الأطباء", icon: LuStethoscope, href: "/doctors", permission: "doctors.view" },
  { label: "العيادات", icon: LuHospital, href: "/clinics", permission: "clinics.view" },
  { label: "الخدمات والإجراءات", icon: LuClipboardPlus, href: "/services-procedures", permission: "catalog.view" },
  { label: "الأدوية", icon: LuPill, href: "/medications", permission: "catalog.view" },
  { label: "المخزون", icon: LuPackage, href: "/stock/receipts", permission: "stock.view" },
  { label: "بنك الدم", icon: LuDroplet, href: "/blood-bank", permission: "blood_bank.view" },
  { label: "تقارير", icon: LuChartNoAxesCombined, href: "/reports", permission: "reports.view" },
  { label: "السجل", icon: LuHistory, href: "/audit", permission: "audit.view" },
  { label: "المستخدمون", icon: LuUsers, href: "/users", anyPermission: ["users.view", "roles.view"] },
  { label: "الإعدادات", icon: LuSettings, href: "/settings", permission: "settings.view" },
  { label: "دليل الاستخدام", icon: LuBookOpen, href: "/guide" },
];
