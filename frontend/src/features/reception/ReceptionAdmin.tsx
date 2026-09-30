"use client";

import Link from "next/link";
import { useState } from "react";
import { useSearchParams } from "next/navigation";
import { useIdentity } from "../auth/AuthenticatedLayout";
import { useClinicRequest } from "../clinics/api";
import { directoryFacility } from "../directory/facilityContext";
import { DirectoryBack, DirectoryTable } from "../directory/DirectoryPrimitives";
import { identityLabels, permissionLabels, statusLabel, useReviewWrite } from "./review";
import styles from "../clinics/clinics.module.css";

type Kind = "corrections" | "duplicates" | "accounts";
const scopes = { corrections: "identity_corrections.review", duplicates: "patient_duplicates.review", accounts: "reception_accounts.manage" };
const labels = { corrections: "طلبات تصحيح الهوية", duplicates: "مراجعة التكرارات", accounts: "حسابات موظفي التسجيل" };
type Row = { id: number; status: string; reason: string; created_at: string };
type Account = { id: number; name: string; username: string; is_active: boolean; lock_version: number; permissions: string[]; blockers: string[] };
type Preview = { can_merge: boolean; blockers: string[]; effect: string; canonical_code: string; duplicate_code: string; preview_hash: string };
type Detail = Row & { lock_version: number; baseline?: string; proposed?: string; current?: Record<string, string | null>; shared_identity?: boolean; requester?: string; current_preview?: Preview; decision_reason: string | null; can_approve: boolean };

export default function ReceptionAdmin() {
  const identity = useIdentity(), params = useSearchParams();
  const defaultKind = (Object.keys(scopes) as Kind[]).find(k => directoryFacility(identity.access, scopes[k], params.get("facility_id")).entry) || "corrections";
  const kind: Kind = params.get("tab") === "accounts" ? "accounts" : params.get("tab") === "duplicates" ? "duplicates" : params.has("tab") ? "corrections" : defaultKind;
  const { entry } = directoryFacility(identity.access, scopes[kind], params.get("facility_id"));
  if (!entry) return <section className={styles.panel}><h2>مراجعة بيانات المرضى</h2><p role="alert">لا تملك صلاحية هذه المراجعة في المنشأة المحددة.</p></section>;
  const f = entry.facility.id;
  return <div className={styles.screen}><header className={styles.heading}><div><h2>{labels[kind]}</h2><p>{entry.facility.name_ar}</p></div></header>
    <nav className={styles.actions} aria-label="مراجعة بيانات المرضى">{(Object.keys(scopes) as Kind[]).filter(k => entry.permissions.includes(scopes[k])).map(k => <Link className={styles.secondary} key={k} href={`/reception-admin?facility_id=${f}&tab=${k}`}>{labels[k]}</Link>)}</nav>
    <Workspace key={`${f}:${kind}:${params.get("id") || "list"}`} facility={f} kind={kind} id={params.get("id")} page={Number(params.get("page")) || 1} />
  </div>;
}

function Workspace({ facility, kind, id, page }: { facility: number; kind: Kind; id: string | null; page: number }) {
  const list = useClinicRequest<{ rows: (Row & Account)[]; page: number; last_page: number; grantable?: string[] }>(!id ? `reception/reviews/${kind}?facility_id=${facility}&page=${page}` : null);
  const detail = useClinicRequest<Detail>(id && kind !== "accounts" ? `reception/reviews/${kind}/${id}?facility_id=${facility}` : null);
  const [selected, setSelected] = useState<Account | null>(null);
  if (id && kind !== "accounts") return <><DirectoryBack href={`/reception-admin?facility_id=${facility}&tab=${kind}`}>العودة للقائمة</DirectoryBack>{detail.error && <p role="alert">{detail.error}</p>}{detail.data ? <Review data={detail.data} kind={kind} facility={facility} refresh={detail.retry} /> : <p role="status">جارٍ تحميل الطلب…</p>}<button className={styles.secondary} onClick={detail.retry}>جلب أحدث مراجعة</button></>;
  return <>
    {kind === "duplicates" && <DuplicateForm facility={facility} refresh={list.retry} />}
    {list.error && <p role="alert">{list.error}<button onClick={list.retry}>إعادة المحاولة</button></p>}
    {list.loading && <p role="status">جارٍ تحديث القائمة…</p>}
    {kind === "accounts" ? <DirectoryTable label="حسابات الاستقبال" headers={["الاسم", "الدخول", "الحالة", "الإجراء"]}>{list.data?.rows.map(row => <tr key={row.id}><td>{row.name}</td><td>{row.username}</td><td>{row.is_active ? "فعال" : "مجمّد"}</td><td><button className={styles.secondary} disabled={row.blockers.length > 0} onClick={() => setSelected(row)}>إدارة الحساب</button>{row.blockers.map(text => <small key={text}>{text}</small>)}</td></tr>)}</DirectoryTable>
      : <DirectoryTable label={labels[kind]} headers={["الطلب", "الحالة", "السبب", "التاريخ", "المراجعة"]}>{list.data?.rows.map(row => <tr key={row.id}><td>{row.id}</td><td>{statusLabel(row.status)}</td><td>{row.reason}</td><td>{row.created_at}</td><td><Link className={styles.secondary} href={`/reception-admin?facility_id=${facility}&tab=${kind}&id=${row.id}`}>فتح المراجعة</Link></td></tr>)}</DirectoryTable>}
    <div className={styles.actions}>{page > 1 && <Link href={`/reception-admin?facility_id=${facility}&tab=${kind}&page=${page - 1}`}>السابق</Link>}<span>صفحة {page} من {list.data?.last_page || 1}</span>{page < (list.data?.last_page || 1) && <Link href={`/reception-admin?facility_id=${facility}&tab=${kind}&page=${page + 1}`}>التالي</Link>}</div>
    {selected && <AccountEditor key={`${selected.id}:${selected.lock_version}`} data={selected} facility={facility} grantable={list.data?.grantable || []} done={() => { setSelected(null); list.retry(); }} />}
  </>;
}

