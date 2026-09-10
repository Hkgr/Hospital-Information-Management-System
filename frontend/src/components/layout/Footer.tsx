import styles from "./shell.module.css";
import FrameOrnaments from "./FrameOrnaments";

export default function Footer() {
  return <footer className={styles.footer}><span>نظام إدارة المشفى</span><span>© 2026 مشفى محمد بن زايد الإماراتي - حلب</span><FrameOrnaments /></footer>;
}
