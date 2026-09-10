"use client";

import { createContext, useContext, useEffect, useState, useSyncExternalStore } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { AuthError, currentUser, getToken, logout, subscribeSession, type Identity } from "./api";
import AppShell from "@/components/layout/AppShell";
import styles from "./login.module.css";

const IdentityContext = createContext<Identity | null>(null);
export function useIdentity() {
  const identity = useContext(IdentityContext);
  if (!identity) throw new Error("Identity requires an authenticated layout");
  return identity;
}

export default function AuthenticatedLayout({ children }: { children: React.ReactNode }) {
  const token = useSyncExternalStore(subscribeSession, getToken, () => null);
  const router = useRouter();
  useEffect(() => { if (!getToken()) router.replace("/login"); }, [token, router]);
  // A session change unmounts all previous personalized state before rendering another user.
  return token ? <AuthenticatedSession key={token}>{children}</AuthenticatedSession> : <p role="status">جارٍ التحقق من الدخول…</p>;
}

function AuthenticatedSession({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const [identity, setIdentity] = useState<Identity | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    currentUser(controller.signal).then(result => {
      if (!controller.signal.aborted) { setIdentity(result); setError(""); }
    }).catch(reason => {
      if (controller.signal.aborted) return;
      setError(reason instanceof AuthError ? reason.message : "تعذّر تحميل بيانات المستخدم.");
    });
    return () => controller.abort();
  }, [attempt]);

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

  if (identity) return <IdentityContext.Provider value={identity}><AppShell user={identity.user} onLogout={signOut} logoutPending={busy} logoutError={error}>
    {children}
  </AppShell></IdentityContext.Provider>;

  return <main className={styles.identity}>
    <Image src="/brand/logos/logo-ar-color.svg" alt="مشفى محمد بن زايد الإماراتي" width={200} height={95} style={{ height: "auto" }} priority />
    <h1>نظام إدارة المشفى</h1>
    {error && <p className={styles.error} role="alert">{error}</p>}
    {error ? <button type="button" className={styles.submit} onClick={() => { setError(""); setAttempt(value => value + 1); }}>إعادة المحاولة</button>
      : <p role="status">جارٍ التحقق من الدخول…</p>}
  </main>;
}
