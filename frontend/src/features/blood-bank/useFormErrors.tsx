"use client";

import { useEffect, useId, useRef, type FormEvent } from "react";
import type { AuthError } from "../auth/api";
import styles from "../clinics/clinics.module.css";

// Names connect server field errors to both native controls and picker searches.
// Run after busy clears so the first invalid control can receive focus.
export function useFormErrors(error: AuthError | null, busy: boolean) {
  const form = useRef<HTMLFormElement>(null);
  const prefix = useId();
  const focused = useRef<AuthError | null>(null);
  const nativeFrame = useRef<number | null>(null);
  const message = (name: string) => error?.fields[name] ?? error?.fields[`person.${name}`];
  const errorId = (name: string) => `${prefix}-${name}`;
  useEffect(() => {
    if (busy || !form.current) return;
    let first: HTMLElement | undefined;
    for (const input of form.current.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>("input[name], select[name], textarea[name]")) {
      const invalid = error?.fields[input.name] ?? error?.fields[`person.${input.name}`];
      if (invalid) {
        input.setAttribute("aria-invalid", "true");
        const id = `${prefix}-${input.name}`;
        if (document.getElementById(id)) input.setAttribute("aria-describedby", id);
        if (!first && !input.disabled && input.getClientRects().length) first = input;
      } else {
        input.removeAttribute("aria-invalid"); input.removeAttribute("aria-describedby");
      }
    }
    if (error?.status === 422 && focused.current !== error) {
      focused.current = error;
      const target = first ?? form.current.querySelector<HTMLElement>('[role="alert"]');
      target?.focus({ preventScroll: true });
      target?.scrollIntoView({ block: "center", behavior: "instant" });
    }
  }, [error, busy, prefix]);
  useEffect(() => () => { if (nativeFrame.current !== null) cancelAnimationFrame(nativeFrame.current); }, []);
  function onInvalid(event: FormEvent<HTMLFormElement>) {
    const target = event.target as HTMLInputElement;
    target.setAttribute("aria-invalid", "true");
    if (nativeFrame.current !== null) cancelAnimationFrame(nativeFrame.current);
    nativeFrame.current = requestAnimationFrame(() => {
      const first = form.current?.querySelector<HTMLElement>('input:invalid, select:invalid, textarea:invalid');
      first?.focus({ preventScroll: true }); first?.scrollIntoView({ block: "center", behavior: "instant" });
      nativeFrame.current = null;
    });
  }
  return { form, onInvalid, fieldError: (name: string) => message(name) ? <small id={errorId(name)} className={styles.fieldError}>{message(name)}</small> : null };
}
