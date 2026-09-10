import Image from "next/image";
import Link from "next/link";
import { LuPanelRightClose, LuPanelRightOpen, LuX } from "react-icons/lu";
import { primaryNavigation, administrationNavigation } from "./navigation";
import styles from "./shell.module.css";

type Props = { pathname: string; collapsed?: boolean; onToggle?: () => void; onNavigate?: () => void; mobile?: boolean };

export function SidebarContent({ pathname, collapsed = false, onToggle, onNavigate, mobile = false }: Props) {
  return <>
    <div className={styles.brandCorner}>
      <Link className={styles.brandLink} href="/" onClick={onNavigate} aria-label="مشفى محمد بن زايد الإماراتي — لوحة التحكم">
        <Image src={collapsed ? "/brand/logos/mark-white.svg" : "/brand/logos/logo-ar-white.svg"}
          alt="مشفى محمد بن زايد الإماراتي" width={collapsed ? 42 : 140} height={collapsed ? 47 : 65} priority
          className={collapsed ? styles.brandMark : styles.brandLogo} />
      </Link>
      {mobile && <button className={styles.iconButton} type="button" onClick={onNavigate} aria-label="إغلاق قائمة التنقل"><LuX aria-hidden="true" /></button>}
    </div>
    <nav className={styles.navigation} id={mobile ? "mobile-navigation" : "desktop-navigation"} aria-label="التنقل الرئيسي">
      <p className={styles.groupLabel}>الرعاية والخدمات</p>
      <NavigationItems items={primaryNavigation} pathname={pathname} onNavigate={onNavigate} />
      <div className={styles.adminGroup}>
        <p className={styles.groupLabel}>الإدارة</p>
        <NavigationItems items={administrationNavigation} pathname={pathname} onNavigate={onNavigate} />
      </div>
    </nav>
    <div className={styles.sidebarBottom}>
      {onToggle ? <button className={styles.collapseButton} type="button" onClick={onToggle} aria-expanded={!collapsed} aria-controls="desktop-navigation" aria-label={collapsed ? "توسيع القائمة الجانبية" : "طي القائمة الجانبية"}>
        {collapsed ? <LuPanelRightOpen aria-hidden="true" /> : <LuPanelRightClose aria-hidden="true" />}
        <span className={styles.navigationText}>طي القائمة الجانبية</span>
      </button> : <p className={styles.sidebarCaption}>مشفى محمد بن زايد الإماراتي · حلب</p>}
    </div>
  </>;
}

function NavigationItems({ items, pathname, onNavigate }: { items: typeof primaryNavigation; pathname: string; onNavigate?: () => void }) {
  return <ul className={styles.navigationList}>{items.map(({ label, icon: Icon, href }) => <li key={label}>
    {href ? <Link href={href} className={styles.navigationItem} aria-label={label} aria-current={pathname === href || (href === "/" && pathname.startsWith("/dashboard/")) ? "page" : undefined} onClick={onNavigate} title={label}>
      <span className={styles.navigationIcon}><Icon aria-hidden="true" /></span><span className={styles.navigationText}>{label}</span>
    </Link> : <button type="button" className={styles.navigationItem} disabled aria-label={`${label} — غير متاح بعد`} title={`${label} — غير متاح بعد`}>
      <span className={styles.navigationIcon}><Icon aria-hidden="true" /></span><span className={styles.navigationText}>{label}</span>
    </button>}
  </li>)}</ul>;
}

export default function Sidebar(props: Props) {
  return <aside className={styles.sidebar} aria-label="القائمة الجانبية"><SidebarContent {...props} /></aside>;
}