function Review({ data, kind, facility, refresh }: { data: Detail; kind: Kind; facility: number; refresh: () => void }) {
  const [reason, setReason] = useState(""), [confirmed, setConfirmed] = useState(false), [message, setMessage] = useState("");
  const write = useReviewWrite();
  const proposed: Record<string, string | null> = data.proposed ? JSON.parse(data.proposed) : {};
  const baseline: Record<string, string | null> = data.baseline ? JSON.parse(data.baseline) : {};
  async function decide(decision: string) {
    if (!confirmed || !reason.trim() || write.busy || data.status !== "pending") return;
    const saved = await write.save(`reception/reviews/${kind}/${data.id}/decision`, { facility_id: facility, lock_version: data.lock_version, decision, reason });
    if (saved) { setMessage(decision === "approved" ? (kind === "duplicates" ? "نُفّذ ربط الهوية المكررة الآمن؛ لم تُنقل أي واقعة." : "اعتُمد التصحيح.") : "رُفض الطلب مع حفظ السبب."); setConfirmed(false); refresh(); }
  }
  return <section className={styles.panel}><h3>طلب #{data.id} — {statusLabel(data.status)}</h3><p>مقدم الطلب: {data.requester || "مسجل في التدقيق"}</p><p>التاريخ: {data.created_at}</p><p>السبب: {data.reason}</p>
    {kind === "corrections" && <><p className={styles.hint}>{data.shared_identity ? "الهوية مشتركة بين منشآت؛ ستتغير بياناتها التعريفية للجميع دون تغيير أي واقعة طبية." : "التصحيح يغيّر الهوية المشتركة، لا التاريخ الطبي."}</p><DirectoryTable label="مقارنة تصحيح الهوية" headers={["الحقل", "عند الطلب", "الحالي", "المقترح"]}>{Object.entries(proposed).map(([key, value]) => <tr key={key}><th>{identityLabels[key] || key}</th><td>{baseline[key] || "غير مسجل"}</td><td>{data.current?.[key] || "غير مسجل"}</td><td>{value || "غير مسجل"}</td></tr>)}</DirectoryTable></>}
    {data.current_preview && <Impact preview={data.current_preview} />}
    {data.decision_reason && <p>سبب القرار: {data.decision_reason}</p>}
    {data.status === "pending" && <><label>سبب القرار<textarea value={reason} disabled={write.busy} maxLength={255} onChange={e => setReason(e.target.value)} /></label><label><input type="checkbox" checked={confirmed} disabled={write.busy} onChange={e => setConfirmed(e.target.checked)} /> راجعت القيم الحالية وأثر القرار</label>
      <div className={styles.actions}><button className={styles.primary} disabled={write.busy || !confirmed || !reason.trim() || !data.can_approve || data.current_preview?.can_merge === false} onClick={() => void decide("approved")}>الموافقة والتنفيذ</button><button className={styles.secondary} disabled={write.busy || !confirmed || !reason.trim()} onClick={() => void decide("rejected")}>رفض الطلب</button></div>{!data.can_approve && <p className={styles.hint}>الموافقة تتطلب التفويض العالمي الصريح للهوية؛ يمكنك المراجعة أو الرفض فقط.</p>}</>}
    {write.error && <p role="alert">{write.error} لم تُطبّق تغييرات تلقائية؛ أعد جلب الطلب وراجع النسخة أو اطلب تقديم طلب جديد.</p>}{message && <p role="status">{message}</p>}
  </section>;
}

function Impact({ preview }: { preview: Preview }) {
  return <div><p>المعتمد: <bdi>{preview.canonical_code}</bdi> — المكرر: <bdi>{preview.duplicate_code}</bdi></p><p>{preview.effect}</p><p>{preview.can_merge ? "المعاينة تسمح بالدمج المحدود بعد قرار صريح." : "الدمج ممنوع؛ السجلان محفوظان للمراجعة."}</p>{preview.blockers.map(text => <p role="note" key={text}>{text}</p>)}</div>;
}

