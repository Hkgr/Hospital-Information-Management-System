<?php

namespace App\Services\Dossiers;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class DossierWizardQueries
{
    public function snapshot(array $f, int $id): array
    {
        return DB::transaction(function () use ($f, $id) {
            $d = app(DossierWrites::class)->dossier($f, $id, false);
            $d['is_oncology'] = (bool) $d['is_oncology'];
            $p = DB::table('patients')->where('id', $d['patient_id'])->first(['id', 'patient_code', 'lock_version', ...SaveBloodProfile::PERSON]);
            $context = app(DossierWorkflowActions::class)->forDossiers($f, [$d])[$id];
            $progress = $context['progress'];
            $visitId = $context['initial_visit']?->id;
            $visit = $visitId ? DB::table('visits')->where('id', $visitId)->where('dossier_id', $id)->where('facility_id', $f['id'])->first(['id', 'visit_no', 'visit_date', 'visit_type_id', 'is_referred', 'referring_hospital', 'referral_date', 'referral_reason', 'lock_version', 'status']) : null;
            $selections = DB::table('dossier_oncology_selections')->where('dossier_id', $id)->where('is_active', true)->orderBy('code')->get();
            if ($visit) {
                $visit->is_referred = (bool) $visit->is_referred;
            }

            return ['id' => $id, 'code' => $d['code'], 'status' => $d['status'], 'lock_version' => $d['lock_version'], 'opening_date' => $d['opening_date'], 'patient' => $p, 'workflow' => $context['workflow'],
                'medical' => Arr::only($d, ['disability_text', 'clinical_history', 'is_oncology', 'previous_examinations', 'medication_source', 'other_organization']) + ['history' => $selections->where('selection_group', 'history')->pluck('code')->values()->all(), 'treatment' => $selections->where('selection_group', 'treatment')->pluck('code')->values()->all()],
                'progress' => collect(['personal', 'medical', 'visit'])->map(fn ($s) => $progress->has($s) ? (array) $progress->get($s) : ['section' => $s, 'state' => 'not_started', 'last_saved_by' => null, 'last_saved_at' => null, 'lock_version' => 0, 'visit_id' => null])->all(),
                'visit' => $visit ? (array) $visit + ['diagnoses' => DB::table('visit_diagnoses as e')->join('diagnoses as n', 'n.id', '=', 'e.diagnosis_id')->leftJoin('clinics as c', 'c.id', '=', 'e.clinic_id')->join('staff as s', 's.id', '=', 'e.diagnosing_staff_id')->where('e.visit_id', $visitId)->where('e.facility_id', $f['id'])->whereNull('e.voided_at')->orderBy('e.id')->get(['e.id', 'e.lock_version', 'e.diagnosis_id', 'e.diagnosed_on', 'e.clinic_id', 'e.diagnosing_staff_id', 'n.name_ar as diagnosis_name', 'c.name_ar as clinic_name', 's.full_name as doctor_name'])->all()] : null];
        });
    }
}
