import { apiRequest, AuthError, type User, type Identity } from "@/features/auth/api";

export type Facility = Identity["access"][number]["facility"];
export type Dashboard = { key: string; title: string; requires_facility: boolean; facilities: Facility[]; default_facility_id: number | null };
export type Catalog = { dashboards: Dashboard[]; default_dashboard_key: string | null };
export type DashboardData = { dashboard: Dashboard; user: User; facilities: Facility[]; selected_facility_id: number | null; links: { key: string; title: string }[] };

export async function dashboardCatalog(signal: AbortSignal): Promise<Catalog> {
  const data = await apiRequest<Catalog>("dashboards", { signal });
  if (!Array.isArray(data.dashboards) || (data.default_dashboard_key !== null && typeof data.default_dashboard_key !== "string")) throw invalidResponse();
  return data;
}
export function dashboardDetail(key: string, facility: number | null, signal: AbortSignal): Promise<DashboardData> {
  const query = facility === null ? "" : `?facility_id=${facility}`;
  return apiRequest<DashboardData>(`dashboards/${encodeURIComponent(key)}${query}`, { signal });
}
export function invalidResponse() { return new AuthError(502, "INVALID_RESPONSE", "تعذّر قراءة بيانات لوحة التحكم."); }
