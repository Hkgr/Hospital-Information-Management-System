import { useEffect, useState } from "react";
import { apiRequest, AuthError } from "@/features/auth/api";

export type Specialty = { id: number; name_ar: string };
export type Doctor = { id: number; code: string; name: string; starts_on: string | null; is_linked: boolean; specialties: Specialty[] };
export type Clinic = {
  id: number; facility_id: number; code: string; name_ar: string; description: string | null;
  is_active: boolean; lock_version: number; specialty: Specialty | null;
  doctor_count: number; patient_count: number; doctors_preview: { id: number; name: string }[]; patient_count_definition: string;
};
export type Page<T> = { data: T[]; meta: { page: number; per_page: number; total: number; last_page: number }; doctor_types_configured?: boolean };
export const columns = { number: "م", code: "كود العيادة", name_ar: "اسم العيادة", description: "التوصيف", doctors: "الأطباء العاملون", doctor_count: "عدد الأطباء", patient_count: "عدد المرضى" };
export type Column = keyof typeof columns;
export const columnKeys = Object.keys(columns) as Column[];

export function useClinicRequest<T>(path: string, envelope = false) {
  const [state, setState] = useState<{ key: string; data?: T; error?: string }>({ key: "" });
  const [attempt, setAttempt] = useState(0);
  const key = `${path}:${attempt}`;
  useEffect(() => {
    const controller = new AbortController();
    apiRequest<T>(path, { signal: controller.signal }, envelope ? "envelope" : "data")
      .then(data => { if (!controller.signal.aborted) setState({ key, data }); })
      .catch(error => { if (!controller.signal.aborted) setState({ key, error: error instanceof AuthError ? error.message : "تعذّر تحميل البيانات. حاول مجددًا." }); });
    return () => controller.abort();
  }, [path, envelope, key]);
  // Never show a previous facility/query while the new request is starting.
  return { ...(state.key === key ? state : {}), retry: () => setAttempt(value => value + 1) };
}

export function useDebounced(value: string) {
  const [debounced, setDebounced] = useState(value);
  useEffect(() => { const timer = setTimeout(() => setDebounced(value), 300); return () => clearTimeout(timer); }, [value]);
  return debounced;
}

export async function downloadReport(path: string, signal: AbortSignal) {
  const { blob, filename } = await apiRequest<{ blob: Blob; filename?: string }>(path, { signal }, "blob");
  if (signal.aborted) return;
  if (!filename || !/\.(xlsx|pdf)$/.test(filename)) throw new Error("Invalid report filename");
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url; link.download = filename; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
