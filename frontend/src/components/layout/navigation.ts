import { LuDroplet, LuFolderHeart, LuStethoscope, LuHospital, LuClipboardPlus, LuPill, LuChartNoAxesCombined, LuHistory } from "react-icons/lu";
import type { IconType } from "react-icons";

type NavigationItem = { label: string; icon: IconType; href?: string; permission?: string };

// Missing routes are presentation-only, not permissions or fabricated modules.
export const primaryNavigation: NavigationItem[] = [
  { label: "بطاقة المرضى", icon: LuFolderHeart, href: "/patient-cards", permission: "dossiers.view" },
  { label: "الزيارات", icon: LuClipboardPlus, href: "/visits", permission: "dossiers.view" },
  { label: "الأطباء", icon: LuStethoscope, href: "/doctors", permission: "doctors.view" },
  { label: "العيادات", icon: LuHospital, href: "/clinics", permission: "clinics.view" },
  { label: "الخدمات والإجراءات", icon: LuClipboardPlus, href: "/services-procedures", permission: "catalog.view" },
  { label: "الأدوية", icon: LuPill },
  { label: "بنك الدم", icon: LuDroplet, href: "/blood-bank", permission: "blood_bank.view" },
  { label: "تقارير", icon: LuChartNoAxesCombined },
  { label: "السجل", icon: LuHistory },
];
