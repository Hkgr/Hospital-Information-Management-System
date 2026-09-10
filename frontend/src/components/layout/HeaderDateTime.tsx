"use client";

import { useSyncExternalStore } from "react";
import styles from "./shell.module.css";

const refreshInterval = 30_000;
const locale = "ar-SY";
const timeZone = "Asia/Damascus";
const fullDate = new Intl.DateTimeFormat(locale, { timeZone, weekday: "long", day: "numeric", month: "long", year: "numeric" });
const shortDate = new Intl.DateTimeFormat(locale, { timeZone, day: "numeric", month: "long" });
const time = new Intl.DateTimeFormat(locale, { timeZone, hour: "2-digit", minute: "2-digit", hour12: true });

function subscribe(onChange: () => void) {
  const timer = window.setInterval(onChange, refreshInterval);
  // Refresh promptly when returning to a tab whose timers were throttled.
  document.addEventListener("visibilitychange", onChange);
  return () => {
    window.clearInterval(timer);
    document.removeEventListener("visibilitychange", onChange);
  };
}

// A stable primitive snapshot within each 30-second window. Seconds aren't displayed.
function getSnapshot() { return Math.floor(Date.now() / refreshInterval); }
function getServerSnapshot() { return null; }

export default function HeaderDateTime() {
  const tick = useSyncExternalStore(subscribe, getSnapshot, getServerSnapshot);
  const now = tick === null ? null : new Date(tick * refreshInterval);

  // Server and initial hydration both render the same placeholder, then read the clock.
  return <div className={styles.dateTime} role="group" aria-label="التاريخ والوقت بتوقيت دمشق">
    {now ? <>
      <time className={styles.date} dateTime={now.toISOString()}>
        <span className={styles.fullDate}>{fullDate.format(now)}</span>
        <span className={styles.shortDate}>{shortDate.format(now)}</span>
      </time>
      <time className={styles.time} dateTime={now.toISOString()}>{time.format(now)}</time>
    </> : <span className={styles.time} aria-label="جارٍ تحميل الوقت">—</span>}
  </div>;
}
