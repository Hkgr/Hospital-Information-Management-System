import { apiRequest, AuthError } from "@/features/auth/api";

export type NamedCount = { key: string; label: string; value: number };
export type Rank = { id: number; name_ar: string; visit_count: number };
export type ReportPatient = {
  id: number; dossier_id: number | null; patient_code: string; patient_name: string;
  visit_count: number; last_visit_on: string;
};
export type ReportPeriod = { key: "day" | "week" | "custom"; label: string; starts_on: string; ends_on: string };
export type FacilityReport = {
  facility: { id: number; code: string; name_ar: string; timezone: string };
  period: ReportPeriod;
  counters: NamedCount[]; visit_status: NamedCount[]; series: NamedCount[]; mix: NamedCount[];
  clinics: Rank[]; doctors: Rank[]; procedures: Rank[]; patients: ReportPatient[];
  patients_definition: string;
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return !!value && typeof value === "object";
}
function isNamed(value: unknown): value is NamedCount {
  return isRecord(value) && typeof value.key === "string" && typeof value.label === "string" && typeof value.value === "number";
}
function isRank(value: unknown): value is Rank {
  return isRecord(value) && typeof value.id === "number" && typeof value.name_ar === "string" && typeof value.visit_count === "number";
}
function isPatient(value: unknown): value is ReportPatient {
  return isRecord(value) && typeof value.id === "number" && (value.dossier_id === null || typeof value.dossier_id === "number")
    && typeof value.patient_code === "string" && typeof value.patient_name === "string"
    && typeof value.visit_count === "number" && typeof value.last_visit_on === "string";
}
export function isFacilityReport(value: unknown): value is FacilityReport {
  if (!isRecord(value) || !isRecord(value.facility) || !isRecord(value.period)) return false;
  return typeof value.facility.id === "number" && typeof value.period.key === "string"
    && Array.isArray(value.counters) && value.counters.every(isNamed)
    && Array.isArray(value.visit_status) && value.visit_status.every(isNamed)
    && Array.isArray(value.series) && value.series.every(isNamed)
    && Array.isArray(value.mix) && value.mix.every(isNamed)
    && Array.isArray(value.clinics) && value.clinics.every(isRank)
    && Array.isArray(value.doctors) && value.doctors.every(isRank)
    && Array.isArray(value.procedures) && value.procedures.every(isRank)
    && Array.isArray(value.patients) && value.patients.every(isPatient)
    && typeof value.patients_definition === "string";
}

export function reportsPath(facility: number, period: string, from: string, to: string) {
  const q = new URLSearchParams({ facility_id: String(facility), period });
  if (period === "custom") {
    if (from) q.set("from", from);
    if (to) q.set("to", to);
  }
  return `reports?${q}`;
}

export async function downloadReportPdf(path: string, signal: AbortSignal) {
  const { blob, filename } = await apiRequest<{ blob: Blob; filename?: string }>(path.replace("reports?", "reports/export/pdf?"), { signal }, "blob");
  if (signal.aborted) return;
  if (!filename || !filename.endsWith(".pdf")) throw new AuthError(502, "INVALID_RESPONSE", "تعذّر قراءة ملف التقرير.");
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url; a.download = filename; a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
