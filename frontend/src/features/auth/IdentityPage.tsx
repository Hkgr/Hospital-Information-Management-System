"use client";

import { useEffect, useState } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { AuthError, currentUser, getToken, logout, type Identity } from "./api";
import styles from "./login.module.css";

// The application has no medical dashboard yet. This small authenticated entry
// confirms the real API identity without inventing modules or role-based routes.
export default function IdentityPage() {
  const router = useRouter();
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    if (!getToken()) { router.replace("/login"); return; }
    let cancelled = false;
    currentUser().then(result => {
      if (!cancelled) { setIdentity(result); setError(""); }
    }).catch(reason => {
      if (cancelled) return;
      if (reason instanceof AuthError && reason.status === 401) router.replace("/login");
      setError(reason instanceof AuthError ? reason.message : "تعذّر تحميل بيانات المستخدم.");
    });
    return () => { cancelled = true; };
  }, [router, attempt]);

  async function signOut() {
    if (busy) return;
    setBusy(true);
    setError("");
    try { await logout(); router.replace("/login"); }
    catch (reason) {
      if (!getToken()) router.replace("/login");
      else setError(reason instanceof AuthError ? reason.message : "تعذّر تسجيل الخروج. حاول مجددًا.");
    } finally { setBusy(false); }
  }

  return <main className={styles.identity}>
    <Image src="/brand/logos/logo-ar-color.svg" alt="مشفى محمد بن زايد الإماراتي" width={200} height={95} style={{ height: "auto" }} priority />
    <h1>{identity ? `مرحبًا، ${identity.user.name}` : "نظام إدارة المشفى"}</h1>
    {error && <p className={styles.error} role="alert">{error}</p>}
    {identity ? <>
      <p>تم تسجيل دخولك بنجاح.</p>
      {identity.user.must_change_password && <p>يتطلب حسابك تغيير كلمة المرور. راجع مسؤول النظام.</p>}
      {identity.access.length > 0 && <ul aria-label="المنشآت المتاحة">{identity.access.map(entry => <li key={entry.facility.id}>{entry.facility.name_ar}</li>)}</ul>}
      <button type="button" className={styles.submit} onClick={signOut} disabled={busy}>{busy ? "جارٍ تسجيل الخروج…" : "تسجيل الخروج"}</button>
    </> : error ? <button type="button" className={styles.submit} onClick={() => { setError(""); setAttempt(value => value + 1); }}>إعادة المحاولة</button>
      : <p role="status">جارٍ التحقق من الدخول…</p>}
  </main>;
}
