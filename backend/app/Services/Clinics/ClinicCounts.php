<?php

namespace App\Services\Clinics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ClinicCounts
{
    public const PATIENT_DEFINITION = 'عدد المرضى المختلفين في الزيارات المكتملة وغير الملغاة المرتبطة مباشرة بالعيادة؛ تكرار الزيارة لا يزيد العدد.';

    public function eligibleDoctors(): Builder
    {
        return DB::table('staff as s')->join('staff_types as st', 'st.id', '=', 's.staff_type_id')
            ->whereNull('s.archived_at')->where('s.is_active', true)->where('st.is_active', true)
            ->whereIn('st.code', config('clinics.doctor_staff_types', []));
    }

    public function currentDoctors(array $facility): Builder
    {
        $today = $facility['today'] ?? now($facility['timezone'])->toDateString();

        return $this->eligibleDoctors()->join('clinic_staff as cs', 'cs.staff_id', '=', 's.id')
            ->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->where('c.facility_id', $facility['id'])->where('c.is_active', true)->whereNull('c.archived_at')
            ->where('cs.starts_on', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $today));
    }

    public function patients(int $facilityId): Builder
    {
        // A visit belongs to one clinic in today's schema. Do not infer from staff.
        return DB::table('visits')->where('facility_id', $facilityId)
            ->where('status', 'complete')->whereNull('voided_at')->whereColumn('clinic_id', 'clinics.id')
            ->selectRaw('COUNT(DISTINCT patient_id)');
    }
}
