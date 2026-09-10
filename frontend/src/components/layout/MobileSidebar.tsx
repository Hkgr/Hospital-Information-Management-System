import { useEffect, useRef } from "react";
import { SidebarContent } from "./Sidebar";
import FrameOrnaments from "./FrameOrnaments";
import styles from "./shell.module.css";

export default function MobileSidebar({ open, onClose, pathname }: { open: boolean; onClose: () => void; pathname: string }) {
  const dialogRef = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = dialogRef.current;
    if (!open || !dialog) return;
    dialog.showModal();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    const desktop = window.matchMedia("(min-width: 768px)");
    const closeOnDesktop = () => { if (desktop.matches) dialog.close(); };
    desktop.addEventListener("change", closeOnDesktop);
    return () => {
      desktop.removeEventListener("change", closeOnDesktop);
      dialog.close();
      document.body.style.overflow = previousOverflow;
    };
  }, [open]);

  return <dialog ref={dialogRef} className={styles.mobileSidebar} aria-label="قائمة التنقل" onClose={onClose}
    onKeyDown={event => {
      if (event.key !== "Tab") return;
      const focusable = Array.from(event.currentTarget.querySelectorAll<HTMLElement>('a[href], button:not(:disabled), [tabindex="0"]'))
        .filter(element => element.getClientRects().length > 0);
      const first = focusable[0];
      const last = focusable.at(-1);
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
      else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    }}
    onClick={event => { if (event.target === event.currentTarget) dialogRef.current?.close(); }}>
    <div className={styles.mobileSidebarContent}><SidebarContent pathname={pathname} onNavigate={onClose} mobile /><FrameOrnaments /></div>
  </dialog>;
}
