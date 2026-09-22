<?php

namespace App\Services\Dossiers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DossierPurge
{
    public function destroy(Request $request, array $f, int $id): void
    {
        DB::transaction(function () use ($request, $f, $id) {
            $dossier = app(DossierWrites::class)->dossier($f, $id);
            $patientId = (int) $dossier['patient_id'];
            $visits = DB::table('visits')->where('dossier_id', $id)->where('facility_id', $f['id'])->lockForUpdate()->pluck('id');
            $visitIds = $visits->all();
            if (Schema::hasColumn('patient_dossiers', 'registration_visit_id')) {
                DB::table('patient_dossiers')->where('id', $id)->where('facility_id', $f['id'])->update(['registration_visit_id' => null, 'updated_at' => now()]);
            }
            $this->purgeVisits($f['id'], $id, $visitIds);
            $this->purgeOncology($f['id'], $id);
            if (Schema::hasTable('dossier_import_rows')) {
                DB::table('dossier_import_rows')->where('dossier_id', $id)->where('facility_id', $f['id'])->delete();
            }
            DB::table('dossier_oncology_selections')->where('dossier_id', $id)->where('facility_id', $f['id'])->delete();
            DB::table('dossier_section_progress')->where('dossier_id', $id)->where('facility_id', $f['id'])->delete();
            DB::table('dossier_requests')->where('facility_id', $f['id'])->where('entity_id', $id)->delete();
            if ($visitIds) {
                DB::table('dossier_requests')->where('facility_id', $f['id'])->whereIn('entity_id', $visitIds)->delete();
            }
            if ($visitIds) {
                DB::table('visits')->whereIn('id', $visitIds)->where('dossier_id', $id)->where('facility_id', $f['id'])->delete();
            }
            $this->forgetAudit($f['id'], $id, $visitIds, $patientId);
            DB::table('patient_dossiers')->where('id', $id)->where('facility_id', $f['id'])->delete();
            app(DossierWrites::class)->audit($request, $f, 'patient_dossier', $id, ['code' => $dossier['code'], 'patient_id' => $patientId], null, 'deleted');
            $this->forgetOrphanPatient($patientId);
        });
    }

    private function purgeVisits(int $facility, int $dossier, array $visits): void
    {
        if (! $visits) {
            return;
        }
        $outcomes = DB::table('visit_outcomes')->whereIn('visit_id', $visits)->pluck('id');
        $pathologies = Schema::hasTable('visit_pathologies')
            ? DB::table('visit_pathologies')->whereIn('visit_id', $visits)->where('dossier_id', $dossier)->pluck('id')
            : collect();
        $prescriptions = Schema::hasTable('visit_prescriptions')
            ? DB::table('visit_prescriptions')->whereIn('visit_id', $visits)->pluck('id')
            : collect();
        $sessions = DB::table('dose_sessions')->whereIn('visit_id', $visits)->pluck('id');
        if (Schema::hasTable('pathology_attachments') && $pathologies->isNotEmpty()) {
            DB::table('pathology_attachments')->whereIn('pathology_id', $pathologies)->delete();
        }
        if (Schema::hasTable('visit_diagnostic_assessments')) {
            DB::table('visit_diagnostic_assessments')->whereIn('visit_id', $visits)->where('dossier_id', $dossier)->delete();
        }
        if ($pathologies->isNotEmpty()) {
            DB::table('visit_pathologies')->whereIn('id', $pathologies)->delete();
        }
        if (Schema::hasTable('visit_prescription_items') && $prescriptions->isNotEmpty()) {
            DB::table('visit_prescription_items')->whereIn('prescription_id', $prescriptions)->delete();
        }
        if ($prescriptions->isNotEmpty()) {
            DB::table('visit_prescriptions')->whereIn('id', $prescriptions)->delete();
        }
        if (Schema::hasTable('visit_attachment_uploads')) {
            DB::table('visit_attachment_uploads')->whereIn('visit_id', $visits)->where('dossier_id', $dossier)->delete();
        }
        if (Schema::hasTable('visit_attachments')) {
            DB::table('visit_attachments')->whereIn('visit_id', $visits)->where('dossier_id', $dossier)->delete();
        }
        DB::table('visit_medications')->whereIn('visit_id', $visits)->delete();
        if ($sessions->isNotEmpty()) {
            DB::table('dose_session_items')->whereIn('dose_session_id', $sessions)->delete();
            DB::table('dose_sessions')->whereIn('id', $sessions)->delete();
        }
        if (Schema::hasTable('case_reviews')) {
            if ($outcomes->isNotEmpty()) {
                DB::table('case_reviews')->whereIn('visit_outcome_id', $outcomes)->delete();
            }
            DB::table('case_reviews')->whereIn('followup_visit_id', $visits)->delete();
        }
        if (Schema::hasTable('death_records')) {
            DB::table('death_records')->whereIn('visit_id', $visits)->delete();
        }
        if (Schema::hasTable('admissions')) {
            DB::table('admissions')->whereIn('visit_id', $visits)->delete();
        }
        if (Schema::hasTable('blood_transfusions')) {
            DB::table('blood_transfusions')->whereIn('visit_id', $visits)->delete();
        }
        DB::table('visit_diagnoses')->whereIn('visit_id', $visits)->delete();
        DB::table('visit_services')->whereIn('visit_id', $visits)->delete();
        DB::table('visit_procedures')->whereIn('visit_id', $visits)->delete();
        DB::table('visit_outcomes')->whereIn('visit_id', $visits)->delete();
        DB::table('visit_demographics')->whereIn('visit_id', $visits)->delete();
        DB::table('correction_requests')->whereIn('visit_id', $visits)->delete();
        DB::table('entry_drafts')->whereIn('visit_id', $visits)->delete();
        DB::table('dose_sessions')->whereIn('visit_id', $visits)->delete();
    }

