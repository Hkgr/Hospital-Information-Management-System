<?php

namespace App\Services\Directory;

use App\Exceptions\ClinicException;
use App\Services\Clinics\ClinicCounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicStaffLinks
{
    // Every writer locks staff ids ascending BEFORE clinic ids ascending.
    // Never acquire an additional staff lock after any clinic lock.
    public function lockStaff(array $ids): void
    {
        DB::table('staff')->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function lockClinics(array $ids): void
    {
        DB::table('clinics')->whereIn('id', array_unique($ids))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** Both endpoint rows must already be locked by the caller's transaction. */
    public function change(int $clinicId, int $staffId, bool $add, array $facility, ?string $startsOn = null): bool
    {
        $today = $facility['today'];
        if ($add && ! DB::table('clinics')->where('id', $clinicId)->where('facility_id', $facility['id'])->where('is_active', true)->whereNull('archived_at')->exists()) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'الإضافة تحتاج عيادة فعالة غير مؤرشفة في المنشأة المحددة.']);
        }
        if ($add && ! app(ClinicCounts::class)->eligibleDoctors()->where('s.id', $staffId)->exists()) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'اختر أطباء فعالين من الأنواع المعتمدة فقط.']);
        }
        $periods = DB::table('clinic_staff')->where('clinic_id', $clinicId)->where('staff_id', $staffId)->orderBy('starts_on')->lockForUpdate()->get();
        if (! $add) {
            return DB::table('clinic_staff')->where('clinic_id', $clinicId)->where('staff_id', $staffId)
                ->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
                ->update(['ends_on' => $today, 'updated_at' => now()]) > 0;
        }
        $start = $startsOn ?? ($periods->isEmpty() ? ClinicalAssignmentDates::BASELINE : $today);
        // A future zero-length period cancelled by archive reserves no time.
        // Keep its history, but allow a new explicit assignment after restore.
        $overlap = $periods->filter(fn ($p) => $p->ends_on === null || ($p->ends_on > $start && $p->ends_on > $p->starts_on));
        if ($overlap->contains(fn ($p) => $p->starts_on > $start) || $overlap->count() > 1) {
            throw new ClinicException('CLINIC_PERIOD_CONFLICT', 'يوجد ارتباط مجدول أو فترات متداخلة؛ راجع السجل قبل التعديل.');
        }
        if ($overlap->isNotEmpty()) {
            if ($overlap->first()->ends_on !== null && $overlap->first()->ends_on <= $today) {
                throw new ClinicException('CLINIC_PERIOD_CONFLICT', 'التاريخ المختار يقع في ارتباط منتهٍ. اختر بداية جديدة أو صحّح الفترة صراحة من سجل الارتباطات.');
            }
            if ($startsOn && $overlap->first()->starts_on !== $startsOn) {
                throw ValidationException::withMessages(['assignment_starts_on' => 'يوجد ارتباط يغطي التاريخ المختار؛ صحّح بدايته من سجل الارتباطات بدل إضافة فترة متداخلة.']);
            }

            return false;
        }
        $sameDay = $periods->firstWhere('starts_on', $start);
        if ($sameDay) {
            if ($start !== $today) {
                throw new ClinicException('CLINIC_PERIOD_CONFLICT', 'لا تُفتح فترة تاريخية مغلقة تلقائيًا. راجع تاريخ الارتباطات لتصحيحها صراحة.');
            }
            DB::table('clinic_staff')->where('id', $sameDay->id)->update(['ends_on' => null, 'updated_at' => now()]);
        } else {
            DB::table('clinic_staff')->insert(['clinic_id' => $clinicId, 'staff_id' => $staffId, 'starts_on' => $start, 'created_at' => now(), 'updated_at' => now()]);
        }

        return true;
    }
}
