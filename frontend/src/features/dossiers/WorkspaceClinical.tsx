"use client";

import { useClinicRequest } from "../clinics/api";
import type { Snapshot } from "./wizard";
import type { Dossier, Visit } from "./api";
import { dispositionLabels } from "./pathology";
import OncologyPanel from "./OncologyPanel";
import PathologyPanel from "./PathologyPanel";
import layout from "./wizard.module.css";

export default function WorkspaceClinical({ facility, snapshot, caps, active, visited, revision, onChanged, medicationView }: {
  facility: number; snapshot: Snapshot; caps: Record<string, boolean>; active: number; visited: number[]; revision: number; onChanged: () => void; medicationView?: "unlinked"|"outside";
}) {
  const visit = useClinicRequest<Visit>(snapshot.visit ? `dossiers/${snapshot.id}/visits/${snapshot.visit.id}?facility_id=${facility}` : null, false, true, revision);
  const patient = useClinicRequest<Dossier>(active === 6 || visited.includes(6) ? `dossiers/${snapshot.id}?facility_id=${facility}` : null, false, true, revision);
  if (visit.loading && !visit.data) return active > 5 ? <p role="status">جارٍ تحميل الوقائع المحفوظة لهذه الزيارة…</p> : null;
  if (visit.error && !visit.data) return active > 5 ? <p role="alert">{visit.error}<button onClick={visit.retry}>إعادة المحاولة</button></p> : null;
  if (!visit.data) return null;
  if (active > 6 && !caps.treatment_view) return <p role="alert">لا تتوفر صلاحية استعراض العلاج في هذا المشفى.</p>;
  return <>{[6, 7, 8].filter(i => visited.includes(i) || active === i).map(i => <section key={i} hidden={active !== i} className={layout.toolPanel}>
    <p className={layout.toolNotice}>كل إجراء يُحفظ بتأكيد مستقل. الموعد والوصفة لا يسجلان إعطاءً أو صرفًا تلقائيًا.</p>
    {i === 6 && <div><h3>حالة التشريح المرضي الحالية للمريض</h3>{patient.error ? <p role="alert">{patient.error}<button onClick={patient.retry}>إعادة المحاولة</button></p> : patient.loading ? <p role="status">جارٍ تحديث الحالة…</p> : <p>{dispositionLabels[patient.data?.pathology_summary?.disposition ?? "not_assessed"]}</p>}<small>ملخص أحدث واقعة محفوظة في هذا المشفى؛ التقييم أدناه يخص الزيارة المحددة.</small></div>}
    {i === 6 ? <PathologyPanel facility={facility} dossier={snapshot.id} visit={visit.data} caps={caps} revision={revision} onChanged={onChanged} /> : <OncologyPanel medicationView={i===8?medicationView:undefined} facility={facility} dossier={snapshot.id} caps={caps} revision={revision} onChanged={onChanged} {...i === 8 ? { visit: visit.data } : {}} />}
  </section>)}</>;
}
