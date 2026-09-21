"use client";

import { useEffect, useId, useRef, useState, type ComponentProps } from "react";
import { LuChevronDown, LuX } from "react-icons/lu";
import Picker from "../blood-bank/Picker";
import layout from "./wizard.module.css";

/** Reuse the directory picker, opening its search/results only when needed. */
export default function DossierPicker(props: ComponentProps<typeof Picker>) {
  const [open, setOpen] = useState(false);
  const id = useId(), trigger = useRef<HTMLButtonElement>(null), root = useRef<HTMLDivElement>(null);
  useEffect(() => {
    if (!open) return;
    const outside = (event: Event) => { if (!root.current?.contains(event.target as Node)) setOpen(false); };
    document.addEventListener("pointerdown", outside);
    document.addEventListener("focusin", outside);
    return () => { document.removeEventListener("pointerdown", outside); document.removeEventListener("focusin", outside); };
  }, [open]);
  function close() { setOpen(false); trigger.current?.focus(); }
  return <div ref={root} className={layout.compactPicker} onKeyDown={e => { if (e.key === "Escape" && open) { e.preventDefault(); e.stopPropagation(); close(); } }}>
    <span id={`${id}-label`}>{props.label}</span>
    <button ref={trigger} name={props.name} type="button" className={layout.pickerTrigger} aria-label={`اختيار: ${props.label}`} aria-expanded={open} aria-controls={id} onClick={() => setOpen(v => !v)}>
      <span>{props.selected?.name_ar || "اختر من الدليل…"}</span><LuChevronDown aria-hidden="true" />
    </button>
    {open && <div id={id} className={layout.pickerPopup}><button type="button" className={layout.closePicker} aria-label={`إغلاق خيارات ${props.label}`} onClick={close}><LuX aria-hidden="true" /></button><Picker {...props} onSelect={row => { props.onSelect(row); close(); }} /></div>}
  </div>;
}
