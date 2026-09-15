<?php

namespace App\Services\Dossiers;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DossierWizardQueries
{
    public function snapshot(array $f, int $id, ?int $selected = null, bool $newVisit = false): array
    {
        return DB::transaction(function () use ($f, $id, $selected, $newVisit) {
            $d = app(DossierWrites::class)->dossier($f, $id, false);
            $d['is_oncology'] = (bool) $d['is_oncology'];
            $p = DB::table('patients')->where('id', $d['patient_id'])->first(['id', 'patient_code', 'lock_version', ...SaveBloodProfile::PERSON]);
            $context = app(DossierWorkflowActions::class)->forDossiers($f, [$d])[$id];
            $progress = $context['progress'];
            $visitId = $newVisit ? null : ($selected ?? $context['initial_visit']?->id);
            $visit = $visitId ? DB::table('visits')->where('id', $visitId)->where('dossier_id', $id)->where('patient_id', $d['patient_id'])->where('facility_id', $f['id'])->whereNull('voided_at')->first(['id', 'visit_no', 'visit_date', 'visit_type_id', 'dossier_visit_kind', 'clinic_id', 'attending_staff_id', 'is_referred', 'referring_hospital', 'referral_date', 'referral_reason', 'lock_version', 'status']) : null;
            if ($selected) {
                abort_unless($visit, 404);
            }
            if ($selected || $newVisit) {
                $progress = DB::table('dossier_section_progress')->where('dossier_id', $id)->where('facility_id', $f['id'])->where(fn ($q) => $q->whereNull('visit_id')->when($visitId, fn ($q) => $q->orWhere('visit_id', $visitId)))->get()->keyBy('section');
                $editable = $visit && $visit->status === 'draft';
                $caps = $f['capabilities'];
                $action = $visit ? ($editable && $caps['visits_update'] ? 'update' : null) : ($d['status'] === 'active' && $caps['visits_create'] ? 'create' : null);
                $context['workflow']['visit'] = ['id' => $visitId, 'action' => $action, 'label' => $visit ? 'استكمال الزيارة المسودة' : 'إضافة زيارة'];
                $later = $newVisit || $visit?->dossier_visit_kind === 'subsequent';
                $context['workflow']['sections'] = [$later ? false : $context['workflow']['personal_update'], $later ? false : $context['workflow']['medical_update'], $action !== null, $editable && $caps['clinical_update'], $editable && $caps['clinical_update'], $editable && ($caps['visits_update'] || $caps['visits_complete'])];
                $context['workflow']['resume_section'] = 2;
            }
            $selections = DB::table('dossier_oncology_selections')->where('dossier_id', $id)->where('is_active', true)->orderBy('code')->get();
            if ($visit) {
                $visit->is_referred = (bool) $visit->is_referred;
            }

            return ['id' => $id, 'card_id' => (int) $p->id, 'code' => $p->patient_code, 'status' => $d['status'], 'lock_version' => $d['lock_version'], 'opening_date' => $d['opening_date'], 'patient' => $p, 'workflow' => $context['workflow'],
                'medical' => Arr::only($d, ['disability_text', 'clinical_history', 'is_oncology', 'previous_examinations', 'medication_source', 'other_organization']) + ['history' => $selections->where('selection_group', 'history')->pluck('code')->values()->all(), 'treatment' => $selections->where('selection_group', 'treatment')->pluck('code')->values()->all()],
                'clinical' => $visit ? app(DossierVisitSections::class)->read($f, $visit->id) : ['services' => [], 'procedures' => [], 'prescription' => null, 'outcome' => null, 'attachment_count' => 0],
                'progress' => collect(['personal', 'medical', 'visit', 'clinical', 'medications', 'attachments'])->map(fn ($s) => $progress->has($s) ? (array) $progress->get($s) : ['section' => $s, 'state' => 'not_started', 'last_saved_by' => null, 'last_saved_at' => null, 'lock_version' => 0, 'visit_id' => null])->all(),
                'visit' => $visit ? (array) $visit + ['diagnoses' => DB::table('visit_diagnoses as e')->join('diagnoses as n', 'n.id', '=', 'e.diagnosis_id')->leftJoin('clinics as c', 'c.id', '=', 'e.clinic_id')->join('staff as s', 's.id', '=', 'e.diagnosing_staff_id')->where('e.visit_id', $visitId)->where('e.facility_id', $f['id'])->whereNull('e.voided_at')->orderBy('e.id')->get(['e.id', 'e.lock_version', 'e.diagnosis_id', 'e.diagnosed_on', 'e.clinic_id', 'e.diagnosing_staff_id', 'n.name_ar as diagnosis_name', 'c.name_ar as clinic_name', 's.full_name as doctor_name'])->all()] : null];
        });
    }
}
