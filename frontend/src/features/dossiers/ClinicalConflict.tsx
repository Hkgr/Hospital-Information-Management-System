"use client";
import ConflictReview from "../blood-bank/ConflictReview";
import { clinicalDraft, prescriptionKindLabels, prescriptionKinds, type ClinicalDraft, type SavedRow } from "./clinical";
import type { Snapshot } from "./wizard";
const names:Record<string,string>={catalog:"الخدمة أو الإجراء",clinic:"العيادة",doctor:"الطبيب",note:"الملاحظة",medication:"الدواء",prescribed_on:"تاريخ الوصفة",outcome_on:"تاريخ النتيجة",code:"النتيجة",remove:"الإزالة",void_reason:"سبب التصحيح",display_order:"الترتيب",referral_target:"مشفى الوجهة",outgoing_referral_date:"تاريخ الإحالة الصادرة",outgoing_referral_reason:"سبب الإحالة الصادرة",funding_source_id:"تمويل الوصفة",unavailable_reason:"سبب عدم التواجد في المشفى",kind:"نوع الوصفة"};
function flatten(d:ClinicalDraft,current:ClinicalDraft,step:number){
  const values:Record<string,string>={},labels:Record<string,string>={};
  function row(prefix:string,r:SavedRow & Record<string,unknown>){
    if(!r.id){values[`${prefix}.new`]=JSON.stringify(r);labels[`${prefix}.new`]=`إضافة ${prefix} الجديدة من مسودتي`;return;}
    for(const [k,v] of Object.entries(r))if(!["id","lock_version","key","items"].includes(k)){const key=`${prefix}.${k}`;values[key]=JSON.stringify(v??null);labels[key]=`${prefix} · ${names[k]??k}`;}
  }
  if(step===3)for(const kind of ["services","procedures"] as const)for(const r of d[kind]){if(r.id&&!current[kind].some(c=>c.id===r.id))continue;row(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`,r);}
  if(step===4){
    for(const kind of prescriptionKinds){
      const p=d.prescriptions[kind], currentRx=current.prescriptions[kind];
      if(p&&(!p.id||p.id===currentRx?.id)){
        row(prescriptionKindLabels[kind],p);
        if(p.id)for(const r of p.items)if(!r.id||currentRx?.items.some(c=>c.id===r.id))row(`دواء ${prescriptionKindLabels[kind]}:${r.key}`,r);
      }
    }
    if(d.outcome&&(!d.outcome.id||d.outcome.id===current.outcome?.id))row("النتيجة",d.outcome);
  }
  return {values,labels};
}
const show=(value:unknown):string=>{if(value===null||value===undefined)return "غير محدد";if(typeof value==="boolean")return value?"نعم":"لا";if(typeof value==="object"){const row=value as Record<string,unknown>;if(row.name_ar)return String(row.name_ar);return Object.entries(row).filter(([k])=>!["id","key","lock_version","display_order"].includes(k)).map(([k,v])=>`${names[k]??(k==="items"?"بنود الوصفة":k)}: ${Array.isArray(v)?v.map(show).join("؛ "):show(v)}`).join(" · ");}return String(value);};
export default function ClinicalConflict({step,latest,draft,onAccept}:{step:number;latest:Snapshot;draft:ClinicalDraft;onAccept:(value:ClinicalDraft)=>void}){
  const current=clinicalDraft(latest);
  const candidate={...draft,prescriptions:{...draft.prescriptions},outcome:draft.outcome&&!draft.outcome.id&&current.outcome?{...draft.outcome,id:current.outcome.id,lock_version:current.outcome.lock_version}:draft.outcome};
  for(const kind of prescriptionKinds){
    const p=draft.prescriptions[kind], saved=current.prescriptions[kind];
    if(p&&!p.id&&saved) candidate.prescriptions[kind]={...p,id:saved.id,lock_version:saved.lock_version};
  }
  const fresh=flatten(current,current,step),mine=flatten(candidate,current,step);
  return <ConflictReview key={`${step}:${latest.visit?.lock_version}`} latest={fresh.values} draft={{...fresh.values,...mine.values}} labels={{...fresh.labels,...mine.labels}} format={(_,v)=>show(JSON.parse(v))} onAccept={fields=>{
    const read=(prefix:string)=>fields[`${prefix}.new`]?JSON.parse(fields[`${prefix}.new`]):Object.fromEntries(Object.entries(fields).filter(([k])=>k.startsWith(prefix+".")).map(([k,v])=>[k.slice(prefix.length+1),JSON.parse(v)]));
    const result=structuredClone(current);
    if(step===3)for(const kind of ["services","procedures"] as const){const merged=[...current[kind],...draft[kind].filter(r=>!r.id)];result[kind]=merged.filter(r=>Object.keys(read(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`)).length).map(r=>({...r,...read(`${kind==="services"?"خدمة":"إجراء"}:${r.key}`)}));}
    if(step===4){
      for(const kind of prescriptionKinds){
        const label=prescriptionKindLabels[kind];
        if(fields[`${label}.new`]) result.prescriptions[kind]=read(label);
        else if(current.prescriptions[kind]){const items=[...current.prescriptions[kind]!.items,...(draft.prescriptions[kind]?.items.filter(i=>!i.id)??[])];result.prescriptions[kind]={...current.prescriptions[kind]!,...read(label),items:items.filter(i=>Object.keys(read(`دواء ${label}:${i.key}`)).length).map(i=>({...i,...read(`دواء ${label}:${i.key}`)}))};}
      }
      if(fields['النتيجة.new'])result.outcome=read('النتيجة');else if(current.outcome)result.outcome={...current.outcome,...read('النتيجة')};
    }
    onAccept(result);
  }}/>;
}
