<?php

namespace App\Services\Dossiers;

use Illuminate\Support\Facades\DB;

class DossierWorkflowActions
{
    public static function creation(array $caps): array
    {
        $reason = ! $caps['create'] ? 'تحتاج الإضافة صلاحية إنشاء إضبارة.'
            : (! $caps['patients_search'] && ! $caps['patients_create'] ? 'إنشاء الإضبارة يحتاج أيضًا صلاحية البحث عن مريض أو إنشاء مريض جديد.' : null);

        return ['allowed' => $reason === null, 'reason' => $reason];
    }

    /** Read-only batch; the same initial visit and action rules serve list, detail and wizard. */
    public function forDossiers(array $f, array $dossiers): array
    {
        if (! $dossiers) {
            return [];
        }
        $ids = array_column($dossiers, 'id');
        $progress = DB::table('dossier_section_progress')->where('facility_id', $f['id'])->whereIn('dossier_id', $ids)->get()->groupBy('dossier_id');
        $initial = DB::table('visits as iv')->whereColumn('iv.dossier_id', 'wd.id')->whereColumn('iv.patient_id', 'wd.patient_id')->where('iv.facility_id', $f['id'])
            ->where(fn ($q) => $q->where('iv.dossier_visit_kind', 'initial')->orWhere(fn ($q) => $q->whereNull('iv.dossier_visit_kind')->where('iv.status', 'draft')->whereNull('iv.voided_at')))
            ->orderByRaw("CASE WHEN iv.dossier_visit_kind='initial' THEN 0 ELSE 1 END")->orderBy('iv.visit_date')->orderBy('iv.id')->limit(1)->select('iv.id');
        $initialIds = DB::table('patient_dossiers as wd')
            ->where('wd.facility_id', $f['id'])->whereIn('wd.id', $ids)->select('wd.id')->selectSub($initial, 'initial_visit_id')->get()->pluck('initial_visit_id', 'id');
        $visits = DB::table('visits')->where('facility_id', $f['id'])->whereIn('id', $initialIds->filter()->all())->get(['id', 'status', 'voided_at'])->keyBy('id');
        $caps = $f['capabilities'];
        $result = [];
        foreach ($dossiers as $dossier) {
            $d = (array) $dossier;
            $visit = $visits->get($initialIds->get($d['id']));
            $sections = $progress->get($d['id'], collect())->filter(fn ($p) => $p->visit_id === null || $p->visit_id == $visit?->id)->keyBy('section');
            $visitAction = $visit ? ($visit->status === 'draft' && ! $visit->voided_at && $caps['visits_update'] ? 'update' : null)
                : ($d['status'] === 'draft' && $caps['visits_create'] ? 'create' : null);
            $editable = $visit && $visit->status === 'draft' && ! $visit->voided_at;
            $allowed = [$caps['personal_update'] && $caps['patients_update'], $caps['medical_update'], $visitAction !== null, $editable && $caps['clinical_update'], $editable && $caps['clinical_update'], $editable && ($caps['visits_update'] || $caps['visits_complete'])];
            $resume = null;
            foreach ([false, true] as $includeSaved) {
                foreach (['personal', 'medical', 'visit', 'clinical', 'medications', 'attachments'] as $index => $section) {
                    if ($allowed[$index] && ($d['status'] === 'draft' || $index < 2) && ($includeSaved || ($sections->get($section)?->state ?? 'not_started') !== 'saved')) {
                        $resume = $index;
                        break 2;
                    }
                }
            }
            $label = $visitAction === 'create' ? 'تسجيل الزيارة الأولية' : ($visitAction === 'update' ? (($sections->get('visit')?->state ?? 'not_started') === 'saved' ? 'تعديل الزيارة الأولية المسودة' : 'استكمال الزيارة الأولية المسودة') : null);
            $result[$d['id']] = ['progress' => $sections, 'initial_visit' => $visit, 'workflow' => ['personal_update' => $allowed[0], 'medical_update' => $allowed[1], 'resume_section' => $resume, 'sections' => $allowed, 'subsequent_create' => $d['status'] === 'active' && $caps['visits_create'], 'visit' => ['id' => $visit ? (int) $visit->id : null, 'action' => $visitAction, 'label' => $label]]];
        }

        return $result;
    }
}
