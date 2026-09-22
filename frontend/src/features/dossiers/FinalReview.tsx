"use client";
import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { apiRequest, AuthError } from "../auth/api";
import Modal from "../clinics/Modal";
import Attachments from "./Attachments";
import ReviewSummary from "./ReviewSummary";
import { ClinicalContext } from "./ClinicalEditor";
import type { Context } from "./clinical";
import type { Snapshot } from "./wizard";
import styles from "../clinics/clinics.module.css";
import layout from "./wizard.module.css";

export default function FinalReview({facility,snapshot:s,caps,onSaved,onPending,blocked,reviewed,setReviewed,error,onError,onReviewMode}:{facility:number;snapshot:Snapshot;caps:Record<string,boolean>;onSaved:(s:Snapshot)=>void;onPending:(v:boolean)=>void;blocked:boolean;reviewed:boolean;setReviewed:(v:boolean)=>void;error:(key:string)=>React.ReactNode;onError:(e:AuthError)=>void;onReviewMode:(v:boolean)=>void}){
  const [pane,setPane]=useState<"attachments"|"review">("attachments");
  useEffect(()=>{onReviewMode(pane==="review");},[pane,onReviewMode]);
  const [context,setContext]=useState<Context>({clinic:null,doctor:null}),[confirm,setConfirm]=useState(false),[busy,setBusy]=useState(false),[uploading,setUploading]=useState(false);
  const pending=useRef<AbortController|null>(null),reservation=useRef<{signature:string;id:string}|null>(null);const router=useRouter();
  useEffect(()=>()=>pending.current?.abort(),[]);
  useEffect(()=>{onPending(uploading||busy);},[uploading,busy,onPending]);
  const title=s.status==="draft"?"تفعيل بطاقة المريض وإكمال الزيارة":"إكمال هذه الزيارة";
  const coreSaved=["personal","medical","visit","clinical","medications"].every(section=>s.progress.find(p=>p.section===section)?.state==="saved");
  async function complete(){
    if(pending.current||blocked||uploading||!s.visit)return;
    const c=new AbortController();pending.current=c;setBusy(true);
    const body={facility_id:facility,lock_version:s.visit.lock_version,dossier_lock_version:s.lock_version,clinic_id:context.clinic?.id,attending_staff_id:context.doctor?.id,confirmed:true};
    const signature=JSON.stringify(body);if(reservation.current?.signature!==signature)reservation.current={signature,id:crypto.randomUUID()};
    try{const saved=await apiRequest<Snapshot>(`dossiers/${s.id}/visits/${s.visit.id}/complete`,{method:"POST",signal:c.signal,body:JSON.stringify({...body,request_id:reservation.current.id})});if(!c.signal.aborted){onSaved(saved);setConfirm(false);router.push(`/patient-cards/${s.id}?facility_id=${facility}&visit=${s.visit.id}`);}}
    catch(e){if(!c.signal.aborted){setConfirm(false);onError(e instanceof AuthError?e:new AuthError(0,"FAILED","تعذّر الإكمال؛ البيانات والمسودة باقية."));}}
    finally{pending.current=null;if(!c.signal.aborted)setBusy(false);}
  }
  return <><div className={layout.sectionTabs} role="tablist" aria-label="المرفقات والمراجعة"><button type="button" role="tab" aria-selected={pane==="attachments"} onClick={()=>setPane("attachments")}>1. المرفقات الاختيارية</button><button type="button" role="tab" aria-selected={pane==="review"} disabled={uploading||blocked} onClick={()=>setPane("review")}>2. المراجعة والتأكيد</button></div><div hidden={pane!=="attachments"}><Attachments facility={facility} snapshot={s} caps={caps} onSaved={onSaved} onPending={setUploading} blocked={blocked||busy}/><button type="button" className={styles.primary} disabled={uploading||blocked} onClick={()=>setPane("review")}>الانتقال إلى المراجعة</button></div><div hidden={pane!=="review"}><ReviewSummary snapshot={s}/>{blocked&&<p role="status">احفظ الأقسام الخمسة الأولى وحل التعارضات قبل تفعيل البطاقة.</p>}<label className={layout.checkGroup}><input name="confirmed" type="checkbox" checked={reviewed} disabled={blocked||busy} onChange={e=>setReviewed(e.target.checked)}/>راجعت البيانات المحفوظة والأقسام الاختيارية، بما فيها الأقسام الفارغة.{error("confirmed")}</label><p className={styles.hint}>حفظ الأقسام الخمسة الأولى يكفي لتفعيل البطاقة؛ المرفقات اختيارية وإكمال الزيارة قرار صريح.</p><div className={styles.fields}><ClinicalContext value={context} change={setContext} facility={facility} date={String(s.visit?.visit_date??"")} prefix="" doctorKey="attending_staff_id" label="المسؤول عن إكمال الزيارة" error={error}/></div><p className={styles.hint}>اختر الطبيب المسؤول صراحة؛ لا يُستنتج من أطباء الوصفة أو التشخيصات.</p>{caps.visits_complete&&(s.status==="active"||caps.finalize)&&<button type="button" className={styles.primary} disabled={blocked||busy||uploading||s.visit?.status!=="draft"||!coreSaved} onClick={()=>setConfirm(true)}>{title}</button>}{confirm&&<Modal title={title} busy={busy} onClose={()=>setConfirm(false)}><p>سيصبح السجل الطبي لهذه الزيارة للقراءة فقط. لا تكتمل زيارة أخرى بهذا الإجراء.</p><div className={styles.actions}><button type="button" className={styles.primary} disabled={busy} onClick={()=>void complete()}>أؤكد الإكمال</button><button type="button" className={styles.secondary} disabled={busy} onClick={()=>setConfirm(false)}>إلغاء</button></div></Modal>}</div></>;
}
