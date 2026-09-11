<?php

namespace App\Services\Directory;

use Illuminate\Support\Facades\DB;

class DirectoryReferences
{
    // All restrictive FKs to staff/clinics. staff_specialties are intrinsic attributes,
    // removed only by a successful hard delete; clinic_staff is preserved history.
    public const DOCTOR = ['users' => ['staff_id'], 'staff_aliases' => ['staff_id'], 'staff_work_days' => ['staff_id'],
        'visits' => ['attending_staff_id', 'resident_staff_id'], 'visit_diagnoses' => ['diagnosing_staff_id'],
        'visit_services' => ['performed_by'], 'visit_procedures' => ['specialist_id', 'nurse_id'],
        'dose_sessions' => ['supervising_staff_id', 'administered_by'], 'visit_medications' => ['prescribing_staff_id'],
        'visit_outcomes' => ['decided_by'], 'case_reviews' => ['decided_by'], 'blood_recipient_procedures' => ['specialist_id'],
        'cancer_case_diagnoses' => ['decided_by'], 'report_metric_catalog_items' => ['staff_id']];

    public const CLINIC = ['visits' => ['clinic_id'], 'staff_work_days' => ['clinic_id'], 'report_metric_catalog_items' => ['clinic_id']];

    public function summary(bool $doctor, int $id, int $facilityId): array
    {
        $links = DB::table('clinic_staff')->where($doctor ? 'staff_id' : 'clinic_id', $id);
        $hasLinks = (clone $links)->exists();
        $other = false;
        foreach ($doctor ? self::DOCTOR : self::CLINIC as $table => $columns) {
            foreach ($columns as $column) {
                if (DB::table($table)->where($column, $id)->exists()) {
                    $other = true;
                    break 2;
                }
            }
        }

        return ['action' => $hasLinks || $other ? 'archive' : 'delete',
            'organizational_links' => $links->whereIn('clinic_id', DB::table('clinics')->where('facility_id', $facilityId)->select('id'))->count(),
            'has_other_references' => $other];
    }
}
