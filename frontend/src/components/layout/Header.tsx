import { useEffect, useId, useRef, useState } from "react";
import { LuBell, LuChevronDown, LuLogOut, LuMenu, LuUserRound } from "react-icons/lu";
import type { User } from "@/features/auth/api";
import HeaderDateTime from "./HeaderDateTime";
import styles from "./shell.module.css";

type Props = { title: string; user: User; onOpenNavigation: () => void; navigationOpen: boolean; onLogout: () => Promise<void>; logoutPending: boolean; logoutError: string };

export default function Header({ title, user, onOpenNavigation, navigationOpen, onLogout, logoutPending, logoutError }: Props) {
  const [accountOpen, setAccountOpen] = useState(false);
  const accountRef = useRef<HTMLDivElement>(null);
  const accountButton = useRef<HTMLButtonElement>(null);
  const accountId = useId();
  const initials = user.name.trim().split(/\s+/).slice(0, 2).map(part => Array.from(part)[0]).join("");
  useEffect(() => {
    if (!accountOpen) return;
    function closeOutside(event: PointerEvent) {
      if (!accountRef.current?.contains(event.target as Node)) setAccountOpen(false);
    }
    function closeOnEscape(event: KeyboardEvent) {
      if (event.key === "Escape") { setAccountOpen(false); accountButton.current?.focus(); }
    }
    document.addEventListener("pointerdown", closeOutside);
    document.addEventListener("keydown", closeOnEscape);
    return () => { document.removeEventListener("pointerdown", closeOutside); document.removeEventListener("keydown", closeOnEscape); };
  }, [accountOpen]);

  return <header className={styles.header}>
    <div className={styles.pageHeading}>
      <button type="button" className={`${styles.iconButton} ${styles.mobileMenuButton}`} aria-label="فتح قائمة التنقل" aria-expanded={navigationOpen} aria-controls="mobile-navigation" onClick={onOpenNavigation}><LuMenu aria-hidden="true" /></button>
      <div><p className={styles.pageEyebrow}>نظام إدارة المشفى</p><h1 id="page-title">{title}</h1></div>
    </div>
    <div className={styles.headerActions}>
      <HeaderDateTime />
      <button type="button" className={styles.iconButton} aria-label="التنبيهات — غير متاحة بعد" title="التنبيهات — غير متاحة بعد" disabled><LuBell aria-hidden="true" /></button>
      <div className={styles.account} ref={accountRef} onBlur={event => {
        // Disabling the pending logout button can blur it without a new target.
        // Keep the disclosure open so a failed request can show its retry error.
        if (event.relatedTarget && !event.currentTarget.contains(event.relatedTarget as Node)) setAccountOpen(false);
      }}>
        <button ref={accountButton} type="button" className={styles.accountButton} aria-label={`حساب ${user.name}`} aria-expanded={accountOpen} aria-controls={accountId} onClick={() => setAccountOpen(value => !value)}>
          <span className={styles.avatar} aria-hidden="true">{initials || <LuUserRound />}</span>
          <span className={styles.accountName}><strong>{user.name}</strong></span>
          <LuChevronDown className={styles.accountChevron} aria-hidden="true" />
        </button>
        {accountOpen && <section className={styles.accountPanel} id={accountId} aria-label="الحساب">
          <div className={styles.accountDetails}><strong>{user.name}</strong><span dir="ltr">@{user.username}</span></div>
          {user.must_change_password && <p className={styles.accountNote}>يتطلب حسابك تغيير كلمة المرور. راجع مسؤول النظام.</p>}
          {logoutError && <p className={styles.error} role="alert">{logoutError}</p>}
          <button type="button" className={styles.logoutButton} disabled={logoutPending} onClick={() => void onLogout()}><LuLogOut aria-hidden="true" />{logoutPending ? "جارٍ تسجيل الخروج…" : "تسجيل الخروج"}</button>
        </section>}
      </div>
    </div>
  </header>;
}
