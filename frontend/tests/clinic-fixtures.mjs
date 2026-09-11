export const facility = { id: 1, code: "TEST-A", name_ar: "مشفى محمد بن زايد الإماراتي — منشأة اختبار", timezone: "Asia/Damascus" };
export const permissions = ["view", "create", "update", "delete", "export"].map(action => `clinics.${action}`);
export const user = { id: 91, staff_id: null, username: "clinic-test", name: "مستخدم الاختبار", email: null, must_change_password: false, last_login_at: null };
export const doctors = ["أحمد الاختباري", "ليلى التجريبية", "سامر النموذجي"].map((name, index) => ({ id: index + 1, name, code: `D00${index + 1}`, starts_on: "2026-09-01", is_linked: index < 2, specialties: [{ id: 1, name_ar: "الطب الداخلي" }] }));
export const clinics = ["العيادة الداخلية", "عيادة الأطفال", "العيادة القلبية", "عيادة الجراحة العامة", "العيادة العظمية", "العيادة النسائية"].map((name_ar, index) => ({
  id: index + 1, facility_id: 1, code: `00${index + 1}`, name_ar, description: "بيانات اختبارية فقط — تقديم الرعاية والتقييم الطبي والمتابعة ضمن العيادة، بالتنسيق مع الأطباء المرتبطين بها.",
  is_active: index !== 4, lock_version: 1, specialty: { id: 1, name_ar: "الطب الداخلي" }, doctor_count: 2, patient_count: 12 + index,
  doctors_preview: doctors.slice(0, 2).map(d => ({ id: d.id, name: d.name })), patient_count_definition: "عدد المرضى المختلفين في الزيارات المكتملة وغير الملغاة المرتبطة مباشرة بالعيادة؛ تكرار الزيارة لا يزيد العدد.",
}));
export function paginated(data, page = 1, per_page = 20, total = data.length) { return { data, meta: { page, per_page, total, last_page: Math.max(1, Math.ceil(total / per_page)) } }; }
