import { LuBuilding2, LuUserRound } from "react-icons/lu";
import type { DashboardData } from "./api";
import styles from "./dashboard.module.css";

export default function GeneralDashboard({ data }: { data: DashboardData }) {
  return <div className={styles.overview}>
    <section className={styles.welcome} aria-labelledby="welcome-heading">
      <span className={styles.symbol}><LuUserRound aria-hidden="true" /></span>
      <div><p className={styles.eyebrow}>مساحتك في نظام المشفى</p><h2 id="welcome-heading">مرحبًا، {data.user.name}</h2>
        <p>يمكنك الاطلاع هنا على سياق حسابك والمنشآت المتاحة لك.</p></div>
    </section>
    <section className={styles.facilities} aria-labelledby="facilities-heading">
      <h2 id="facilities-heading">المنشآت المتاحة لك</h2>
      {data.facilities.length ? <ul>{data.facilities.map(facility => <li key={facility.id}>
        <span className={styles.symbol}><LuBuilding2 aria-hidden="true" /></span>
        <div><h3>{facility.name_ar}</h3><p dir="ltr">{facility.code}</p></div>
      </li>)}</ul> : <p className={styles.empty}>لا توجد منشآت مرتبطة بوصولك الحالي. يمكنك مراجعة مسؤول النظام عند الحاجة.</p>}
    </section>
  </div>;
}
