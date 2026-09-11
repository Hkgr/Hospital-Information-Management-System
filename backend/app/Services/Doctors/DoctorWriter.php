<?php

namespace App\Services\Doctors;

use App\Exceptions\DoctorException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\ClinicStaffLinks;
use App\Services\Directory\DirectoryLifecycle;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DoctorWriter
{
    public function __construct(private DoctorCounts $counts, private DoctorAccess $access, private ClinicStaffLinks $links, private ClinicAudit $audit) {}

    public function save(Request $request, array $facility, array $input, ?int $id, bool $linksOnly = false): int
    {
        if (! $linksOnly) {
            $this->access->directory($request->user(), $id === null ? 'create' : 'update');
        }
        $add = $input['clinic_add_ids'] ?? [];
        $remove = $input['clinic_remove_ids'] ?? [];
        if ($linksOnly || $add || $remove) {
            $this->access->facility($request->user(), $facility['id'], 'link');
        }
        if (array_intersect($add, $remove)) {
            throw ValidationException::withMessages(['clinic_add_ids' => 'لا يمكن إضافة العيادة وإزالتها في الطلب نفسه.']);
        }
        try {
            return DB::transaction(function () use ($request, $facility, $input, $id, $linksOnly, $add, $remove) {
                // Both writers acquire staff, then clinics, then period rows. Never reverse this order.
                $old = $id === null ? null : $this->locked($id, $input['lock_version']);
                if ($old && $old['archived_at'] !== null) {
                    throw new DoctorException('DOCTOR_STATE_CONFLICT', 'استعد السجل المؤرشف قبل تعديله أو إدارة ارتباطاته.');
                }
                if (! $linksOnly) {
                    $this->validateFields($input, $old);
                    $fields = Arr::only($input, ['description', 'staff_type_id', 'license_no', 'phone', 'is_active']);
                    $fields += ['staff_code' => $input['code'], 'full_name' => $input['name'], 'search_name' => $input['name'], 'updated_at' => now()];
                    if ($id === null) {
                        $id = DB::table('staff')->insertGetId($fields + ['lock_version' => 1, 'created_at' => now()]);
                    } else {
                        DB::table('staff')->where('id', $id)->update($fields);
                    }
                    $previous = DB::table('staff_specialties')->where('staff_id', $id)->pluck('specialty_id')->all();
                    DB::table('staff_specialties')->where('staff_id', $id)->whereNotIn('specialty_id', $input['specialty_ids'])->delete();
                    foreach (array_diff($input['specialty_ids'], $previous) as $specialty) {
                        DB::table('staff_specialties')->insert(['staff_id' => $id, 'specialty_id' => $specialty]);
                    }
                }
                $this->links->lockClinics(array_merge($add, $remove));
                $allowed = DB::table('clinics')->where('facility_id', $facility['id'])->whereIn('id', array_merge($add, $remove))->pluck('id')->all();
                if (count($allowed) !== count(array_unique(array_merge($add, $remove)))) {
                    throw ValidationException::withMessages(['clinic_add_ids' => 'اختر عيادات من المنشأة المحددة فقط.']);
                }
                if (DB::table('clinics')->whereIn('id', $add)->where('is_active', false)->exists() || ($add && ! ($input['is_active'] ?? $old['is_active'] ?? false))) {
                    throw ValidationException::withMessages(['clinic_add_ids' => 'الإضافة تحتاج طبيبًا وعيادات فعالة.']);
                }
                foreach ([false => $remove, true => $add] as $adding => $ids) {
                    sort($ids, SORT_NUMERIC);
                    foreach ($ids as $clinicId) {
                        if ($this->links->change($clinicId, $id, (bool) $adding, $facility)) {
                            DB::table('clinics')->where('id', $clinicId)->increment('lock_version');
                            $this->audit->record($request, $facility['id'], $clinicId, 'doctors_changed', null, ['staff_id' => $id, 'linked' => (bool) $adding]);
                            $this->audit->record($request, $facility['id'], $id, 'clinics_changed', null, ['clinic_id' => $clinicId, 'linked' => (bool) $adding], 'doctor');
                        }
                    }
                }
                if ($old) {
                    DB::table('staff')->where('id', $id)->update(['lock_version' => $old['lock_version'] + 1, 'updated_at' => now()]);
                }
                $new = (array) DB::table('staff')->find($id);
                if (! $linksOnly) {
                    $this->audit->record($request, $facility['id'], $id, $old ? 'updated' : 'created', $old, $new + ['specialty_ids' => $input['specialty_ids']], 'doctor');
                }

                return $id;
            }, 3);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw ValidationException::withMessages(['code' => 'كود الطبيب مستخدم في الدليل المشترك.']);
            }
            throw $e;
        }
    }

    private function validateFields(array $input, ?array $old): void
    {
        if (($input['is_active'] ?? false) && ! ($old['is_active'] ?? false)) {
            app(DirectoryLifecycle::class)->requireActiveType((int) $input['staff_type_id']);
        }
        $type = DB::table('staff_types')->whereIn('code', config('clinics.doctor_staff_types'))->where('id', $input['staff_type_id'])->first();
        if (! $type || (! $type->is_active && $input['staff_type_id'] != ($old['staff_type_id'] ?? null))) {
            throw ValidationException::withMessages(['staff_type_id' => 'اختر نوع طبيب فعالًا من الأنواع المعتمدة.']);
        }
        if (DB::table('staff')->where('staff_code', $input['code'])->when($old, fn ($q) => $q->where('id', '!=', $old['id']))->exists()) {
            throw ValidationException::withMessages(['code' => 'كود الطبيب مستخدم في الدليل المشترك.']);
        }
        $existing = $old ? DB::table('staff_specialties')->where('staff_id', $old['id'])->pluck('specialty_id')->all() : [];
        $valid = DB::table('specialties')->whereIn('id', $input['specialty_ids'])->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $existing))->count();
        if ($valid !== count($input['specialty_ids']) || (! $input['specialty_ids'] && DB::table('specialties')->where('is_active', true)->exists())) {
            throw ValidationException::withMessages(['specialty_ids' => 'اختر تخصصًا واحدًا أو أكثر من التخصصات المتاحة.']);
        }
    }

    private function locked(int $id, int $version): array
    {
        // Lock only staff first; joining staff_types here would add an unnecessary shared lock dependency.
        $row = DB::table('staff')->where('id', $id)->lockForUpdate()->first();
        if (! $row || ! $this->counts->directory()->where('s.id', $id)->exists()) {
            throw new DoctorException('DOCTOR_NOT_FOUND', 'الطبيب غير موجود في الدليل المتاح.', 404);
        }
        if ((int) $row->lock_version !== $version) {
            throw new DoctorException('DOCTOR_VERSION_CONFLICT', 'تغيّرت بيانات الطبيب أو ارتباطاته. اجلب أحدث نسخة وراجع مسودتك قبل الحفظ.');
        }

        return (array) $row;
    }

    public function deactivate(Request $request, array $facility, int $id, int $version): void
    {
        app(DirectoryLifecycle::class)->apply($request, $facility, true, $id, $version, 'deactivate');
    }

    public function delete(Request $request, array $facility, int $id, int $version): void
    {
        app(DirectoryLifecycle::class)->apply($request, $facility, true, $id, $version, 'delete');
    }
}
