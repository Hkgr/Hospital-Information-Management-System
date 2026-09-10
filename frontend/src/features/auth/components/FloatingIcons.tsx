import { motion, useReducedMotion, useTransform } from "framer-motion";
import { FaStethoscope, FaNotesMedical, FaHeartbeat, FaFlask, FaMicroscope,
  FaSyringe, FaTablets, FaGlobe, FaLaptopMedical, FaShieldAlt, FaBriefcaseMedical,
  FaHospital, FaDna, FaBookMedical, FaBrain, FaAmbulance, FaHandHoldingHeart,
  FaStarOfLife, FaUserMd, FaThermometerHalf } from "react-icons/fa";
import type { MousePosition } from "./AnimatedBackground";
import styles from "../login.module.css";

// Positions, sizes, opacity and rhythms follow the reference, in the same order.
const icons = [
  { Icon: FaStethoscope, size: 40, x: 7, y: 10, op: .2, c: 0, dur: 13, del: 0, ry: 28, rx: 22 },
  { Icon: FaNotesMedical, size: 26, x: 86, y: 16, op: .15, c: 1, dur: 16, del: 2.5, ry: -24, rx: 30 },
  { Icon: FaHeartbeat, size: 46, x: 91, y: 54, op: .13, c: 2, dur: 20, del: 5, ry: 32, rx: -28 },
  { Icon: FaFlask, size: 28, x: 11, y: 74, op: .16, c: 0, dur: 15, del: 1.5, ry: -30, rx: 26 },
  { Icon: FaMicroscope, size: 34, x: 74, y: 84, op: .14, c: 1, dur: 17, del: 7, ry: 22, rx: -32 },
  { Icon: FaSyringe, size: 24, x: 4, y: 42, op: .17, c: 0, dur: 12, del: 3.5, ry: 26, rx: 20 },
  { Icon: FaTablets, size: 30, x: 33, y: 6, op: .14, c: 2, dur: 14, del: 6, ry: -22, rx: -28 },
  { Icon: FaGlobe, size: 38, x: 64, y: 4, op: .12, c: 1, dur: 22, del: 2, ry: 20, rx: 25 },
  { Icon: FaLaptopMedical, size: 32, x: 93, y: 28, op: .15, c: 0, dur: 18, del: 8, ry: -28, rx: 22 },
  { Icon: FaShieldAlt, size: 36, x: 2, y: 22, op: .13, c: 2, dur: 16, del: 4, ry: 24, rx: -20 },
  { Icon: FaBriefcaseMedical, size: 28, x: 50, y: 93, op: .16, c: 0, dur: 13, del: 9, ry: -26, rx: 24 },
  { Icon: FaHospital, size: 42, x: 19, y: 90, op: .11, c: 1, dur: 24, del: 1, ry: 22, rx: -24 },
  { Icon: FaDna, size: 30, x: 80, y: 40, op: .14, c: 2, dur: 15, del: 10, ry: 28, rx: 18 },
  { Icon: FaBookMedical, size: 26, x: 47, y: 3, op: .17, c: 0, dur: 12, del: 3, ry: -24, rx: -26 },
  { Icon: FaBrain, size: 36, x: 21, y: 52, op: .12, c: 1, dur: 21, del: 6.5, ry: 18, rx: 28 },
  { Icon: FaAmbulance, size: 30, x: 58, y: 80, op: .14, c: 2, dur: 17, del: 2.5, ry: -30, rx: -18 },
  { Icon: FaHandHoldingHeart, size: 22, x: 39, y: 97, op: .19, c: 0, dur: 14, del: 5.5, ry: 24, rx: 20 },
  { Icon: FaStarOfLife, size: 20, x: 97, y: 68, op: .18, c: 2, dur: 11, del: .5, ry: -20, rx: 22 },
  { Icon: FaUserMd, size: 34, x: 14, y: 32, op: .11, c: 1, dur: 23, del: 7.5, ry: 26, rx: -22 },
  { Icon: FaThermometerHalf, size: 28, x: 70, y: 60, op: .13, c: 2, dur: 16, del: 4.5, ry: -24, rx: 20 },
];

function FloatingIcon({ item, index, mouseX, mouseY }: MousePosition & { item: typeof icons[number]; index: number }) {
  const reduced = useReducedMotion();
  const x = useTransform(mouseX, v => v * (.015 + (index % 4) * .006));
  const y = useTransform(mouseY, v => v * (.015 + (index % 3) * .007));
  return <motion.div className={styles.floatingIcon} style={{ x, y, left: `${item.x}%`, top: `${item.y}%` }}>
    <motion.div initial={reduced ? false : { opacity: 0, scale: 0, rotate: -20 }}
      animate={{ opacity: item.op, scale: 1, rotate: 0,
        y: reduced ? 0 : [0, item.ry, item.ry * .3, -item.ry * .5, 0],
        x: reduced ? 0 : [0, item.rx * .4, item.rx, item.rx * .2, 0] }}
      transition={{ opacity: { duration: reduced ? 0 : 1.2, delay: reduced ? 0 : item.del * .4 },
        scale: { duration: .9, delay: item.del * .4, ease: [.16, 1, .3, 1] },
        rotate: { duration: .9, delay: item.del * .4 },
        y: { duration: item.dur, repeat: Infinity, ease: "easeInOut", delay: item.del },
        x: { duration: item.dur * 1.4, repeat: Infinity, ease: "easeInOut", delay: item.del + .5 } }}
      style={{ color: ["var(--mbz-blue)", "var(--mbz-action)", "var(--mbz-teal)"][item.c], display: "flex" }}>
      <item.Icon size={item.size} />
    </motion.div>
  </motion.div>;
}

export default function FloatingIcons(props: MousePosition) {
  return icons.map((item, index) => <FloatingIcon key={index} item={item} index={index} {...props} />);
}
