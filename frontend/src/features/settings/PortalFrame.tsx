"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import styles from "../clinics/clinics.module.css";
import css from "./portal.module.css";

/** Only wraps the administrative portals; no effect on clinical workspaces. */
export default function PortalFrame({ children }: { children: React.ReactNode }) {
  const params = useSearchParams(), path = usePathname(), { access } = useIdentity();
  const permission = path === "/users" ? "users.view" : path === "/statistics" ? "statistics.view" : path === "/reception" ? "reception.view" : null;
  const facility = params.get("facility_id") ?? (permission ? String(access.find(e => e.permissions.includes(permission))?.facility.id ?? "") : null);
  return <div className={`${styles.screen} ${css.portal}`}><div className={css.help}><Link href={`/guide${facility === null ? "" : `?facility_id=${encodeURIComponent(facility)}`}`}>دليل الاستخدام</Link></div>{children}</div>;
}
