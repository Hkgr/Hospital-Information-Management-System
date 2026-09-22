"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { motion, useReducedMotion } from "framer-motion";
import type { DashboardCounter, DashboardRank } from "./api";
import styles from "./dashboard.module.css";

const tones = ["teal", "blue", "action", "amber", "coral", "violet"] as const;

export function CounterCard({ item, index }: { item: DashboardCounter; index: number }) {
  const value = useCount(item.value);
  return <article className={styles.counter} data-tone={tones[index % tones.length]}>
    <p>{item.label}</p>
    <strong aria-label={`${item.label}: ${item.value}`}>{value.toLocaleString("ar-SY")}</strong>
  </article>;
}

export function DonutChart({ title, items }: { title: string; items: DashboardCounter[] }) {
  const total = items.reduce((sum, item) => sum + item.value, 0);
  const radius = 42;
  const circumference = 2 * Math.PI * radius;
  const segments = items.map((item, index) => {
    const dash = total === 0 ? 0 : (item.value / total) * circumference;
    const offset = items.slice(0, index).reduce((sum, entry) => sum + (total === 0 ? 0 : (entry.value / total) * circumference), 0);
    return { item, index, dash, offset };
  });
  return <figure className={styles.donut}>
    <svg viewBox="0 0 120 120" role="img" aria-label={`${title} — الإجمالي ${total}`}>
      <circle className={styles.donutTrack} cx="60" cy="60" r={radius} />
      {segments.map(({ item, index, dash, offset }) => <circle key={item.key} className={styles.donutSegment} data-tone={tones[index % tones.length]}
        cx="60" cy="60" r={radius} strokeDasharray={`${dash} ${circumference - dash}`} strokeDashoffset={-offset}
        transform="rotate(-90 60 60)" />)}
      <text className={styles.donutValue} x="60" y="56">{total.toLocaleString("ar-SY")}</text>
      <text className={styles.donutCaption} x="60" y="74">الإجمالي</text>
    </svg>
    <figcaption>
      <h3>{title}</h3>
      <ul>{items.map((item, index) => <li key={item.key} data-tone={tones[index % tones.length]}>
        <span>{item.label}</span><strong>{item.value.toLocaleString("ar-SY")}</strong>
      </li>)}</ul>
    </figcaption>
  </figure>;
}

export function BarChart({ title, items, hrefFor }: { title: string; items: DashboardRank[]; hrefFor?: (id: number) => string }) {
  const max = Math.max(1, ...items.map(item => item.visit_count));
  return <div className={styles.bars} role="img" aria-label={title}>
    {items.map((item, index) => {
      const inner = <>
        <span className={styles.barName}>{item.name_ar}</span>
        <span className={styles.barTrack}><span className={styles.barFill} data-tone={tones[index % tones.length]} style={{ inlineSize: `${(item.visit_count / max) * 100}%` }} /></span>
        <strong>{item.visit_count.toLocaleString("ar-SY")}</strong>
      </>;
      return hrefFor ? <Link key={item.id} className={styles.barRow} href={hrefFor(item.id)}>{inner}</Link>
        : <div key={item.id} className={styles.barRow}>{inner}</div>;
    })}
  </div>;
}

export function RankTable({ title, items, hrefFor, countLabel }: { title: string; items: DashboardRank[]; hrefFor: (id: number) => string; countLabel: string }) {
  return <section className={styles.tableCard} aria-labelledby={`${title}-heading`}>
    <h2 id={`${title}-heading`}>{title}</h2>
    <div className={styles.tableWrap}>
      <table>
        <thead><tr><th scope="col">الترتيب</th><th scope="col">الاسم</th><th scope="col">{countLabel}</th></tr></thead>
        <tbody>{items.map((item, index) => <tr key={item.id}>
          <td>{index + 1}</td>
          <td><Link href={hrefFor(item.id)}>{item.name_ar}</Link></td>
          <td>{item.visit_count.toLocaleString("ar-SY")}</td>
        </tr>)}</tbody>
      </table>
    </div>
  </section>;
}

export function Reveal({ children, className, delay = 0 }: { children: React.ReactNode; className?: string; delay?: number }) {
  const reduced = useReducedMotion();
  return <motion.div className={className} initial={reduced ? false : { opacity: 0, y: 18 }}
    animate={{ opacity: 1, y: 0 }} transition={{ duration: reduced ? 0 : 0.45, delay: reduced ? 0 : delay, ease: [0.16, 1, 0.3, 1] }}>
    {children}
  </motion.div>;
}

function useCount(value: number) {
  const reduced = useReducedMotion();
  const [shown, setShown] = useState(0);
  useEffect(() => {
    if (reduced) return;
    let frame = 0;
    const start = performance.now();
    const tick = (now: number) => {
      const progress = Math.min(1, (now - start) / 900);
      const eased = 1 - Math.pow(1 - progress, 3);
      setShown(Math.round(value * eased));
      if (progress < 1) frame = requestAnimationFrame(tick);
    };
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [value, reduced]);
  return reduced ? value : shown;
}
