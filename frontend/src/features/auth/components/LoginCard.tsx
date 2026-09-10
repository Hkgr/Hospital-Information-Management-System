import { useRef, useState, type FormEvent } from "react";
import Image from "next/image";
import { useRouter } from "next/navigation";
import { motion, useMotionValue, useMotionTemplate, useTransform, useSpring, useReducedMotion } from "framer-motion";
import { FaUser, FaLock, FaEye, FaEyeSlash, FaSignInAlt } from "react-icons/fa";
import { AuthError, login } from "../api";
import styles from "../login.module.css";

export default function LoginCard() {
  const [showPassword, setShowPassword] = useState(false);
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [fields, setFields] = useState<AuthError["fields"]>({});
  const submitting = useRef(false);
  const usernameInput = useRef<HTMLInputElement>(null);
  const passwordInput = useRef<HTMLInputElement>(null);
  const card = useRef<HTMLDivElement>(null);
  const router = useRouter();
  const reduced = useReducedMotion();
  const mouseX = useMotionValue(0);
  const mouseY = useMotionValue(0);
  const rotateX = useSpring(useTransform(mouseY, [-180, 180], [9, -9]), { stiffness: 180, damping: 28 });
  const rotateY = useSpring(useTransform(mouseX, [-180, 180], [-9, 9]), { stiffness: 180, damping: 28 });
  const glareX = useTransform(mouseX, [-180, 180], ["0%", "100%"]);
  const glareY = useTransform(mouseY, [-180, 180], ["0%", "100%"]);
  const glare = useMotionTemplate`radial-gradient(circle at ${glareX} ${glareY}, rgba(255,255,255,.14) 0%, transparent 60%)`;

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (submitting.current) return;
    const invalid: AuthError["fields"] = {};
    if (!username.trim() || username.trim().length > 60) invalid.username = "أدخل اسم مستخدم لا يتجاوز 60 محرفًا.";
    if (!password) invalid.password = "أدخل كلمة المرور.";
    setFields(invalid);
    setError("");
    if (Object.keys(invalid).length) {
      (invalid.username ? usernameInput : passwordInput).current?.focus();
      return;
    }
    submitting.current = true;
    setLoading(true);
    try {
      await login(username, password);
      setPassword("");
      router.replace("/");
    } catch (reason) {
      const failure = reason instanceof AuthError ? reason : new AuthError(0, "UNKNOWN", "تعذّر تسجيل الدخول. حاول مجددًا.");
      setError(failure.message);
      setFields(failure.fields);
      if (failure.fields.username) usernameInput.current?.focus();
      else if (failure.fields.password) passwordInput.current?.focus();
    } finally {
      submitting.current = false;
      setLoading(false);
    }
  }

  return <motion.div ref={card} className={styles.tilt} style={{ rotateX, rotateY }}
    onPointerMove={event => {
      if (reduced || event.pointerType !== "mouse" || !window.matchMedia("(hover: hover) and (pointer: fine)").matches) return;
      const bounds = card.current!.getBoundingClientRect();
      mouseX.set(event.clientX - bounds.left - bounds.width / 2);
      mouseY.set(event.clientY - bounds.top - bounds.height / 2);
    }} onPointerLeave={() => { mouseX.set(0); mouseY.set(0); }}>
    <motion.div className={styles.glare} style={{ background: glare }} aria-hidden="true" />
    <section className={styles.card} aria-labelledby="login-heading">
      <div className={styles.topBar} aria-hidden="true" />
      <div className={styles.flagCorner} aria-hidden="true"><div className={styles.flagRibbon}>
        <span>★</span><span>★</span><span>★</span>
      </div></div>
      <header className={styles.brand}>
        <div className={styles.markRing}><div className={styles.markInner}>
          <Image src="/brand/logos/mark-color.svg" alt="" width={66} height={74} priority />
        </div></div>
        <div className={styles.wordmark}>
          <Image src="/brand/logos/logo-ar-color.svg" alt="مشفى محمد بن زايد الإماراتي" width={200} height={95} priority />
          <p>نظام إدارة المشفى</p>
        </div>
      </header>
      <div className={styles.divider}><span /><h1 id="login-heading">تسجيل الدخول</h1><span /></div>
      <form className={styles.form} onSubmit={submit} noValidate aria-busy={loading}>
        {error && <p className={styles.error} role="alert">{error}</p>}
        <div>
          <label className={styles.srOnly} htmlFor="username">اسم المستخدم</label>
          <div className={`${styles.field} ${username ? styles.filled : ""}`}>
            <FaUser className={styles.fieldIcon} aria-hidden="true" />
            <input ref={usernameInput} id="username" name="username" type="text" autoComplete="username" autoCapitalize="none" spellCheck={false}
              placeholder="اسم المستخدم" value={username} onChange={e => setUsername(e.target.value)}
              maxLength={60} required readOnly={loading} aria-invalid={!!fields.username} aria-describedby={fields.username ? "username-error" : undefined} />
          </div>
          {fields.username && <p id="username-error" className={styles.fieldError} role="alert">{fields.username}</p>}
        </div>
        <div>
          <label className={styles.srOnly} htmlFor="password">كلمة المرور</label>
          <div className={`${styles.field} ${password ? styles.filled : ""}`}>
            <FaLock className={styles.fieldIcon} aria-hidden="true" />
            <input ref={passwordInput} id="password" name="password" type={showPassword ? "text" : "password"} autoComplete="current-password"
              className={styles.password} placeholder="كلمة المرور" value={password} onChange={e => setPassword(e.target.value)} required readOnly={loading}
              aria-invalid={!!fields.password} aria-describedby={fields.password ? "password-error" : undefined} />
            <button type="button" className={styles.showPassword} aria-label={showPassword ? "إخفاء كلمة المرور" : "إظهار كلمة المرور"}
              aria-pressed={showPassword} aria-controls="password" onClick={() => setShowPassword(value => !value)}>
              {showPassword ? <FaEyeSlash aria-hidden="true" /> : <FaEye aria-hidden="true" />}
            </button>
          </div>
          {fields.password && <p id="password-error" className={styles.fieldError} role="alert">{fields.password}</p>}
        </div>
        <button className={styles.submit} type="submit" disabled={loading}>
          <span className={styles.shimmer} aria-hidden="true" />
          {loading ? <><span className={styles.spinner} aria-hidden="true" /><span>جارٍ تسجيل الدخول</span></>
            : <><FaSignInAlt aria-hidden="true" /><span>دخول</span></>}
        </button>
        <span className={styles.srOnly} role="status">{loading ? "جارٍ تسجيل الدخول، يرجى الانتظار." : ""}</span>
      </form>
      <footer className={styles.footer}><span>© 2026</span><span aria-hidden="true">·</span><span>كل الحقوق محفوظة</span></footer>
    </section>
  </motion.div>;
}
