"use client";

import Link from "next/link";
import { LuBuilding2, LuCalendarClock, LuClipboardPlus, LuDroplet, LuFolderHeart, LuHospital, LuPackage, LuPill, LuSparkles, LuStethoscope, LuUserRound } from "react-icons/lu";
import type { IconType } from "react-icons";
import type { DashboardAppointment, DashboardData } from "./api";
import { BarChart, CounterCard, DonutChart, RankTable, Reveal } from "./HomeVisuals";
import styles from "./dashboard.module.css";

const destinations: Record<string, { href: string; icon: IconType }> = {
  "patient-cards": { href: "/patient-cards", icon: LuFolderHeart },
  visits: { href: "/visits", icon: LuClipboardPlus },
  doctors: { href: "/doctors", icon: LuStethoscope },
  clinics: { href: "/clinics", icon: LuHospital },
  "services-procedures": { href: "/services-procedures", icon: LuClipboardPlus },
  medications: { href: "/medications", icon: LuPill },
  stock: { href: "/stock/receipts", icon: LuPackage },
  "blood-bank": { href: "/blood-bank", icon: LuDroplet },
};

export default function GeneralDashboard({ data }: { data: DashboardData }) {
  const stats = data.stats;
  const actions = data.links.flatMap(link => {
    const destination = destinations[link.key];
    return destination ? [{ ...link, ...destination }] : [];
  });
  return <div className={styles.overview}>
    <Reveal>
      <section className={styles.hero} aria-labelledby="welcome-heading">
        <div className={styles.heroCopy}>
          <p className={styles.eyebrow}><LuSparkles aria-hidden="true" /> الرئيسية</p>
          <h2 id="welcome-heading">مرحبًا، {data.user.name}</h2>
          <p>لمحة حيّة عن نشاط المنشآت المتاحة لك: البطاقات، الزيارات، العيادات الأكثر حركة، والمواعيد القادمة.</p>
        </div>
        <div className={styles.heroMark} aria-hidden="true"><LuUserRound /></div>
      </section>
    </Reveal>
    {stats.counters.length > 0 && <Reveal delay={0.05}><section className={styles.counters} aria-label="مؤشرات سريعة">
      {stats.counters.map((item, index) => <CounterCard key={item.key} item={item} index={index} />)}
    </section></Reveal>}
    {(stats.visit_status.length > 0 || stats.dossier_status.length > 0) && <Reveal delay={0.08}>
      <div className={styles.chartGrid}>
        {stats.visit_status.length > 0 && <section className={styles.panel} aria-labelledby="visit-status-heading">
          <h2 id="visit-status-heading" className={styles.visuallyHidden}>توزيع الزيارات</h2>
          <DonutChart title="توزيع الزيارات" items={stats.visit_status} />
        </section>}
        {stats.dossier_status.length > 0 && <section className={styles.panel} aria-labelledby="dossier-status-heading">
          <h2 id="dossier-status-heading" className={styles.visuallyHidden}>حالة البطاقات</h2>
          <DonutChart title="حالة البطاقات" items={stats.dossier_status} />
        </section>}
      </div>
    </Reveal>}
    {stats.clinics.length > 0 && <Reveal delay={0.1}><section className={styles.panel} aria-labelledby="clinic-bars-heading">
      <h2 id="clinic-bars-heading">العيادات الأكثر نشاطًا</h2>
      <BarChart title="العيادات الأكثر نشاطًا" items={stats.clinics} hrefFor={id => `/clinics/${id}`} />
    </section></Reveal>}
    {(stats.clinics.length > 0 || stats.doctors.length > 0) && <Reveal delay={0.12}><div className={styles.tableGrid}>
      {stats.clinics.length > 0 && <RankTable title="ترتيب العيادات" items={stats.clinics} hrefFor={id => `/clinics/${id}`} countLabel="الزيارات المكتملة" />}
      {stats.doctors.length > 0 && <RankTable title="الأطباء الأكثر نشاطًا" items={stats.doctors} hrefFor={id => `/doctors/${id}`} countLabel="الزيارات المكتملة" />}
    </div></Reveal>}
    <Reveal delay={0.14}><section className={styles.panel} aria-labelledby="appointments-heading">
      <h2 id="appointments-heading"><LuCalendarClock aria-hidden="true" /> المواعيد القادمة</h2>
      {stats.appointments.length ? <ul className={styles.appointments}>{stats.appointments.map(item => <AppointmentCard key={item.id} item={item} />)}</ul>
        : <p className={styles.empty}>{data.links.some(link => link.key === "patient-cards") ? "لا توجد جلسات علاجية مجدولة من اليوم فصاعدًا في المنشآت المتاحة لك." : "تظهر مواعيد العلاج هنا عند توفر صلاحية عرض الخطط العلاجية."}</p>}
    </section></Reveal>
    <Reveal delay={0.16}><section className={styles.panel} aria-labelledby="actions-heading">
      <h2 id="actions-heading">إجراءات سريعة</h2>
      {actions.length ? <ul className={styles.actions}>{actions.map((item, index) => <li key={item.key}>
        <Link href={item.href} className={styles.action} data-tone={["teal", "blue", "action", "amber", "coral", "violet"][index % 6]}>
          <span className={styles.symbol}><item.icon aria-hidden="true" /></span>
          <strong>{item.title}</strong>
          <span>انتقال مباشر</span>
        </Link>
      </li>)}</ul> : <p className={styles.empty}>لا توجد إجراءات سريعة متاحة لصلاحياتك الحالية. يمكنك مراجعة مسؤول النظام عند الحاجة.</p>}
    </section></Reveal>
    <Reveal delay={0.18}><section className={styles.facilities} aria-labelledby="facilities-heading">
      <h2 id="facilities-heading">المنشآت المتاحة لك</h2>
      {data.facilities.length ? <ul>{data.facilities.map(facility => <li key={facility.id}>
        <span className={styles.symbol}><LuBuilding2 aria-hidden="true" /></span>
        <div><h3>{facility.name_ar}</h3><p dir="ltr">{facility.code}</p></div>
      </li>)}</ul> : <p className={styles.empty}>لا توجد منشآت مرتبطة بوصولك الحالي. يمكنك مراجعة مسؤول النظام عند الحاجة.</p>}
    </section></Reveal>
  </div>;
}

function AppointmentCard({ item }: { item: DashboardAppointment }) {
  return <li className={styles.appointment}>
    <time dateTime={item.planned_on}>{formatAppointment(item.planned_on)}</time>
    <div>
      <Link href={`/patient-cards/${item.dossier_id}`}>{item.patient_name}</Link>
      <p>{item.patient_code} · جلسة {item.session_number}{item.clinic_name ? ` · ${item.clinic_name}` : ""}{item.doctor_name ? ` · ${item.doctor_name}` : ""}</p>
    </div>
  </li>;
}

function formatAppointment(value: string) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return value;
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), 12));
  return new Intl.DateTimeFormat("ar-SY", { weekday: "long", day: "numeric", month: "long" }).format(date);
}
