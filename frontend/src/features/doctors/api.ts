export { useClinicRequest as useDirectoryRequest, useDebounced, downloadReport } from "../clinics/api";
export type { Page, Specialty } from "../clinics/api";

export type ClinicLink = { id: number; code: string; name_ar: string; starts_on: string | null; is_linked: boolean; can_view: boolean };
export type Doctor = {
  id: number; code: string; name: string; description: string | null; staff_type: { id: number; code: string; name_ar: string };
  specialties: { id: number; name_ar: string; is_active?: boolean }[]; license_no: string | null; phone: string | null;
  is_active: boolean; archived_at: string | null; lock_version: number; clinic_count: number; patient_count: number;
  clinics_preview: Pick<ClinicLink, "id" | "code" | "name_ar">[]; patient_count_definition: string;
};
export type Capabilities = { create: boolean; update: boolean; delete: boolean; link: boolean; export: boolean; view_clinics: boolean };
export type Options = { staff_types: Doctor["staff_type"][]; specialties: Doctor["specialties"]; doctor_types_configured: boolean; capabilities: Capabilities };
export const columns = { number: "م", code: "كود الطبيب", name: "اسم الطبيب", specialties: "التخصصات", description: "التوصيف المهني", clinics: "العيادات الحالية", clinic_count: "عدد العيادات", patient_count: "عدد المرضى", is_active: "الحالة" };
export type Column = keyof typeof columns;
export const columnKeys = Object.keys(columns) as Column[];
