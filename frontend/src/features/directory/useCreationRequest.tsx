"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import styles from "../clinics/clinics.module.css";

type Attempt = { path: string; body: string; requestId: string };

/** One logical create, including an uncertain response, owns one immutable UUID/payload. */
export function useCreationRequest() {
  const attempt = useRef<Attempt | null>(null);
  const running = useRef(false);
  const [uncertain, setUncertain] = useState(false);
  async function send<T>(entry: Attempt, signal?: AbortSignal): Promise<T> {
    if (running.current) throw new AuthError(409, "CREATION_PENDING", "انتظر نتيجة محاولة الحفظ الحالية.");
    running.current = true;
    try {
      const result = await apiRequest<T>(entry.path, { method: "POST", signal, body: JSON.stringify({ ...JSON.parse(entry.body), request_id: entry.requestId }) });
      setUncertain(false);
      return result;
    } catch (reason) {
      // Validation is known not to commit. Network/server/abort results remain uncertain.
      if (reason instanceof AuthError && reason.status === 422) { attempt.current = null; setUncertain(false); }
      else setUncertain(true);
      throw reason;
    } finally { running.current = false; }
  }
  async function request<T>(path: string, init: RequestInit): Promise<T> {
    if (init.method !== "POST") return apiRequest<T>(path, init);
    const body = String(init.body);
    if (attempt.current && (attempt.current.body !== body || attempt.current.path !== path)) {
      throw new AuthError(409, "CREATION_UNCERTAIN", "تغيّرت المسودة بعد محاولة حفظ غير مؤكدة. استعد نتيجة الطلب السابق أولًا؛ لن ننشئ سجلًا ثانيًا.");
    }
    const entry = attempt.current ?? { path, body, requestId: crypto.randomUUID() };
    attempt.current = entry;
    return send<T>(entry, init.signal ?? undefined);
  }
  async function recover<T>(signal: AbortSignal): Promise<T> {
    if (!attempt.current) throw new Error("No pending creation request.");
    return send<T>(attempt.current, signal);
  }
  return { request, recover, uncertain };
}

export function CreationRecovery<T>({ creation, onSaved }: { creation: ReturnType<typeof useCreationRequest>; onSaved: (row: T) => void }) {
  const [busy, setBusy] = useState(false), [error, setError] = useState("");
  const pending = useRef<AbortController | null>(null);
  useEffect(() => () => pending.current?.abort(), []);
  if (!creation.uncertain) return null;
  async function recover() {
    if (pending.current) return;
    const active = new AbortController(); pending.current = active; setBusy(true); setError("");
    try { const row = await creation.recover<T>(active.signal); if (!active.signal.aborted) onSaved(row); }
    catch (reason) { if (!active.signal.aborted) setError(reason instanceof Error ? reason.message : "تعذّر استعادة النتيجة. حاول مجددًا."); }
    finally { if (!active.signal.aborted) { pending.current = null; setBusy(false); } }
  }
  return <div role="status" className={styles.scopeNote}><p>نتيجة الحفظ غير مؤكدة. استعد نتيجة الطلب الأصلي بمعرّفه قبل بدء إنشاء آخر؛ لا تعتمد المطابقة على الاسم. تبقى مسودتك محفوظة أثناء المحاولة.</p><button type="button" disabled={busy} className={styles.secondary} onClick={() => void recover()}>{busy ? "جارٍ استعادة النتيجة…" : "استعادة نتيجة الحفظ"}</button>{error && <p role="alert">{error}</p>}</div>;
}
