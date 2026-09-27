"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";

export const identityLabels: Record<string, string> = { first_name: "الاسم الأول", family_name: "اسم العائلة", father_name: "اسم الأب", mother_name: "اسم الأم", birth_date: "تاريخ الميلاد", birth_date_accuracy: "دقة الميلاد", gender: "الجنس", phone: "الهاتف", alt_phone: "هاتف بديل", address_line: "عنوان السكن" };
export const permissionLabels: Record<string, string> = { "reception.view": "عرض الاستقبال", "reception.register": "تسجيل بطاقة وزيارة أولى", "reception.correct": "التصحيح خلال المهلة", "reception.corrections.request": "طلب تصحيح" };
export const statusLabel = (status: string) => ({ pending: "قيد المراجعة", approved: "موافق عليه", rejected: "مرفوض" }[status] || status);

export function useReviewWrite() {
  const pending = useRef<AbortController | null>(null);
  const replay = useRef<{ key: string; id: string } | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => () => pending.current?.abort(), []);
  async function save<T>(path: string, body: object, method = "POST"): Promise<T | undefined> {
    if (pending.current) return;
    const key = JSON.stringify({ path, body, method });
    if (replay.current?.key !== key) replay.current = { key, id: crypto.randomUUID() };
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true); setError(null);
    try {
      const result = await apiRequest<T>(path, { method, body: JSON.stringify({ ...body, request_id: replay.current.id }), signal: controller.signal });
      if (!controller.signal.aborted) return result;
    } catch (reason) {
      if (!controller.signal.aborted) setError(reason instanceof AuthError ? reason.message : "تعذّر الحفظ. بقيت المسودة محفوظة؛ أعد المحاولة.");
    } finally {
      if (pending.current === controller) pending.current = null;
      if (!controller.signal.aborted) setBusy(false);
    }
  }
  return { save, busy, error };
}
