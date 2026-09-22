"use client";
import {useEffect,useRef,useState} from "react";
import {apiRequest,subscribeSession} from "../auth/api";
import {useClinicRequest,type Page} from "../clinics/api";
import {Pagination} from "../directory/Controls";
import {DirectoryTable} from "../directory/DirectoryPrimitives";
import OncologyEditor from "./OncologyEditor";
import {intents,modalities,sessionStates,type Dose,type Dispensing,type Plan,type Session,type SessionDose,type EditorKind} from "./oncology";
import type {Visit} from "./api";
import {sourceLabels} from "./api";
import {dispositionLabels} from "./pathology";
import styles from "../clinics/clinics.module.css";
import oncology from "./oncology.module.css";
function excerpt(value?:string){const text=(value??"").replace(/\s+/g," ").trim();return text?text.length>90?`${text.slice(0,90)}…`:text:"دون نص";}
function StatusBadge({status}:{status:string}) {
  const tone = status === "completed" ? styles.active : status === "cancelled" ? styles.badge : oncology.scheduled;
  return <span className={tone}>{sessionStates[status] ?? status}</span>;
}
type MedicationPane="unlinked"|"outside";
type Props={facility:number;dossier:number;caps:Record<string,boolean>;revision:number;onChanged:()=>void;visit?:Visit;medicationView?:MedicationPane;medicationSource?:string|null};
type Editing={kind:EditorKind;plan?:Plan;session?:Session;dose?:Dose;dispensing?:Dispensing;dispensePurpose?:"unlinked"|"take_home"|"supportive"};
export default function OncologyPanel(props:Props){const {facility,dossier,caps,revision,onChanged,visit,medicationView,medicationSource}=props;const allowed=!!caps.treatment_view;
 const [page,setPage]=useState(1),[size,setSize]=useState(10),[sessionPage,setSessionPage]=useState(1),[sessionSize,setSessionSize]=useState(50),[editing,setEditing]=useState<Editing|null>(null),[details,setDetails]=useState<Plan|null>(null),[selectedId,setSelectedId]=useState<number|null>(null),[error,setError]=useState<string|null>(null),[busy,setBusy]=useState(false);
 const pending=useRef<AbortController|null>(null);
 useEffect(()=>{const clear=()=>{pending.current?.abort();setEditing(null);setDetails(null);};const unsub=subscribeSession(clear);return()=>{unsub();pending.current?.abort();};},[]);
 const base=`dossiers/${dossier}`;
 const plans=useClinicRequest<Page<Plan>&{readiness:{disposition:string}|null;next_dose:{planned_on:string}|null}>(allowed&&!medicationView?`${base}/treatment-plans?facility_id=${facility}&page=${page}&per_page=${size}`:null,true,true,revision);
 const sessions=useClinicRequest<Page<Session>>(allowed&&!medicationView?`${base}/treatment-sessions?facility_id=${facility}&page=${sessionPage}&per_page=${sessionSize}`:null,true,true,revision);
 const planSessions=useClinicRequest<Page<Session>>(details?`${base}/treatment-sessions?facility_id=${facility}&plan_id=${details.id}&per_page=50`:null,true,true,revision);
 const actual=useClinicRequest<{doses:Dose[];dispensed:Dispensing[]}>(allowed&&visit?`${base}/visits/${visit.id}/doses?facility_id=${facility}`:null,false,true,revision);
 const rows=sessions.data?.data??[];
 const administrable=(s:Session)=>!!visit&&!!s.plan_id&&s.planned_on===visit.visit_date&&s.revision_id===s.current_revision_id&&!s.dose_id&&s.status==="scheduled";
 useEffect(()=>{
  if(!rows.length){setSelectedId(null);return;}
  setSelectedId(id=>id&&rows.some(s=>s.id===id)?id:(rows.find(administrable)?.id??rows[0].id));
 },[rows,visit?.id,visit?.visit_date]);
 async function loadPlan(id:number,kind?:EditorKind,session?:Session){if(pending.current)return;const c=new AbortController();pending.current=c;setBusy(true);setError(null);try{const plan=await apiRequest<Plan>(`${base}/treatment-plans/${id}?facility_id=${facility}`,{signal:c.signal});if(!c.signal.aborted){if(kind)setEditing({kind,plan,session});else setDetails(plan);}}catch(e){if(!c.signal.aborted)setError(e instanceof Error?e.message:"تعذّر تحميل الخطة.");}finally{if(pending.current===c)pending.current=null;if(!c.signal.aborted)setBusy(false);}}
 if(!allowed)return null;
 const selected=rows.find(s=>s.id===selectedId)??null;
 const sessionDoses:SessionDose[]=selected?.doses??[];
 const changed=()=>{setEditing(null);setDetails(null);onChanged();};
 const dispensed=actual.data?.dispensed??[];
 const purposeOf=(row:Dispensing)=>row.dispensing_purpose==="take_home"?"take_home":row.dispensing_purpose==="supportive"?"supportive":"unlinked";
 const paneTitle=medicationView==="unlinked"?"صرف غير مرتبط بالجرعة":medicationView==="outside"?"صرف خارج المشفى":visit?"جلسات وجرعات البطاقة":"الخطة ومواعيد العلاج";
 const paneLabel=medicationView?"صرف أدوية الزيارة":visit?"العلاج الفعلي للزيارة":"الخطة ومواعيد العلاج";
 const sessionActions=(s:Session,plan?:Plan)=><div className={styles.actions}>
  {caps.treatment_schedule&&s.status==="scheduled"&&<button className={styles.secondary} onClick={()=>setEditing({kind:"session-dose",plan,session:s})}>إضافة جرعة علاجية</button>}
  {caps.treatment_schedule&&s.status!=="completed"&&<button className={styles.secondary} onClick={()=>setEditing({kind:"session",session:s})}>تغيير الموعد أو حالته</button>}
  {administrable(s)&&caps.treatment_administer&&<button className={styles.primary} disabled={busy||sessions.loading} onClick={()=>void loadPlan(s.plan_id!,"dose",s)}>تسجيل إعطاء جرعة</button>}
 </div>;
 return <section className={styles.detailPanel} aria-label={paneLabel}><header className={styles.heading}><div><h2>{paneTitle}</h2><p>{medicationView?`الزيارة ${visit?.visit_no} · ${visit?.visit_date}. هذا الصرف لا يرتبط بجرعة. الأدوية المرتبطة بالجرعة تُفتح فقط عند تسجيل الجرعة.`:visit?`الزيارة ${visit.visit_no} · ${visit.visit_date}. تظهر كل جلسات البطاقة وجرعاتها. الجلسة المجدولة بتاريخ هذه الزيارة يمكن إعطاء جرعتها هنا.`:"الخطة عدة مواعيد. لكل جلسة تاريخ زيارة وحالة، وجدول جرعات مرتبط بها."}</p></div></header>
 {!medicationView&&<div className={oncology.choices}>{caps.treatment_create&&<div className={oncology.choice}><button className={styles.primary} onClick={()=>setEditing({kind:"plan"})}>إضافة خطة علاجية</button><small>النمط، النية، نص البروتوكول، والطبيبان. ثم تُضاف مواعيد الجلسات داخل الخطة.</small></div>}{caps.treatment_schedule&&<div className={oncology.choice}><button className={styles.secondary} onClick={()=>setEditing({kind:"appointment"})}>موعد بدون خطة</button><small>جلسة واحدة بتاريخ زيارة وطبيب، ويمكن تسجيل جرعاتها دون خطة.</small></div>}</div>}
 {error&&<p role="alert">{error}</p>}{busy&&<p role="status">جارٍ تحميل النسخة الحالية…</p>}
 {!medicationView&&<>{plans.loading&&<p role="status">جارٍ تحميل الخطط…</p>}{plans.error&&<p role="alert">{plans.error}<button onClick={plans.retry}>إعادة المحاولة</button></p>}{plans.data&&<><div className={styles.resultSummary}><span>الاستعداد التشخيصي: {dispositionLabels[plans.data.readiness?.disposition??"not_assessed"]}</span><strong>الموعد القادم: {plans.data.next_dose?.planned_on??"لا موعد مؤهل حاليًا"}</strong></div><DirectoryTable label="الخطط العلاجية" headers={["الخطة","البروتوكول","الإجراءات"]} busy={plans.loading}>{plans.data.data.map(plan=><tr key={plan.id}><td><bdi>{plan.plan_number}</bdi><small className={styles.hint}>{modalities[plan.modality??""]} · {intents[plan.intent??""]}</small></td><td>{excerpt(plan.protocol_text)}</td><td><div className={styles.actions}><button className={styles.primary} disabled={busy} onClick={()=>void loadPlan(plan.id)}>فتح الخطة</button>{caps.treatment_update&&<button className={styles.secondary} disabled={busy} onClick={()=>void loadPlan(plan.id,"plan")}>تعديل الخطة</button>}</div></td></tr>)}</DirectoryTable><Pagination meta={plans.data.meta} onPage={setPage} onPageSize={n=>{setSize(Number(n));setPage(1);}}/></>}
 {details&&<section className={styles.panel}><div className={styles.toolbar}><h3>جلسات {details.plan_number}</h3><div className={styles.actions}>{caps.treatment_schedule&&<button className={styles.primary} disabled={busy} onClick={()=>setEditing({kind:"schedule",plan:details})}>إضافة موعد للخطة</button>}<button className={styles.secondary} onClick={()=>setDetails(null)}>إغلاق</button></div></div>{details.revisions[0]&&<p>{excerpt(String(details.revisions[0].protocol_text??""))} · {details.revisions[0].treating_doctor_name}</p>}{planSessions.loading&&<p role="status">جارٍ تحميل جلسات الخطة…</p>}{planSessions.data&&<DirectoryTable label={`جلسات ${details.plan_number}`} headers={["الجلسة","تاريخ الزيارة","الحالة","الإجراءات"]}>{planSessions.data.data.map(s=><tr key={s.id}><td>جلسة {s.session_number}</td><td>{s.planned_on}</td><td><StatusBadge status={s.status}/></td><td>{sessionActions(s,details)}</td></tr>)}{!planSessions.data.data.length&&<tr><td colSpan={4}>لا مواعيد بعد. أضف موعد الجلسة الأولى.</td></tr>}</DirectoryTable>}</section>}
 <div className={oncology.board}>
  <section className={oncology.boardBlock}>
   <div className={oncology.boardHead}><h3>جدول الجلسات</h3></div>
   <p className={styles.hint}>كل جلسات البطاقة، بما فيها المجدولة لاحقًا والمكتملة والملغاة. النقر على صف يفتح جرعات تلك الجلسة.</p>
   {sessions.loading&&<p role="status">جارٍ تحميل المواعيد…</p>}{sessions.error&&<p role="alert">{sessions.error}<button onClick={sessions.retry}>إعادة المحاولة</button></p>}
   {sessions.data&&<><DirectoryTable label="جدول الجلسات" headers={["الجلسة","الخطة","تاريخ الزيارة","الحالة","الإجراءات"]} busy={sessions.loading}>{rows.map(s=><tr key={s.id} aria-selected={s.id===selectedId} className={`${oncology.sessionRow}${s.id===selectedId?` ${oncology.selectedRow}`:""}`} onClick={()=>setSelectedId(s.id)}><td><button type="button" className={`${styles.textButton} ${oncology.nameButton}`}>{s.plan_id?`جلسة ${s.session_number}`:`موعد مستقل #${s.id}`}</button></td><td>{s.plan_id?s.plan_number:"بدون خطة"}</td><td>{s.planned_on}{visit&&s.planned_on===visit.visit_date&&<small className={oncology.todayMark}>تاريخ هذه الزيارة</small>}</td><td><StatusBadge status={s.status}/>{s.revision_id!==s.current_revision_id&&s.status!=="completed"&&<small className={styles.hint}>نسخة علاجية سابقة — تحتاج معالجة قبل الإعطاء</small>}{s.has_voided_dose&&<small>توجد وقائع إعطاء مبطلة محفوظة تاريخيًا</small>}</td><td onClick={e=>e.stopPropagation()}>{sessionActions(s)}</td></tr>)}{!rows.length&&<tr><td colSpan={5}>لا توجد جلسات مسجلة على البطاقة.</td></tr>}</DirectoryTable><Pagination meta={sessions.data.meta} onPage={setSessionPage} onPageSize={n=>{setSessionSize(Number(n));setSessionPage(1);}}/></>}
  </section>
  <section className={oncology.boardBlock}>
   <div className={oncology.boardHead}><h3>جدول الجرعات{selected?` · ${selected.plan_id?`${selected.plan_number} / جلسة ${selected.session_number}`:`موعد #${selected.id}`}`:""}</h3>{selected&&caps.treatment_schedule&&selected.status==="scheduled"&&<button className={styles.secondary} onClick={()=>setEditing({kind:"session-dose",session:selected})}>إضافة جرعة علاجية</button>}</div>
   <p className={styles.hint}>جرعات الجلسة المختارة مع تاريخ زيارة كل جرعة.</p>
   <DirectoryTable label="جدول الجرعات" headers={["الجرعة","تاريخ الزيارة","المصدر","الممرض"]}>{sessionDoses.map(dose=><tr key={dose.id}><td>{dose.dose_name}</td><td>{dose.given_on}{visit&&dose.given_on===visit.visit_date&&<small className={oncology.todayMark}>تاريخ هذه الزيارة</small>}</td><td>{sourceLabels[dose.medication_source??""]??"غير مسجل"}</td><td>{dose.nurse_name??"غير مسجل"}</td></tr>)}{selected&&!sessionDoses.length&&<tr><td colSpan={4}>لا جرعات بعد لهذه الجلسة.</td></tr>}{!selected&&<tr><td colSpan={4}>اختر جلسة من الجدول أعلاه.</td></tr>}</DirectoryTable>
  </section>
  {visit&&<section className={oncology.boardBlock}>
   <h3>إعطاء هذه الزيارة</h3>
   {actual.loading&&<p role="status">جارٍ تحديث الوقائع الفعلية…</p>}{actual.error&&<p role="alert">{actual.error}<button onClick={actual.retry}>إعادة المحاولة</button></p>}
   {actual.data&&<DirectoryTable label="إعطاء هذه الزيارة" headers={["الجلسة","تاريخ الزيارة","الأدوية","الحالة","الإجراءات"]}>{actual.data.doses.map(d=>{const linked=dispensed.filter(row=>row.dose_session_id===d.id);return <tr key={d.id}><td>{d.oncology_session_id?`جلسة #${d.oncology_session_id}`:`إعطاء #${d.id}`}</td><td>{String(d.administered_on??"")}</td><td>{d.items.map(i=>i.medication_name_snapshot).join(" · ")||"بدون دواء"}{!!linked.length&&<small className={styles.hint}>{linked.length} صرف مرتبط</small>}</td><td>{d.voided_at?"إعطاء مبطل — محفوظ تاريخيًا":"تمت"}{linked.some(row=>row.parent_voided_at&&!row.voided_at)&&<small className={styles.hint}>صرف فعال مستقل مرتبط بإعطاء مبطل</small>}</td><td>{!d.voided_at&&d.oncology_session_id&&<div className={styles.actions}>{caps.treatment_correct&&<button className={styles.secondary} onClick={()=>setEditing({kind:"dose",dose:d})}>تصحيح الإعطاء</button>}{caps.treatment_void&&caps.treatment_schedule&&<button className={`${styles.secondary} ${styles.dangerText}`} onClick={()=>setEditing({kind:"void-dose",dose:d})}>إبطال الإعطاء</button>}{caps.treatment_dispense&&<button className={styles.primary} onClick={()=>setEditing({kind:"dispense",dose:d,dispensePurpose:"supportive"})}>دواء مرتبط بهذه الجرعة</button>}</div>}</td></tr>;})}{!actual.data.doses.length&&<tr><td colSpan={5}>لا إعطاء مسجّل في هذه الزيارة.</td></tr>}</DirectoryTable>}
  </section>}
 </div>
 </>}
 {visit&&<>{actual.loading&&medicationView&&<p role="status">جارٍ تحديث الوقائع الفعلية…</p>}{actual.error&&medicationView&&<p role="alert">{actual.error}<button onClick={actual.retry}>إعادة المحاولة</button></p>}{actual.data&&<>
 {medicationView&&<>{caps.treatment_dispense&&<div className={styles.actions}><button type="button" className={styles.primary} onClick={()=>setEditing({kind:"dispense",dispensePurpose:medicationView==="outside"?"take_home":"unlinked"})}>{medicationView==="outside"?"تسجيل صرف خارج المشفى":"تسجيل صرف غير مرتبط بالجرعة"}</button></div>}<p className={styles.hint}>الأدوية المرتبطة بالجرعة تُفتح فقط عند تسجيل الجرعة.</p><DirectoryTable label={paneTitle} headers={["اسم الدواء المحفوظ","تاريخ الصرف","الكمية","الإجراءات"]}>{dispensed.filter(d=>d.dispensing_purpose===(medicationView==="outside"?"take_home":"unlinked")).map(d=><tr key={d.id}><td>{d.medication_name_snapshot}{d.voided_at&&" · مبطل"}</td><td>{String(d.dispensed_on??"")}</td><td>{d.quantity} {d.quantity_unit}</td><td>{!d.voided_at&&<div className={styles.actions}>{caps.treatment_correct&&<button className={styles.secondary} onClick={()=>setEditing({kind:"dispense",dispensing:d,dispensePurpose:purposeOf(d)})}>تصحيح الصرف</button>}{caps.treatment_void&&<button className={`${styles.secondary} ${styles.dangerText}`} onClick={()=>setEditing({kind:"void-dispense",dispensing:d})}>إبطال الصرف</button>}</div>}</td></tr>)}</DirectoryTable></>}</>}</>}
 {editing&&<OncologyEditor {...editing} facility={facility} dossier={dossier} visit={visit} caps={caps} medicationSource={medicationSource} onClose={()=>setEditing(null)} onSaved={changed} onRefresh={onChanged}/>}</section>;
}
