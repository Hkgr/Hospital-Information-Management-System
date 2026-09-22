export type AuditChange = { field: string; label: string; before: string | null; after: string; before_recorded: boolean };
export type AuditEvent = {
  id: number; occurred_at: string; actor: { id: number | null; name: string };
  category: string; category_label: string; entity: string; entity_label: string;
  entity_id: number; action: string; action_label: string; reason: string | null; changes: AuditChange[];
};

export const categories = { patient_card: "بطاقة المريض", treatment: "العلاج والأدوية", directory: "الدليل", stock: "المخزون", blood_bank: "بنك الدم", accounts: "الحسابات", technical: "خطأ تقني", other: "أخرى" };
export const actions = { created: "إنشاء", updated: "تعديل", login: "تسجيل دخول", logout: "تسجيل خروج", failed: "خطأ تقني", voided: "إلغاء" };

export function formatAuditTime(iso: string, timezone: string) {
  return new Intl.DateTimeFormat("ar-SY", { dateStyle: "medium", timeStyle: "short", timeZone: timezone }).format(new Date(iso));
}
