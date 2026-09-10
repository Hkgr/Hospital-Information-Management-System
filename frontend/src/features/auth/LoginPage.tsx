"use client";

import { useRef } from "react";
import { MotionConfig, useMotionValue, useReducedMotion } from "framer-motion";
import AnimatedBackground from "./components/AnimatedBackground";
import FloatingIcons from "./components/FloatingIcons";
import LoginCard from "./components/LoginCard";
import styles from "./login.module.css";

export default function LoginPage() {
  const mouseX = useMotionValue(0);
  const mouseY = useMotionValue(0);
  const reduced = useReducedMotion();
  const frame = useRef<HTMLElement>(null);
  return (
    <MotionConfig reducedMotion="user">
      <main ref={frame} className={styles.page} onPointerMove={(event) => {
        if (reduced || event.pointerType !== "mouse" || !window.matchMedia("(hover: hover) and (pointer: fine)").matches) return;
        mouseX.set(event.clientX - window.innerWidth / 2);
        mouseY.set(event.clientY - window.innerHeight / 2);
      }} onPointerLeave={() => { mouseX.set(0); mouseY.set(0); }}>
        <div className={styles.decorations} aria-hidden="true">
          <AnimatedBackground mouseX={mouseX} mouseY={mouseY} />
          <FloatingIcons mouseX={mouseX} mouseY={mouseY} />
        </div>
        <div className={styles.cardPosition}><LoginCard /></div>
      </main>
    </MotionConfig>
  );
}
