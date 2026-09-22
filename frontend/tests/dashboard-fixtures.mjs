// Test-only API data. Production uses Laravel and never imports this file.
export function emptyDashboardStats() {
  return { counters: [], visit_status: [], dossier_status: [], clinics: [], doctors: [], appointments: [] };
}

export function sampleDashboardStats() {
  return {
    counters: [
      { key: "dossiers", label: "بطاقات المرضى", value: 128 },
      { key: "visits", label: "الزيارات", value: 364 },
      { key: "clinics", label: "العيادات", value: 12 },
      { key: "doctors", label: "الأطباء", value: 19 },
      { key: "appointments", label: "المواعيد القادمة", value: 3 },
    ],
    visit_status: [
      { key: "complete", label: "مكتملة", value: 290 },
      { key: "draft", label: "مسودة", value: 74 },
    ],
    dossier_status: [
      { key: "active", label: "فعّالة", value: 101 },
      { key: "draft", label: "مسودة", value: 27 },
    ],
    clinics: [
      { id: 3, name_ar: "عيادة الأورام", visit_count: 88 },
      { id: 5, name_ar: "عيادة الباطنة", visit_count: 54 },
    ],
    doctors: [
      { id: 7, name_ar: "د. ليلى الحسن", visit_count: 41 },
      { id: 8, name_ar: "د. مازن خليل", visit_count: 33 },
    ],
    appointments: [
      { id: 21, planned_on: "2099-01-15", session_number: 2, dossier_id: 9, patient_code: "P-21", patient_name: "نورا العبد الله", clinic_name: "عيادة الأورام", doctor_name: "د. ليلى الحسن" },
    ],
  };
}

export function dashboardFixture(user, facilities = [], stats = emptyDashboardStats(), links = []) {
  const dashboard = { key: "general", title: "لوحة التحكم", requires_facility: false, facilities, default_facility_id: null };
  return {
    catalog: { dashboards: [dashboard], default_dashboard_key: "general" },
    detail: { dashboard, user, facilities, selected_facility_id: null, links, stats },
  };
}
