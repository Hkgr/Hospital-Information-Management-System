"use client";

import Link from "next/link";
import type { ReactNode } from "react";
import { LuArrowRight, LuEye, LuSquarePen, LuTrash2 } from "react-icons/lu";
import styles from "../clinics/clinics.module.css";

// Extracted without changing the doctor/clinic DOM or CSS.
export function DirectoryTable({ label, busy, headers, children }: { label: string; busy?: boolean; headers: ReactNode[]; children: ReactNode }) {
  return <div className={styles.tableScroll} tabIndex={0} role="region" aria-label={label} aria-busy={busy}><table><thead><tr>{headers.map((heading, index) => <th key={index} scope="col">{heading}</th>)}</tr></thead><tbody>{children}</tbody></table></div>;
}

export function DirectoryRowActions({ name, href, onEdit, editTitle = "تعديل", onDelete, disabled = false, beforeDelete, children }: { name: string; href: string; onEdit?: () => void; editTitle?: string; onDelete?: () => void; disabled?: boolean; beforeDelete?: ReactNode; children?: ReactNode }) {
  return <div className={styles.rowActions}><Link href={href} className={styles.iconButton} aria-label={`استعراض ${name}`} title="استعراض"><LuEye aria-hidden="true" /></Link>{onEdit && <button className={styles.iconButton} disabled={disabled || undefined} onClick={onEdit} aria-label={`تعديل ${name}`} title={editTitle}><LuSquarePen aria-hidden="true" /></button>}{beforeDelete}{onDelete && <button className={`${styles.iconButton} ${styles.dangerText}`} disabled={disabled || undefined} onClick={onDelete} aria-label={`حذف ${name}`} title="حذف"><LuTrash2 aria-hidden="true" /></button>}{children}</div>;
}

export function DirectoryBack({ href, children }: { href: string; children: ReactNode }) {
  return <Link href={href} className={styles.back}><LuArrowRight aria-hidden="true" />{children}</Link>;
}
