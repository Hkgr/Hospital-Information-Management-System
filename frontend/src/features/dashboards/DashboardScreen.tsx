"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { AuthError } from "@/features/auth/api";
import { useIdentity } from "@/features/auth/AuthenticatedLayout";
import { dashboardCatalog, dashboardDetail, invalidResponse, type Catalog, type DashboardData } from "./api";
import { dashboardPath, dashboardViews, knownDashboard } from "./registry";
import styles from "./dashboard.module.css";

export default function DashboardScreen({ dashboardKey }: { dashboardKey?: string }) {
  const query = useSearchParams().toString();
  // Drop previous data before effects run on a new key/facility, including delayed responses.
  return <DashboardRequest key={`${dashboardKey ?? "entry"}:${query}`} dashboardKey={dashboardKey} query={query} />;
}

function DashboardRequest({ dashboardKey, query }: { dashboardKey?: string; query: string }) {
  const identity = useIdentity();
  const router = useRouter();
  const [state, setState] = useState<{ catalog?: Catalog; data?: DashboardData; error?: string; empty?: boolean }>({});
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    async function load() {
      const ids = new URLSearchParams(query).getAll("facility_id");
      // Other query values (including returnTo/next) never affect navigation.
      if (ids.length > 1 || (ids.length && !/^[1-9]\d*$/.test(ids[0]))) throw new AuthError(422, "INVALID_FACILITY", "معرّف المنشأة غير صالح.");
      const facility = ids.length ? Number(ids[0]) : null;
      if (facility !== null && (!Number.isSafeInteger(facility) || facility > 2147483647)) throw new AuthError(422, "INVALID_FACILITY", "معرّف المنشأة غير صالح.");
      const catalog = await dashboardCatalog(controller.signal);
      if (!dashboardKey) {
        const target = catalog.dashboards.find(item => item.key === catalog.default_dashboard_key);
        const path = target && dashboardPath(target);
        if (controller.signal.aborted) return;
        if (!path) { setState({ empty: true }); return; }
        router.replace(path);
        return;
      }
      if (!knownDashboard(dashboardKey)) throw new AuthError(404, "UNKNOWN_DASHBOARD", "لوحة التحكم المطلوبة غير متاحة في هذا التطبيق.");
      // Always ask Laravel for details; catalog presence is not authorization.
      const data = await dashboardDetail(dashboardKey, facility, controller.signal);
      if (data.dashboard?.key !== dashboardKey || data.user?.id !== identity.user.id || data.selected_facility_id !== facility
        || !Array.isArray(data.facilities) || (facility !== null && data.facilities.some(item => item.id !== facility))) throw invalidResponse();
      if (!controller.signal.aborted) setState({ catalog, data });
    }
    load().catch(reason => {
      if (controller.signal.aborted) return;
      const message = reason instanceof AuthError
        ? reason.status === 403 ? "ليس لديك صلاحية الوصول إلى لوحة التحكم أو المنشأة المطلوبة."
          : reason.status === 404 ? "لوحة التحكم المطلوبة غير موجودة." : reason.message
        : "تعذّر تحميل لوحة التحكم. حاول مجددًا.";
      setState({ error: message });
    });
    return () => controller.abort();
  }, [dashboardKey, query, identity.user.id, router, attempt]);

  const retry = () => { setState({}); setAttempt(value => value + 1); };
  if (state.error || state.empty) return <section className={styles.status}>
    <h2>{state.empty ? "لا توجد لوحة تحكم متاحة" : "تعذّر عرض لوحة التحكم"}</h2>
    <p role={state.error ? "alert" : "status"}>{state.error ?? "لا توجد لوحة مسموحة يدعمها هذا الإصدار. راجع مسؤول النظام عند الحاجة."}</p>
    <button type="button" onClick={retry}>إعادة المحاولة</button>
    {dashboardKey && <button type="button" onClick={() => router.replace("/")}>العودة إلى اللوحة الافتراضية</button>}
  </section>;
  if (!state.data || !state.catalog || !dashboardKey || !knownDashboard(dashboardKey)) return <p className={styles.loading} role="status">جارٍ تحميل لوحة التحكم…</p>;
  const View = dashboardViews[dashboardKey];
  const choices = state.catalog.dashboards.filter(item => dashboardPath(item));
  return <>
    {choices.length > 1 && <label className={styles.switcher}>لوحة التحكم
      <select value={dashboardKey} onChange={event => {
        const choice = choices.find(item => item.key === event.target.value);
        const path = choice && dashboardPath(choice);
        if (path) { setState({}); router.push(path); }
      }}>{choices.map(item => <option key={item.key} value={item.key}>{item.title}</option>)}</select>
    </label>}
    {state.data.dashboard.requires_facility && <label className={styles.switcher}>المنشأة
      <select value={state.data.selected_facility_id ?? ""} onChange={event => {
        const descriptor = state.catalog?.dashboards.find(item => item.key === dashboardKey);
        const path = descriptor && dashboardPath(descriptor, Number(event.target.value));
        if (path) { setState({}); router.push(path); }
      }}>{state.catalog.dashboards.find(item => item.key === dashboardKey)?.facilities.map(item => <option key={item.id} value={item.id}>{item.name_ar}</option>)}</select>
    </label>}
    <View data={state.data} />
  </>;
}
