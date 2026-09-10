import Image from "next/image";
import Link from "next/link";
import { LuPanelRightClose, LuPanelRightOpen, LuX } from "react-icons/lu";
import { primaryNavigation } from "./navigation";
import FrameOrnaments from "./FrameOrnaments";
import styles from "./shell.module.css";

type Props = { pathname: string; collapsed?: boolean; onToggle?: () => void; onNavigate?: () => void; mobile?: boolean };

export function SidebarContent({ pathname, collapsed = false, onToggle, onNavigate, mobile = false }: Props) {
  return <>
    <div className={styles.brandCorner}>
      <Link className={styles.brandLink} href="/" onClick={onNavigate} aria-label="مشفى محمد بن زايد الإماراتي — لوحة التحكم">
        <Image src="/brand/logos/logo-ar-white.svg" alt="" width={140} height={65} priority className={styles.brandLogo} />
        <Image src="/brand/logos/mark-white.svg" alt="" width={42} height={47} priority className={styles.brandMark} />
      </Link>
      {mobile && <button className={styles.iconButton} type="button" onClick={onNavigate} aria-label="إغلاق قائمة التنقل"><LuX aria-hidden="true" /></button>}
    </div>
    <nav className={styles.navigation} id={mobile ? "mobile-navigation" : "desktop-navigation"} aria-label="التنقل الرئيسي">
      <NavigationItems items={primaryNavigation} pathname={pathname} onNavigate={onNavigate} />
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
    </Link> : <button type="button" className={styles.navigationItem} disabled aria-label={`${label} — قريبًا، غير متاح بعد`} title={`${label} — قريبًا`}>
      <span className={styles.navigationIcon}><Icon aria-hidden="true" /></span>
      <span className={styles.navigationText} aria-hidden="true"><span>{label}</span><small className={styles.comingSoon}>قريبًا</small></span>
    </button>}
  </li>)}</ul>;
}

export default function Sidebar(props: Props) {
  return <aside className={styles.sidebar} aria-label="القائمة الجانبية"><SidebarContent {...props} /><FrameOrnaments /></aside>;
}
