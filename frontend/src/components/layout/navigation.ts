import { LuFolderHeart, LuUsersRound, LuStethoscope, LuClipboardPlus, LuPill, LuChartNoAxesCombined, LuHistory } from "react-icons/lu";
import type { IconType } from "react-icons";

type NavigationItem = { label: string; icon: IconType; href?: string };

// Missing routes are presentation-only, not permissions or fabricated modules.
export const primaryNavigation: NavigationItem[] = [
  { label: "الإضبارات", icon: LuFolderHeart },
  { label: "المرضى", icon: LuUsersRound },
  { label: "الأطباء والعيادات", icon: LuStethoscope },
  { label: "الخدمات والإجراءات", icon: LuClipboardPlus },
  { label: "الأدوية", icon: LuPill },
  { label: "تقارير", icon: LuChartNoAxesCombined },
  { label: "السجل", icon: LuHistory },
];
