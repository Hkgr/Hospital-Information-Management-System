<?php

namespace App\Services\Doctors;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DoctorCounts
{
    public const PATIENT_DEFINITION = 'عدد المرضى المختلفين في الزيارات المكتملة وغير الملغاة التي يكون الطبيب فيها الطبيب المعالج داخل المنشأة؛ لا يجمع مرضى عياداته أو الوصفات والإجراءات.';

    public function directory(): Builder
    {
        return DB::table('staff as s')->join('staff_types as st', 'st.id', '=', 's.staff_type_id')
            ->whereIn('st.code', config('clinics.doctor_staff_types', []));
    }

    public function currentClinics(array $facility): Builder
    {
        return DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')
            ->join('staff as linked_staff', 'linked_staff.id', '=', 'cs.staff_id')
            ->join('staff_types as linked_type', 'linked_type.id', '=', 'linked_staff.staff_type_id')
            ->where('linked_staff.is_active', true)->whereNull('linked_staff.archived_at')->where('linked_type.is_active', true)
            ->whereIn('linked_type.code', config('clinics.doctor_staff_types', []))
            ->whereNull('c.archived_at')->where('c.facility_id', $facility['id'])->where('c.is_active', true)
            ->where('cs.starts_on', '<=', $facility['today'])
            ->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $facility['today']));
    }

    public function patients(int $facilityId): Builder
    {
        return DB::table('visits')->where('facility_id', $facilityId)->where('status', 'complete')->whereNull('voided_at')
            ->whereColumn('attending_staff_id', 's.id')->selectRaw('COUNT(DISTINCT patient_id)');
    }

    public function patientVisits(int $facilityId): Builder
    {
        return DB::table('visits as v')->join('patients as p', 'p.id', '=', 'v.patient_id')
            ->where('v.facility_id', $facilityId)->where('v.status', 'complete')->whereNull('v.voided_at')->whereNotNull('v.attending_staff_id');
    }

    public function summarize(Builder $query): Builder
    {
        return $query->groupBy('v.attending_staff_id', 'p.id', 'p.patient_code', 'p.first_name', 'p.family_name')
            ->select('v.attending_staff_id as owner_id', 'p.id as patient_id', 'p.patient_code', 'p.first_name', 'p.family_name')
            ->selectRaw('COUNT(*) as visit_count')->selectRaw('MAX(v.visit_date) as last_on')
            ->orderBy('p.patient_code')->orderBy('p.id');
    }

    public function roster(array $facility, array $staffIds): Builder
    {
        return $this->summarize($this->patientVisits($facility['id'])->whereIn('v.attending_staff_id', $staffIds));
    }
}
