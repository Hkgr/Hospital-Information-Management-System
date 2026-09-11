<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicWriter
{
    public function __construct(private ClinicCounts $counts, private ClinicAudit $audit) {}

    public function save(Request $request, array $facility, array $input, ?int $id): int
    {
        try {
            return DB::transaction(function () use ($request, $facility, $input, $id) {
                $old = $id === null ? null : $this->locked($facility['id'], $id, $input['lock_version']);
                $fields = Arr::only($input, ['code', 'name_ar', 'description', 'specialty_id', 'is_active']);
                if (! empty($fields['specialty_id']) && $fields['specialty_id'] != ($old['specialty_id'] ?? null) && ! DB::table('specialties')->where('id', $fields['specialty_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['specialty_id' => 'اختر تخصصًا فعالًا.']);
                }
                if (DB::table('clinics')->where('facility_id', $facility['id'])->where('code', $fields['code'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                    throw ValidationException::withMessages(['code' => 'كود العيادة مستخدم في هذه المنشأة.']);
                }
                $fields['updated_at'] = now();
                if ($id === null) {
                    $id = DB::table('clinics')->insertGetId($fields + ['facility_id' => $facility['id'], 'created_at' => now(), 'lock_version' => 1]);
                } else {
                    DB::table('clinics')->where('id', $id)->update($fields + ['lock_version' => $old['lock_version'] + 1]);
                }
                $beforePeriods = $this->periods($id);
                $this->changeDoctors($id, $facility, $input['doctor_add_ids'] ?? [], $input['doctor_remove_ids'] ?? []);
                $new = (array) DB::table('clinics')->find($id);
                $this->audit->record($request, $facility['id'], $id, $old ? 'updated' : 'created', $old, $new);
                $afterPeriods = $this->periods($id);
                if ($beforePeriods !== $afterPeriods) {
                    $this->audit->record($request, $facility['id'], $id, 'doctors_changed', ['periods' => $beforePeriods], ['periods' => $afterPeriods]);
                }
                if ($old && $old['is_active'] && ! $new['is_active']) {
                    $this->audit->record($request, $facility['id'], $id, 'deactivated', ['is_active' => true], ['is_active' => false]);
                }

                return $id;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'كود العيادة مستخدم في هذه المنشأة.']);
            }
            throw $e;
        }
    }

    public function delete(Request $request, array $facility, int $id, int $version): void
    {
        try {
            DB::transaction(function () use ($request, $facility, $id, $version) {
                $old = $this->locked($facility['id'], $id, $version);
                foreach (['clinic_staff', 'visits', 'staff_work_days'] as $table) {
                    if (DB::table($table)->where('clinic_id', $id)->exists()) {
                        $this->referenced();
                    }
                }
                DB::table('clinics')->where('id', $id)->delete();
                $this->audit->record($request, $facility['id'], $id, 'deleted', $old, null);
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                $this->referenced();
            }
            throw $e;
        }
    }

    public function deactivate(Request $request, array $facility, int $id, int $version): void
    {
        DB::transaction(function () use ($request, $facility, $id, $version) {
            $old = $this->locked($facility['id'], $id, $version);
            DB::table('clinics')->where('id', $id)->update(['is_active' => false, 'lock_version' => $version + 1, 'updated_at' => now()]);
            $this->audit->record($request, $facility['id'], $id, 'deactivated', $old, (array) DB::table('clinics')->find($id));
        }, 3);
    }

    private function locked(int $facilityId, int $id, int $version): array
    {
        $clinic = DB::table('clinics')->where('facility_id', $facilityId)->where('id', $id)->lockForUpdate()->first();
        if (! $clinic) {
            throw new ClinicException('CLINIC_NOT_FOUND', 'العيادة غير موجودة في المنشأة المحددة.', 404);
        }
        if ((int) $clinic->lock_version !== $version) {
            throw new ClinicException('CLINIC_VERSION_CONFLICT', 'عدّل مستخدم آخر هذه العيادة. أعد تحميل بياناتها قبل الحفظ.');
        }

        return (array) $clinic;
    }

    private function changeDoctors(int $clinicId, array $facility, array $add, array $remove): void
    {
        if (array_intersect($add, $remove)) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'لا يمكن إضافة الطبيب وإزالته في الطلب نفسه.']);
        }
        $eligible = $this->counts->eligibleDoctors()->whereIn('s.id', $add)->lockForUpdate()->pluck('s.id')->all();
        if (count($eligible) !== count($add)) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'اختر أطباء فعالين من الأنواع المعتمدة فقط.']);
        }
        $today = $facility['today'] ?? now($facility['timezone'])->toDateString();
        foreach ($remove as $staffId) {
            // Explicit removals only. Hidden/inactive/paginated associations survive unrelated edits.
            DB::table('clinic_staff')->where('clinic_id', $clinicId)->where('staff_id', $staffId)
                ->where('starts_on', '<=', $today)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>', $today))
                ->update(['ends_on' => $today, 'updated_at' => now()]);
        }
        foreach ($add as $staffId) {
            $periods = DB::table('clinic_staff')->where('clinic_id', $clinicId)->where('staff_id', $staffId)->lockForUpdate()->get();
            $overlapping = $periods->filter(fn ($p) => $p->ends_on === null || $p->ends_on > $today);
            if ($overlapping->contains(fn ($p) => $p->starts_on > $today) || $overlapping->count() > 1) {
                throw new ClinicException('CLINIC_PERIOD_CONFLICT', 'يوجد ارتباط مجدول أو فترات متداخلة لهذا الطبيب؛ راجع السجل قبل التعديل.');
            }
            if ($overlapping->isNotEmpty()) {
                continue; // Idempotent addition of an already-current doctor.
            }
            $sameDay = $periods->firstWhere('starts_on', $today);
            if ($sameDay) {
                // Reopen today's interval; every transition remains in audit_logs.
                DB::table('clinic_staff')->where('id', $sameDay->id)->update(['ends_on' => null, 'updated_at' => now()]);
            } else {
                DB::table('clinic_staff')->insert(['clinic_id' => $clinicId, 'staff_id' => $staffId, 'starts_on' => $today, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function periods(int $id): array
    {
        return DB::table('clinic_staff')->where('clinic_id', $id)->orderBy('id')->get(['staff_id', 'starts_on', 'ends_on'])->map(fn ($p) => (array) $p)->all();
    }

    private function referenced(): never
    {
        throw new ClinicException('CLINIC_REFERENCED', 'لا يمكن حذف عيادة مرتبطة بسجلات أو بتاريخ أطباء. يمكنك تعطيلها بإجراء منفصل.');
    }
}
