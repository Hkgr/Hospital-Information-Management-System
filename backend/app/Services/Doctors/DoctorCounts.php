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
            ->where('c.facility_id', $facility['id'])->where('c.is_active', true)
            ->where('cs.starts_on', '<=', $facility['today'])
            ->where(fn ($q) => $q->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $facility['today']));
    }

    public function patients(int $facilityId): Builder
    {
        return DB::table('visits')->where('facility_id', $facilityId)->where('status', 'complete')->whereNull('voided_at')
            ->whereNotNull('attending_staff_id')->select('attending_staff_id')->selectRaw('COUNT(DISTINCT patient_id) as patient_count')->groupBy('attending_staff_id');
    }
}
