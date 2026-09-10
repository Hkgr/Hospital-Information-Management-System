import { LuLayoutDashboard, LuUsersRound, LuStethoscope, LuSiren, LuBedDouble,
  LuScissors, LuFlaskConical, LuScanLine, LuPill, LuReceipt, LuChartNoAxesCombined, LuSettings } from "react-icons/lu";
import type { IconType } from "react-icons";

type NavigationItem = { label: string; icon: IconType; href?: string };

// Missing routes are presentation-only, not permissions or fabricated modules.
export const primaryNavigation: NavigationItem[] = [
  { label: "لوحة التحكم", icon: LuLayoutDashboard, href: "/" },
  { label: "الاستقبال", icon: LuUsersRound },
  { label: "العيادات", icon: LuStethoscope },
  { label: "الإسعاف", icon: LuSiren },
  { label: "التنويم", icon: LuBedDouble },
  { label: "العمليات", icon: LuScissors },
  { label: "المخبر", icon: LuFlaskConical },
  { label: "الأشعة", icon: LuScanLine },
  { label: "الصيدلية", icon: LuPill },
  { label: "الفوترة", icon: LuReceipt },
  { label: "التقارير", icon: LuChartNoAxesCombined },
];

export const administrationNavigation: NavigationItem[] = [
  { label: "المستخدمون", icon: LuUsersRound },
  { label: "الإعدادات", icon: LuSettings },
];
