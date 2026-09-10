import { motion, MotionValue, useTransform } from "framer-motion";
import type { CSSProperties } from "react";
import styles from "../login.module.css";

export type MousePosition = { mouseX: MotionValue<number>; mouseY: MotionValue<number> };
const blobs = [
  { size: 750, x: "-18%", y: "-18%", color: "var(--mbz-blue)", opacity: 18, xm: .012, ym: .008, delay: 0, duration: 24 },
  { size: 650, x: "72%", y: "62%", color: "var(--mbz-teal)", opacity: 14, xm: -.018, ym: .013, delay: -10, duration: 30 },
  { size: 480, x: "38%", y: "32%", color: "var(--mbz-blue)", opacity: 10, xm: .009, ym: -.016, delay: -16, duration: 20 },
  { size: 380, x: "62%", y: "2%", color: "var(--mbz-teal)", opacity: 9, xm: -.008, ym: .01, delay: -6, duration: 26 },
  { size: 300, x: "5%", y: "60%", color: "var(--mbz-blue)", opacity: 12, xm: .015, ym: -.01, delay: -3, duration: 22 },
];

function Blob({ blob, mouseX, mouseY }: MousePosition & { blob: typeof blobs[number] }) {
  const x = useTransform(mouseX, value => value * blob.xm);
  const y = useTransform(mouseY, value => value * blob.ym);
  return <motion.div className={styles.blobPosition} style={{ x, y, left: blob.x, top: blob.y }}>
    <div className={styles.blob} style={{ width: blob.size, height: blob.size,
      background: `radial-gradient(circle, color-mix(in srgb, ${blob.color} ${blob.opacity}%, transparent) 0%, transparent 68%)`,
      "--duration": `${blob.duration}s`, "--delay": `${blob.delay}s`,
    } as CSSProperties} />
  </motion.div>;
}

export default function AnimatedBackground(props: MousePosition) {
  return <>
    <div className={styles.mesh} />
    {blobs.map((blob, i) => <Blob key={i} blob={blob} {...props} />)}
    <div className={styles.dots} />
    <div className={styles.corner} /><div className={`${styles.corner} ${styles.cornerEnd}`} />
    <div className={styles.vignette} />
  </>;
}