function DuplicateForm({ facility, refresh }: { facility: number; refresh: () => void }) {
  const [search, setSearch] = useState(""), [submitted, setSubmitted] = useState(""), [canonical, setCanonical] = useState(""), [duplicate, setDuplicate] = useState(""), [pair, setPair] = useState("");
  const [reason, setReason] = useState(""), [message, setMessage] = useState("");
  const patients = useClinicRequest<{ id: number; code: string; first_name: string; family_name: string }[]>(submitted ? `reception/reviews/patients?facility_id=${facility}&search=${encodeURIComponent(submitted)}` : null);
  const preview = useClinicRequest<Preview>(pair ? `reception/reviews/duplicates/preview?facility_id=${facility}&${pair}` : null);
  const write = useReviewWrite();
  async function send() {
    if (!preview.data || !reason.trim() || write.busy) return;
    const saved = await write.save(`reception/reviews/duplicates`, { facility_id: facility, canonical_dossier_id: Number(canonical), duplicate_dossier_id: Number(duplicate), preview_hash: preview.data.preview_hash, reason });
    if (saved) { setMessage("سُجّل طلب المراجعة؛ لم تُدمج سجلات بعد."); refresh(); }
  }
  return <section className={styles.panel}><h3>معاينة تكرار الهوية</h3><label>البحث بالاسم أو الكود<input value={search} onChange={e => setSearch(e.target.value)} /></label><button className={styles.secondary} disabled={search.trim().length < 3} onClick={() => { setSubmitted(search.trim()); setCanonical(""); setDuplicate(""); setPair(""); }}>البحث في المنشأة</button>
    {patients.error && <p role="alert">{patients.error}</p>}<div className={styles.fields}>{[["السجل المعتمد", canonical, setCanonical], ["السجل المكرر", duplicate, setDuplicate]].map(([label, value, setter]) => <label key={label as string}>{label as string}<select value={value as string} disabled={write.busy} onChange={e => { (setter as (value: string) => void)(e.target.value); setPair(""); }}><option value="">اختر من نتائج البحث</option>{patients.data?.map(p => <option value={p.id} key={p.id}>{p.code} — {p.first_name} {p.family_name}</option>)}</select></label>)}</div>
    <button className={styles.secondary} disabled={!canonical || !duplicate || canonical === duplicate || write.busy} onClick={() => setPair(`canonical_dossier_id=${canonical}&duplicate_dossier_id=${duplicate}`)}>معاينة الأثر</button>
    {preview.error && <p role="alert">{preview.error}</p>}{preview.data && <><Impact preview={preview.data} /><label>سبب طلب المراجعة<textarea value={reason} maxLength={255} onChange={e => setReason(e.target.value)} /></label><button className={styles.primary} disabled={write.busy || !reason.trim()} onClick={() => void send()}>تسجيل طلب مراجعة التكرار</button></>}
    {write.error && <p role="alert">{write.error}</p>}{message && <p role="status">{message}</p>}
  </section>;
}

function AccountEditor({ data, facility, grantable, done }: { data: Account; facility: number; grantable: string[]; done: () => void }) {
  const [active, setActive] = useState(data.is_active), [permissions, setPermissions] = useState(data.permissions), [reason, setReason] = useState("");
  const write = useReviewWrite();
  async function save() {
    if (write.busy || !reason.trim()) return;
    const saved = await write.save(`reception/reviews/accounts/${data.id}`, { facility_id: facility, lock_version: data.lock_version, is_active: active, permissions, reason }, "PUT");
    if (saved) done();
  }
  return <section className={styles.panel} aria-label="إدارة حساب الاستقبال"><h3>{data.name}</h3><p>التجميد لا يحذف الحساب أو أعماله، ويُلغي جميع توكناته.</p><fieldset disabled={write.busy}><label><input type="checkbox" checked={active} onChange={e => setActive(e.target.checked)} /> الحساب فعال</label>{grantable.map(code => <label key={code}><input type="checkbox" checked={permissions.includes(code)} onChange={e => setPermissions(p => e.target.checked ? [...p, code] : p.filter(c => c !== code))} />{permissionLabels[code] || code}</label>)}<label>سبب تغيير الحساب<textarea maxLength={255} value={reason} onChange={e => setReason(e.target.value)} /></label></fieldset>
    {write.error && <p role="alert">{write.error} أغلق المراجعة واجلب القائمة المحدثة قبل قرار جديد.</p>}<div className={styles.actions}><button className={styles.primary} disabled={write.busy || !reason.trim()} onClick={() => void save()}>تأكيد تغيير الحساب</button><button className={styles.secondary} disabled={write.busy} onClick={done}>إلغاء</button></div></section>;
}
