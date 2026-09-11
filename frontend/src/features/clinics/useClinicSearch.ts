import { useCallback, useEffect, useRef, useState } from "react";

export default function useClinicSearch(pathname: string, params: string, facilityId: number) {
  const url = `${pathname}?${params}`;
  const committed = new URLSearchParams(params).get("search") ?? "";
  const [draft, setDraft] = useState<{ url: string; value: string } | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  // URL navigation invalidates a draft before rendering; an old draft must not
  // reappear even if a later history entry happens to have the same URL.
  if (draft && draft.url !== url) setDraft(null);
  const search = draft?.url === url ? draft.value : committed;
  const cancel = useCallback(() => { if (timer.current) clearTimeout(timer.current); setDraft(null); }, []);
  useEffect(() => {
    window.addEventListener("popstate", cancel);
    return () => { window.removeEventListener("popstate", cancel); if (timer.current) clearTimeout(timer.current); };
  }, [cancel, url]);
  function change(value: string) {
    if (timer.current) clearTimeout(timer.current);
    setDraft({ url, value });
    if (value === committed) return;
    timer.current = setTimeout(() => {
      // Guard the actual location too: popstate/Next rendering can be in flight.
      if (`${window.location.pathname}?${new URLSearchParams(window.location.search)}` !== url) return;
      const next = new URLSearchParams(params);
      next.set("facility_id", String(facilityId)); next.delete("page");
      if (value) next.set("search", value); else next.delete("search");
      // Next integrates native history with useSearchParams. Keep replace semantics
      // and commit synchronously, so a delayed router transition cannot undo typing.
      window.history.replaceState(null, "", `${pathname}?${next}`);
    }, 300);
  }
  return { search, committed, change, cancel, searching: search !== committed };
}
