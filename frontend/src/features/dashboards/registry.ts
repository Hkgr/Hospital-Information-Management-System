import GeneralDashboard from "./GeneralDashboard";
import type { Dashboard } from "./api";

// Keys map to compiled local components. API values and query strings are never URLs.
export const dashboardViews = { general: GeneralDashboard };
export function knownDashboard(key: string): key is keyof typeof dashboardViews {
  return Object.hasOwn(dashboardViews, key);
}
export function dashboardPath(dashboard: Dashboard, facility = dashboard.default_facility_id): string | null {
  if (!knownDashboard(dashboard.key)) return null;
  if (facility !== null && (!Number.isSafeInteger(facility) || facility < 1 || !dashboard.facilities.some(item => item.id === facility))) return null;
  if (dashboard.requires_facility && facility === null) return null;
  return `/dashboard/${dashboard.key}${facility === null ? "" : `?facility_id=${facility}`}`;
}
