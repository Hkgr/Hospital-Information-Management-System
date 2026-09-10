import styles from "./shell.module.css";

// A single viewport-aligned drawing, visible only through the frame's surfaces.
export default function FrameOrnaments() {
  return <div className={styles.frameOrnaments} aria-hidden="true" />;
}
