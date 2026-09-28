"use client";

import { useEffect, useRef, useState } from "react";
import { apiRequest, clearLocalSession, expireLocalSession, getToken } from "./api";
import styles from "../clinics/clinics.module.css";
import idleStyles from "./idle.module.css";

type State = { idle_timeout: number | null; remaining_seconds?: number };

/** Only trusted interaction schedules activity; the timer itself never renews. */
export default function IdleSession({ children }: { children: React.ReactNode }) {
  const [ready, setReady] = useState(false), [remaining, setRemaining] = useState<number | null>(null);
  const [started, setStarted] = useState(false);
  const [verificationError, setVerificationError] = useState(false);
  const continueSession = useRef<() => void>(() => {});
  const retryVerification = useRef<() => void>(() => {});
  useEffect(() => {
    const token = getToken();
    const abort = new AbortController();
    const channel = typeof BroadcastChannel === "undefined" ? null : new BroadcastChannel("hospital.web-idle");
    let key = "", deadline = Infinity, busy = false, activity = false, lastRenewal = 0, interactedAt = 0;
    let wall = Date.now(), monotonic = performance.now();
    if (token) void crypto.subtle.digest("SHA-256", new TextEncoder().encode(token)).then(buffer => { key = Array.from(new Uint8Array(buffer), v => v.toString(16).padStart(2, "0")).join(""); });
    async function check(renew: boolean) {
      if (busy || abort.signal.aborted || getToken() !== token) return;
      busy = true;
      const started = performance.now();
      try {
        const value = await apiRequest<State>(renew ? "session/activity" : "session", { method: renew ? "POST" : "GET", signal: abort.signal });
        if (abort.signal.aborted) return;
        deadline = value.idle_timeout === null ? Infinity : performance.now() + Math.max(0, (value.remaining_seconds ?? 0) * 1000 - (performance.now() - started));
        if (renew) { lastRenewal = performance.now(); if (key) channel?.postMessage({ key, kind: "refresh" }); }
        setRemaining(Number.isFinite(deadline) ? Math.max(0, Math.ceil((deadline - performance.now()) / 1000)) : null);
        setReady(true);
        setStarted(true);
        setVerificationError(false);
      } catch {
        // No offline extension. A committed in-flight write is never replayed
        // here; its outcome must be checked after authentication, using its UUID.
        if (!abort.signal.aborted) {
          if (Number.isFinite(deadline) && performance.now() >= deadline) expireLocalSession();
          // Before the first server snapshot a transport failure (including
          // document navigation) is not evidence of expiration. Fail closed
          // behind verification, with retry, without deleting a valid token.
          else setVerificationError(true);
        }
      } finally { busy = false; }
    }
    const interact = (event: Event) => { if (event.isTrusted && document.visibilityState === "visible") { activity = true; interactedAt = performance.now(); } };
    const resume = () => {
      if (document.visibilityState !== "visible") { activity = false; return; }
      const gap = Math.abs((Date.now() - wall) - (performance.now() - monotonic));
      if (performance.now() >= deadline || gap > 5000) { activity = false; setReady(false); }
      void check(false);
    };
    const events = ["keydown", "pointerdown", "input", "wheel"];
    for (const event of events) window.addEventListener(event, interact, { passive: true });
    window.addEventListener("focus", resume); document.addEventListener("visibilitychange", resume);
    if (channel) channel.onmessage = event => {
      if (!key || event.data?.key !== key) return;
      if (event.data.kind === "ended") expireLocalSession();
      else if (event.data.kind === "refresh") void check(false);
    };
    continueSession.current = () => { activity = false; void check(true); };
    retryVerification.current = () => void check(false);
    void check(false);
    const timer = setInterval(() => {
      const now = performance.now();
      const gap = Math.abs((Date.now() - wall) - (now - monotonic)); wall = Date.now(); monotonic = now;
      if (now >= deadline || gap > 5000) {
        activity = false; setReady(false); void check(false); return;
      }
      setRemaining(Number.isFinite(deadline) ? Math.max(0, Math.ceil((deadline - now) / 1000)) : null);
      if (now - interactedAt > 20000) activity = false;
      if (activity && !busy && now - lastRenewal >= 20000 && document.visibilityState === "visible") { activity = false; void check(true); }
    }, 1000);
    return () => {
      abort.abort(); clearInterval(timer);
      for (const event of events) window.removeEventListener(event, interact);
      window.removeEventListener("focus", resume); document.removeEventListener("visibilitychange", resume);
      if (key && getToken() !== token) channel?.postMessage({ key, kind: "ended" });
      channel?.close();
    };
  }, []);
  // A peer tab may have renewed while this tab slept. Keep the mounted draft
  // hidden during verification; actual expiration unmounts the session owner.
  return <>{!ready && (verificationError ? <section className={styles.status}><p role="alert">تعذّر التحقق من الجلسة. لن تُعرض البيانات قبل نجاح الاتصال بالخادم.</p><button className={styles.primary} onClick={() => retryVerification.current()}>إعادة التحقق</button><button className={styles.secondary} onClick={clearLocalSession}>تسجيل الخروج من هذا المتصفح</button></section> : <p role="status">جارٍ التحقق من صلاحية الجلسة…</p>)}<div hidden={!ready} inert={!ready}>{ready && remaining !== null && remaining <= 30 && <section className={idleStyles.warning} role="alert" aria-label="تنبيه انتهاء الجلسة"><p>ستنتهي الجلسة بسبب الخمول خلال {remaining} ثانية. التغييرات غير المحفوظة لن تُستعاد تلقائيًا.</p><button className={styles.primary} onClick={() => continueSession.current()}>متابعة الجلسة</button></section>}{started && children}</div></>;
}
