<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use App\Services\Directory\ClinicStaffLinks;
use App\Services\Directory\DirectoryLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClinicWriter
{
    public function __construct(private ClinicCounts $counts, private ClinicAudit $audit, private ClinicStaffLinks $links) {}

    public function save(Request $request, array $facility, array $input, ?int $id): int
    {
        try {
            return DB::transaction(function () use ($request, $facility, $input, $id) {
                $this->links->lockStaff(array_merge($input['doctor_add_ids'] ?? [], $input['doctor_remove_ids'] ?? []));
                $old = $id === null ? null : $this->locked($facility['id'], $id, $input['lock_version']);
                if ($old && $old['archived_at'] !== null) {
                    throw new ClinicException('CLINIC_STATE_CONFLICT', 'استعد السجل المؤرشف قبل تعديله أو إدارة ارتباطاته.');
                }
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
                $this->changeDoctors($request, $id, $facility, $input['doctor_add_ids'] ?? [], $input['doctor_remove_ids'] ?? []);
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
        app(DirectoryLifecycle::class)->apply($request, $facility, false, $id, $version, 'delete');
    }

    public function deactivate(Request $request, array $facility, int $id, int $version): void
    {
        app(DirectoryLifecycle::class)->apply($request, $facility, false, $id, $version, 'deactivate');
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

    private function changeDoctors(Request $request, int $clinicId, array $facility, array $add, array $remove): void
    {
        if (array_intersect($add, $remove)) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'لا يمكن إضافة الطبيب وإزالته في الطلب نفسه.']);
        }
        $eligible = $this->counts->eligibleDoctors()->whereIn('s.id', $add)->pluck('s.id')->all();
        if (count($eligible) !== count($add)) {
            throw ValidationException::withMessages(['doctor_add_ids' => 'اختر أطباء فعالين من الأنواع المعتمدة فقط.']);
        }
        foreach ([false => $remove, true => $add] as $adding => $ids) {
            foreach ($ids as $staffId) {
                if ($this->links->change($clinicId, $staffId, (bool) $adding, $facility)) {
                    DB::table('staff')->where('id', $staffId)->increment('lock_version');
                    $this->audit->record($request, $facility['id'], $staffId, 'clinics_changed', null, ['clinic_id' => $clinicId, 'linked' => (bool) $adding], 'doctor');
                }
            }
        }
    }

    private function periods(int $id): array
    {
        return DB::table('clinic_staff')->where('clinic_id', $id)->orderBy('id')->get(['staff_id', 'starts_on', 'ends_on'])->map(fn ($p) => (array) $p)->all();
    }
}
