"use client";
import { useEffect, useRef, useState } from "react";
import { apiRequest, AuthError } from "../auth/api";
import styles from "../clinics/clinics.module.css";

import {treatmentColumns} from "./oncology";
export const reportColumns={...treatmentColumns,pathology_status:"حالة التشريح المرضي",sequence:"م",code:"كود المريض",name:"اسم المريض",mother_name:"اسم الأم",gender:"الجنس",birth_date:"الميلاد",phone:"الهاتف",paper_file_number:"رقم الملف الورقي",opening_date:"بداية الملف الطبي في المشفى",latest_visit_date:"تاريخ آخر زيارة",status:"حالة السياق الطبي",is_oncology:"الحالة الورمية",diagnoses:"تشخيصات آخر زيارة",clinics:"العيادات",doctors:"الأطباء المسؤولون",visit_count:"عدد الزيارات",procedure_count:"عدد الإجراءات"};
export const defaultColumns: (keyof typeof reportColumns)[] = ["sequence","code","name","mother_name","gender","birth_date","phone","paper_file_number","opening_date","latest_visit_date","status","is_oncology"];
export default function DossierReports({path,filters,ready,allowed}:{path:string;filters:string;ready:boolean;allowed:boolean}){
  const pending=useRef<AbortController|null>(null);const [busy,setBusy]=useState(false),[error,setError]=useState("");
  useEffect(()=>()=>pending.current?.abort(),[path,filters,ready]);
  async function download(format:"pdf"|"xlsx"){
    if(!ready||!allowed||pending.current)return;
    const c=new AbortController();pending.current=c;setBusy(true);setError("");
    const q=new URLSearchParams(filters),body:Record<string,unknown>=Object.fromEntries(q);const columns=q.getAll("columns[]");delete body["columns[]"];if(columns.length)body.columns=columns;delete body.page;delete body.per_page;
    try{const {blob,filename}=await apiRequest<{blob:Blob;filename?:string}>(`${path}/${format}`,{method:"POST",signal:c.signal,body:JSON.stringify(body)},"blob");if(c.signal.aborted)return;if(!filename)throw new Error("تعذّر قراءة اسم التقرير.");const url=URL.createObjectURL(blob),a=document.createElement("a");a.href=url;a.download=filename;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
    catch(e){if(!c.signal.aborted)setError(e instanceof AuthError?Object.values(e.fields)[0]??e.message:e instanceof Error?e.message:"تعذّر التصدير.");}
    finally{if(pending.current===c)pending.current=null;setBusy(false);}
  }
  if(!allowed)return null;
  return <div><div className={styles.actions}><button type="button" className={styles.secondary} disabled={!ready||busy} onClick={()=>void download("xlsx")}>{busy?"جارٍ إنشاء التقرير…":"تصدير Excel"}</button><button type="button" className={styles.secondary} disabled={!ready||busy} onClick={()=>void download("pdf")}>تصدير PDF</button></div>{!ready&&<p className={styles.hint}>يتاح التصدير بعد نجاح تحميل النتائج الحالية.</p>}{error&&<p role="alert">{error}</p>}</div>;
}