    private function purgeOncology(int $facility, int $dossier): void
    {
        if (! Schema::hasTable('oncology_plans')) {
            return;
        }
        if (Schema::hasTable('oncology_session_doses')) {
            DB::table('oncology_session_doses')->where('dossier_id', $dossier)->where('facility_id', $facility)->delete();
        }
        if (Schema::hasColumn('dose_sessions', 'dossier_id')) {
            DB::table('dose_sessions')->where('dossier_id', $dossier)->where('facility_id', $facility)->delete();
        }
        if (Schema::hasTable('oncology_sessions')) {
            DB::table('oncology_sessions')->where('dossier_id', $dossier)->where('facility_id', $facility)->delete();
        }
        DB::table('oncology_plans')->where('dossier_id', $dossier)->where('facility_id', $facility)->update(['current_revision_id' => null]);
        if (Schema::hasTable('oncology_regimen_items') && Schema::hasTable('oncology_plan_revisions')) {
            $revisions = DB::table('oncology_plan_revisions')->where('dossier_id', $dossier)->where('facility_id', $facility)->pluck('id');
            if ($revisions->isNotEmpty()) {
                DB::table('oncology_regimen_items')->whereIn('revision_id', $revisions)->delete();
            }
        }
        if (Schema::hasTable('oncology_plan_revisions')) {
            DB::table('oncology_plan_revisions')->where('dossier_id', $dossier)->where('facility_id', $facility)->delete();
        }
        DB::table('oncology_plans')->where('dossier_id', $dossier)->where('facility_id', $facility)->delete();
    }

    private function forgetAudit(int $facility, int $dossier, array $visits, int $patient): void
    {
        $types = [
            'patient_dossier' => [$dossier],
            'dossier_medical' => [$dossier],
            'dossier_visit' => $visits,
            'oncology_plans' => DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', 'oncology_plans')->where('entity_id', $dossier)->pluck('entity_id')->all(),
        ];
        foreach ([
            'visit_diagnosis' => 'visit_diagnoses',
            'visit_services' => 'visit_services',
            'visit_procedures' => 'visit_procedures',
            'visit_prescriptions' => 'visit_prescriptions',
            'visit_prescription_items' => 'visit_prescription_items',
            'visit_outcomes' => 'visit_outcomes',
            'dossier_upload' => 'visit_attachment_uploads',
            'visit_attachment' => 'visit_attachments',
            'visit_pathologies' => 'visit_pathologies',
            'visit_diagnostic_assessments' => 'visit_diagnostic_assessments',
            'oncology_plan_revisions' => 'oncology_plan_revisions',
            'oncology_sessions' => 'oncology_sessions',
            'oncology_session_doses' => 'oncology_session_doses',
            'dose_sessions' => 'dose_sessions',
            'dose_session_items' => 'dose_session_items',
            'visit_medications' => 'visit_medications',
        ] as $type => $unused) {
            $types[$type] = [];
        }
        DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', 'patient_dossier')->where('entity_id', $dossier)->delete();
        DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', 'dossier_medical')->where('entity_id', $dossier)->delete();
        if ($visits) {
            DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', 'dossier_visit')->whereIn('entity_id', $visits)->delete();
        }
        foreach ([
            'oncology_plans', 'oncology_plan_revisions', 'oncology_sessions', 'oncology_session_doses',
            'dose_sessions', 'dose_session_items', 'visit_medications', 'visit_pathologies',
            'visit_diagnostic_assessments', 'visit_diagnosis', 'visit_services', 'visit_procedures',
            'visit_prescriptions', 'visit_prescription_items', 'visit_outcomes', 'dossier_upload', 'visit_attachment',
        ] as $type) {
            DB::table('audit_logs')->where('facility_id', $facility)->where('entity_type', $type)
                ->where(function ($q) use ($dossier, $visits) {
                    $q->where('entity_id', $dossier);
                    if ($visits) {
                        $q->orWhereIn('entity_id', $visits);
                    }
                })->delete();
        }
        unset($types, $patient);
    }

    private function forgetOrphanPatient(int $patient): void
    {
        if (DB::table('patient_dossiers')->where('patient_id', $patient)->exists()) {
            return;
        }
        foreach ([
            'visits' => 'patient_id',
            'admissions' => 'patient_id',
            'death_records' => 'patient_id',
            'cancer_cases' => 'patient_id',
            'patient_merges' => 'source_patient_id',
            'blood_donors' => 'patient_id',
            'blood_transfusions' => 'patient_id',
            'blood_recipients' => 'patient_id',
            'blood_bank_people' => 'patient_id',
            'stock_requests' => 'patient_id',
        ] as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $column) && DB::table($table)->where($column, $patient)->exists()) {
                return;
            }
        }
        if (Schema::hasTable('patient_merges') && DB::table('patient_merges')->where('target_patient_id', $patient)->exists()) {
            return;
        }
        foreach (['patient_identifiers', 'patient_disabilities', 'patient_search_tokens'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('patient_id', $patient)->delete();
            }
        }
        DB::table('patients')->where('id', $patient)->delete();
    }
}
