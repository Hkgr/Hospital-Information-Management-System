import { useEffect, useRef, useState } from "react";
import styles from "../clinics/clinics.module.css";
import ui from "./permissions.module.css";

export type Permission = { id: number; code: string; name_ar: string; description?: string; scope?: string; prerequisites?: string[]; legacy_tasks?: string[] };
export type PermissionGroup = { key: string; name_ar: string; permissions: Permission[] };
export type TaskTemplate = { name_ar: string; codes: string[] };

export function missingRequirements(groups: PermissionGroup[], selected: number[], exact = false) {
  const all = groups.flatMap(g => g.permissions), chosen = all.filter(p => selected.includes(p.id));
  const granted = new Set(chosen.flatMap(p => [p.code, ...(exact ? [] : p.legacy_tasks ?? [])]));
  return [...new Set(chosen.flatMap(p => p.prerequisites ?? []))].filter(code => !granted.has(code));
}

export default function TaskPermissionPicker({ groups, templates, selected, onChange, lockedCodes = [], exact = false }: { groups: PermissionGroup[]; templates: TaskTemplate[]; selected: number[]; onChange: (ids: number[]) => void; lockedCodes?: string[]; exact?: boolean }) {
  const [search, setSearch] = useState(""), [template, setTemplate] = useState<TaskTemplate | null>(null);
  const [notice, setNotice] = useState("");
  const [removal, setRemoval] = useState<{ ids: number[]; affected: Permission[] } | null>(null);
  const removalPanel = useRef<HTMLElement | null>(null);
  const removalTrigger = useRef<HTMLElement | null>(null);
  useEffect(() => { if (removal) { removalPanel.current?.focus({ preventScroll: true }); removalPanel.current?.scrollIntoView({ block: "nearest" }); } }, [removal]);
  const all = groups.flatMap(g => g.permissions), chosen = all.filter(p => selected.includes(p.id));
  const missing = missingRequirements(groups, selected, exact);
  const find = (code: string) => all.find(p => p.code === code);
  const name = (code: string) => find(code)?.name_ar ?? "متطلب غير متاح للتفويض من حسابك";
  const matches = (p: Permission) => `${p.name_ar} ${p.description ?? ""}`.includes(search.trim());
  const scopes: Record<string, string> = { global: "تفويض عالمي", facility: "ضمن المشفى", allowed_records: "ضمن السجلات المسموحة" };
  function add(ids: number[]) {
    const next = new Set([...selected, ...ids]);
    const queue = [...next], visited = new Set<number>(), required: Permission[] = [];
    while (queue.length) {
      const id = queue.shift()!;
      if (visited.has(id)) continue;
      visited.add(id);
      const granted = new Set(all.filter(p => next.has(p.id)).flatMap(p => [p.code, ...(exact ? [] : p.legacy_tasks ?? [])]));
      for (const code of all.find(p => p.id === id)?.prerequisites ?? []) {
        const permission = find(code);
        if (!granted.has(code) && permission && !next.has(permission.id)) {
          next.add(permission.id); queue.push(permission.id); required.push(permission);
        }
      }
    }
    setRemoval(null);
    setNotice(required.length ? `أُضيفت المتطلبات تلقائيًا: ${required.map(p => p.name_ar).join("، ")}. راجع نطاقها في تأكيد الحفظ.` : "");
    onChange([...next]);
  }
  function remove(ids: number[]) {
    removalTrigger.current = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const removable = ids.filter(id => !lockedCodes.includes(all.find(p => p.id === id)?.code ?? ""));
    let next = selected.filter(id => !removable.includes(id));
    const before = new Set(chosen.flatMap(p => [p.code, ...(exact ? [] : p.legacy_tasks ?? [])]));
    const affected: Permission[] = [];
    for (;;) {
      const remaining = all.filter(p => next.includes(p.id));
      const granted = new Set(remaining.flatMap(p => [p.code, ...(exact ? [] : p.legacy_tasks ?? [])]));
      const dependent = remaining.filter(p => p.prerequisites?.some(code => before.has(code) && !granted.has(code)));
      if (!dependent.length) break;
      if (dependent.some(p => lockedCodes.includes(p.code))) { setNotice("لا يمكن إزالة متطلب لصلاحية إدارة محمية."); return; }
      affected.push(...dependent); next = next.filter(id => !dependent.some(p => p.id === id));
    }
    setNotice("");
    if (affected.length) setRemoval({ ids: next, affected });
    else { setRemoval(null); onChange(next); }
  }
  function finishRemoval(apply: boolean) {
    if (apply && removal) onChange(removal.ids);
    setRemoval(null); removalTrigger.current?.focus();
  }
  const toggle = (id: number) => selected.includes(id) ? remove([id]) : add([id]);
  return <div className={ui.picker}>
    <p className={styles.hint}>الإضافة مستقلة لكل قسم: اختر إضافة عيادة أو طبيب أو دواء أو خدمة وإجراء. تُضاف متطلبات العرض تلقائيًا؛ يكفي إسناد الدور داخل المشفى، ولا تمنح الإضافة تعديل السجلات أو حذفها أو تصديرها.</p>
    {!!lockedCodes.length && <p className={styles.hint}>الخيارات المعطّلة محمية لمنع إغلاق إدارة الوصول على المسؤول. بقية الاختيارات صريحة؛ إزالة صلاحية تشغيلية تسري عند الطلب التالي، ولا تُضاف صلاحيات مستقبلية تلقائيًا.</p>}
    <section className={ui.templates} aria-label="مجموعات المهام"><h3>ابدأ بمجموعة مهام ثم خصّصها</h3><p>معاينة فقط قبل الإضافة. لا تُغيّر حسابًا قائمًا ولا تضيف تفويضًا عالميًا تلقائيًا.</p><div className={styles.actions}>{templates.map(t => <button type="button" className={styles.secondary} key={t.name_ar} onClick={() => setTemplate(t)}>معاينة {t.name_ar}</button>)}</div>
      {template && <div className={ui.preview}><h4>{template.name_ar}</h4><ul>{template.codes.map(code => <li key={code}>{name(code)}{!find(code) && " — لن تُضاف"}</li>)}</ul><div className={styles.actions}><button type="button" className={styles.primary} onClick={() => { add(all.filter(p => template.codes.includes(p.code)).map(p => p.id)); setTemplate(null); }}>إضافة الصلاحيات المتاحة صراحة</button><button type="button" className={styles.secondary} onClick={() => setTemplate(null)}>إلغاء المعاينة</button></div></div>}
    </section>
    <label className={styles.search}>البحث في الصلاحيات<input type="search" value={search} onChange={e => setSearch(e.target.value)} placeholder="مثل: التشخيص أو التصدير أو المواعيد" /></label>
    <section className={ui.summary} aria-label="ملخص الصلاحيات المختارة"><strong>{chosen.length} صلاحية مختارة</strong><details><summary>مراجعة المختار وإزالته</summary><ul>{chosen.map(p => <li key={p.id}>{p.name_ar}<button type="button" className={styles.textButton} disabled={lockedCodes.includes(p.code)} onClick={() => toggle(p.id)}>إزالة {p.name_ar}</button></li>)}</ul></details>{!exact && chosen.some(p => p.scope === "global") && <p>الصلاحيات العالمية تحتاج إسنادًا عالميًا مستقلًا من مسؤول النظام؛ ربط الدور بالمشفى وحده لا يفعّلها.</p>}</section>
    {notice && <p role="status" className={styles.hint}>{notice}</p>}
    {removal && <section ref={removalPanel} tabIndex={-1} className={ui.requirements} aria-label="تأكيد إزالة المتطلب"><h4>إزالة هذا المتطلب ستزيل المهام التابعة له</h4><ul>{removal.affected.map(p => <li key={p.id}>{p.name_ar}</li>)}</ul><div className={styles.actions}><button type="button" className={styles.secondary} onClick={() => finishRemoval(true)}>تأكيد إزالة المتطلب والمهام</button><button type="button" className={styles.secondary} onClick={() => finishRemoval(false)}>الاحتفاظ بالاختيارات</button></div></section>}
    {missing.length > 0 && <section className={ui.requirements} role="alert"><h4>متطلبات ناقصة في الاختيارات الحالية</h4><ul>{missing.map(code => <li key={code}>{name(code)}</li>)}</ul><button type="button" className={styles.secondary} onClick={() => add([])}>إضافة المتطلبات المتاحة</button><p>إذا كان المتطلب غير متاح، أزل المهمة التابعة له أو راجع مسؤول النظام.</p></section>}
    <div className={ui.groups}>{groups.map(group => { const rows = group.permissions.filter(matches); return rows.length ? <fieldset key={group.key}><legend>{group.name_ar}</legend><div className={styles.actions}><button type="button" className={styles.textButton} onClick={() => add(group.permissions.map(p => p.id))}>تحديد المجموعة</button><button type="button" className={styles.textButton} onClick={() => remove(group.permissions.map(p => p.id))}>إلغاء تحديد المجموعة</button></div>{rows.map(p => <label key={p.id} className={ui.permission}><input type="checkbox" disabled={lockedCodes.includes(p.code)} checked={selected.includes(p.id)} onChange={() => toggle(p.id)} /><span><strong>{p.name_ar}</strong><small className={ui.scope}>{scopes[p.scope ?? "facility"]}</small><span className={ui.description}>{p.description}</span>{!!p.prerequisites?.length && <small>يتطلب: {p.prerequisites.map(name).join("، ")}</small>}{!exact && !!p.legacy_tasks?.length && <small>صلاحية سابقة واسعة؛ تحتفظ بالمهام التي كانت تتيحها. استخدم المهام المفصلة للأدوار الجديدة.</small>}</span></label>)}</fieldset> : null; })}</div>
    {!all.some(matches) && <p role="status">لا توجد صلاحيات مطابقة ضمن ما يمكنك تفويضه.</p>}
  </div>;
}
