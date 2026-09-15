"use client";
import ConflictReview from "../blood-bank/ConflictReview";
import { clinicalDraft, type ClinicalDraft, type SavedRow } from "./clinical";
import type { Snapshot } from "./wizard";
const names:Record<string,string>={catalog:"الخدمة أو الإجراء",clinic:"العيادة",doctor:"الطبيب",note:"الملاحظة",medication:"الدواء",prescribed_on:"تاريخ الوصفة",outcome_on:"تاريخ النتيجة",code:"النتيجة",remove:"الإزالة",void_reason:"سبب التصحيح",display_order:"الترتيب",referral_target:"مشفى الوجهة",outgoing_referral_date:"تاريخ الإحالة الصادرة",outgoing_referral_reason:"سبب الإحالة الصادرة"};
function flatten(d:ClinicalDraft,current:ClinicalDraft,step:number){
  const values:Record<string,string>={},labels:Record<string,string>={};
  function row(prefix:string,r:SavedRow & Record<string,unknown>){
    if(!r.id){values[`${prefix}.new`]=JSON.stringify(r);labels[`${prefix}.new`]=`إضافة ${prefix} الجديدة من مسودتي`;return;}
    for(const [k,v] of Object.entries(r))if(!["id","lock_version","key","items"].includes(k)){const key=`${prefix}.${k}`;values[key]=JSON.stringify(v??null);labels[key]=`${prefix} · ${names[k]??k}`;}
  }
  if(step===3)for(const kind of ["services","procedures"] as const)for(const r of d[kind]){if(r.id&&!current[kind].some(c=>c.id===r.id))continue;row(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`,r);}
  if(step===4){
    if(d.prescription&&(!d.prescription.id||d.prescription.id===current.prescription?.id)){
      row("الوصفة",d.prescription);
      if(d.prescription.id)for(const r of d.prescription.items)if(!r.id||current.prescription?.items.some(c=>c.id===r.id))row(`دواء:${r.key}`,r);
    }
    if(d.outcome&&(!d.outcome.id||d.outcome.id===current.outcome?.id))row("النتيجة",d.outcome);
  }
  return {values,labels};
}
const show=(value:unknown):string=>{if(value===null||value===undefined)return "غير محدد";if(typeof value==="boolean")return value?"نعم":"لا";if(typeof value==="object"){const row=value as Record<string,unknown>;if(row.name_ar)return String(row.name_ar);return Object.entries(row).filter(([k])=>!["id","key","lock_version","display_order"].includes(k)).map(([k,v])=>`${names[k]??(k==="items"?"بنود الوصفة":k)}: ${Array.isArray(v)?v.map(show).join("؛ "):show(v)}`).join(" · ");}return String(value);};
export default function ClinicalConflict({step,latest,draft,onAccept}:{step:number;latest:Snapshot;draft:ClinicalDraft;onAccept:(value:ClinicalDraft)=>void}){
  const current=clinicalDraft(latest);
  // When another editor created the header first, review my fields against that
  // authoritative header. New items are still separate opt-in additions.
  const candidate={...draft,
    prescription:draft.prescription&&!draft.prescription.id&&current.prescription?{...draft.prescription,id:current.prescription.id,lock_version:current.prescription.lock_version}:draft.prescription,
    outcome:draft.outcome&&!draft.outcome.id&&current.outcome?{...draft.outcome,id:current.outcome.id,lock_version:current.outcome.lock_version}:draft.outcome,
  };
  const fresh=flatten(current,current,step),mine=flatten(candidate,current,step);
  return <ConflictReview key={`${step}:${latest.visit?.lock_version}`} latest={fresh.values} draft={{...fresh.values,...mine.values}} labels={{...fresh.labels,...mine.labels}} format={(_,v)=>show(JSON.parse(v))} onAccept={fields=>{
    const read=(prefix:string)=>fields[`${prefix}.new`]?JSON.parse(fields[`${prefix}.new`]):Object.fromEntries(Object.entries(fields).filter(([k])=>k.startsWith(prefix+".")).map(([k,v])=>[k.slice(prefix.length+1),JSON.parse(v)]));
    const result=structuredClone(current);
    if(step===3)for(const kind of ["services","procedures"] as const){const merged=[...current[kind],...draft[kind].filter(r=>!r.id)];result[kind]=merged.filter(r=>Object.keys(read(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`)).length).map(r=>({...r,...read(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`)}));}
    if(step===4){
      if(fields['الوصفة.new']) result.prescription=read('الوصفة');
      else if(current.prescription){const items=[...current.prescription.items,...(draft.prescription?.items.filter(i=>!i.id)??[])];result.prescription={...current.prescription,...read('الوصفة'),items:items.filter(i=>Object.keys(read(`دواء:${i.key}`)).length).map(i=>({...i,...read(`دواء:${i.key}`)}))};}
      if(fields['النتيجة.new'])result.outcome=read('النتيجة');else if(current.outcome)result.outcome={...current.outcome,...read('النتيجة')};
    }
    onAccept(result);
  }}/>;
}
