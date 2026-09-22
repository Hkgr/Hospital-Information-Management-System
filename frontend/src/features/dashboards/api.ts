import { apiRequest, AuthError, type User, type Identity } from "@/features/auth/api";

export type Facility = Identity["access"][number]["facility"];
export type Dashboard = { key: string; title: string; requires_facility: boolean; facilities: Facility[]; default_facility_id: number | null };
export type Catalog = { dashboards: Dashboard[]; default_dashboard_key: string | null };
export type DashboardLink = { key: string; title: string };
export type DashboardCounter = { key: string; label: string; value: number };
export type DashboardRank = { id: number; name_ar: string; visit_count: number };
export type DashboardAppointment = {
  id: number; planned_on: string; session_number: number; dossier_id: number;
  patient_code: string; patient_name: string; clinic_name: string; doctor_name: string;
};
export type DashboardStats = {
  counters: DashboardCounter[]; visit_status: DashboardCounter[]; dossier_status: DashboardCounter[];
  clinics: DashboardRank[]; doctors: DashboardRank[]; appointments: DashboardAppointment[];
};
export type DashboardData = {
  dashboard: Dashboard; user: User; facilities: Facility[]; selected_facility_id: number | null;
  links: DashboardLink[]; stats: DashboardStats;
};

export function emptyDashboardStats(): DashboardStats {
  return { counters: [], visit_status: [], dossier_status: [], clinics: [], doctors: [], appointments: [] };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === "object";
}
function isNamedCount(value: unknown): value is DashboardCounter {
  return isRecord(value) && typeof value.key === "string" && typeof value.label === "string" && typeof value.value === "number";
}
function isRank(value: unknown): value is DashboardRank {
  return isRecord(value) && typeof value.id === "number" && typeof value.name_ar === "string" && typeof value.visit_count === "number";
}
function isAppointment(value: unknown): value is DashboardAppointment {
  return isRecord(value) && typeof value.id === "number" && typeof value.planned_on === "string" && typeof value.session_number === "number"
    && typeof value.dossier_id === "number" && typeof value.patient_code === "string" && typeof value.patient_name === "string"
    && typeof value.clinic_name === "string" && typeof value.doctor_name === "string";
}
export function isDashboardStats(value: unknown): value is DashboardStats {
  if (!isRecord(value)) return false;
  return Array.isArray(value.counters) && value.counters.every(isNamedCount)
    && Array.isArray(value.visit_status) && value.visit_status.every(isNamedCount)
    && Array.isArray(value.dossier_status) && value.dossier_status.every(isNamedCount)
    && Array.isArray(value.clinics) && value.clinics.every(isRank)
    && Array.isArray(value.doctors) && value.doctors.every(isRank)
    && Array.isArray(value.appointments) && value.appointments.every(isAppointment);
}

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
