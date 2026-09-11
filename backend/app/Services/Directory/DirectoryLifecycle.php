<?php

namespace App\Services\Directory;

use App\Exceptions\ClinicException;
use App\Exceptions\DoctorException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Doctors\DoctorAccess;
use App\Services\Doctors\DoctorCounts;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectoryLifecycle
{
    public function __construct(private ClinicStaffLinks $links, private DirectoryReferences $references, private ClinicAudit $audit) {}

    public function preview(Request $request, array $facility, bool $doctor, int $id): array
    {
        $this->authorize($request, $doctor, 'delete');
        $row = $this->row($facility, $doctor, $id);

        return $this->references->summary($doctor, $id, $facility['id']) + ['lock_version' => (int) $row->lock_version, 'archived' => $row->archived_at !== null];
    }

    public function apply(Request $request, array $facility, bool $doctor, int $id, int $version, string $action): void
    {
        $this->authorize($request, $doctor, in_array($action, ['delete', 'archive']) ? 'delete' : 'update');
        try {
            DB::transaction(function () use ($request, $facility, $doctor, $id, $version, $action) {
                if (! $doctor) {
                    $this->row($facility, false, $id);
                }
                // Clinic archive must discover staff BEFORE locking the clinic. A new
                // membership after discovery is a conflict, never a reversed staff lock.
                $staff = ! $doctor && $action === 'archive' ? DB::table('clinic_staff')->where('clinic_id', $id)->distinct()->pluck('staff_id')->all() : [];
                $this->links->lockStaff($doctor ? [$id] : $staff);
                $row = $this->row($facility, $doctor, $id, true);
                if ((int) $row->lock_version !== $version) {
                    $this->fail($doctor, 'VERSION_CONFLICT', 'تغيّرت البيانات أو الارتباطات. أعد تحميل السجل قبل تأكيد العملية.');
                }
                $table = $doctor ? 'staff' : 'clinics';
                $target = DB::table($table)->where('id', $id);
                if ($action === 'delete') {
                    if ($this->references->summary($doctor, $id, $facility['id'])['action'] !== 'delete') {
                        $this->fail($doctor, 'REFERENCED', 'للسجل ارتباطات أو تاريخ محفوظ. استخدم أرشفة وإزالة من الدليل؛ التعطيل إجراء منفصل.');
                    }
                    if ($doctor) {
                        DB::table('staff_specialties')->where('staff_id', $id)->delete();
                    }
                    $target->delete();
                    $this->audit->record($request, $facility['id'], $id, 'deleted', (array) $row, null, $doctor ? 'doctor' : 'clinic');

                    return;
                }
                $archived = $row->archived_at !== null;
                $valid = match ($action) {
                    'archive' => ! $archived,
                    'restore' => $archived,
                    'deactivate' => ! $archived && (bool) $row->is_active,
                    'reactivate' => ! $archived && ! $row->is_active,
                    default => false,
                };
                if (! $valid) {
                    $this->fail($doctor, 'STATE_CONFLICT', 'لا تناسب العملية حالة السجل الحالية. أعد تحميله؛ استعادة المؤرشف تسبق إعادة تفعيله.');
                }
                if ($doctor && $action === 'reactivate') {
                    $this->requireActiveType((int) $row->staff_type_id);
                }
                if ($action === 'archive') {
                    if (! $doctor && array_diff(DB::table('clinic_staff')->where('clinic_id', $id)->lockForUpdate()->pluck('staff_id')->all(), $staff)) {
                        $this->fail(false, 'VERSION_CONFLICT', 'تغيّرت الارتباطات. أعد تحميل السجل قبل الأرشفة.');
                    }
                    $this->closePeriods($request, $doctor, $id);
                }
                $fields = ['is_active' => $action === 'reactivate', 'lock_version' => $version + 1, 'updated_at' => now()];
                if (in_array($action, ['archive', 'restore'])) {
                    $fields['archived_at'] = $action === 'archive' ? now() : null;
                }
                $target->update($fields);
                $event = ['archive' => 'archived', 'restore' => 'restored', 'reactivate' => 'reactivated', 'deactivate' => 'deactivated'][$action];
                $this->audit->record($request, $facility['id'], $id, $event, (array) $row, (array) $target->first(), $doctor ? 'doctor' : 'clinic');
            }, 3);
        } catch (QueryException $e) {
            if ($action === 'delete' && ($e->errorInfo[1] ?? null) === 1451) {
                $this->fail($doctor, 'REFERENCED', 'ظهر مرجع يمنع الحذف النهائي. أعد معاينة الأثر واختر الأرشفة لحفظ التاريخ.');
            }
            throw $e;
        }
    }

    public function requireActiveType(int $id): void
    {
        if (! DB::table('staff_types')->where('id', $id)->where('is_active', true)->whereIn('code', config('clinics.doctor_staff_types', []))->exists()) {
            throw ValidationException::withMessages(['staff_type_id' => 'يتعذر التفعيل: نوع الطبيب غير فعال أو غير معتمد. راجع مسؤول النظام.']);
        }
    }

    public function history(array $facility, bool $doctor, int $id, array $filters): array
    {
        $this->row($facility, $doctor, $id);
        $query = DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')
            ->join('staff as s', 's.id', '=', 'cs.staff_id')->where('c.facility_id', $facility['id'])
            ->where($doctor ? 'cs.staff_id' : 'cs.clinic_id', $id)
            ->select('cs.id', 'cs.starts_on', 'cs.ends_on', $doctor ? 'c.code' : 's.staff_code as code', $doctor ? 'c.name_ar as name' : 's.full_name as name');
        $page = $query->orderByDesc('cs.starts_on')->orderByDesc('cs.id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $page->items(), 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]];
    }

    private function authorize(Request $request, bool $doctor, string $action): void
    {
        if ($doctor) {
            app(DoctorAccess::class)->directory($request->user(), $action);
        }
        // Clinics are scoped and authorized by ClinicController before entering.
    }

    private function row(array $facility, bool $doctor, int $id, bool $lock = false): object
    {
        $query = DB::table($doctor ? 'staff' : 'clinics')->where('id', $id);
        if (! $doctor) {
            $query->where('facility_id', $facility['id']);
        }
        $row = $query->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $row || ($doctor && ! app(DoctorCounts::class)->directory()->where('s.id', $id)->exists())) {
            $this->fail($doctor, 'NOT_FOUND', 'السجل غير موجود في الدليل المتاح.', 404);
        }

        return $row;
    }

    private function closePeriods(Request $request, bool $doctor, int $id): void
    {
        $query = DB::table('clinic_staff')->where($doctor ? 'staff_id' : 'clinic_id', $id);
        $clinicIds = (clone $query)->distinct()->pluck('clinic_id')->all();
        if ($doctor) {
            $this->links->lockClinics($clinicIds);
        }
        $clinics = DB::table('clinics as c')->join('facilities as f', 'f.id', '=', 'c.facility_id')->whereIn('c.id', $clinicIds)->get(['c.id', 'c.facility_id', 'f.timezone'])->keyBy('id');
        $changed = [];
        foreach ($query->orderBy('id')->lockForUpdate()->get() as $period) {
            $clinic = $clinics[$period->clinic_id];
            $today = now($clinic->timezone)->toDateString();
            if ($period->ends_on !== null && $period->ends_on <= $today) {
                continue;
            }
            // Future bookings become zero-length cancelled periods. No row is
            // deleted, and restore/reactivate never reopens them implicitly.
            $end = max($today, $period->starts_on);
            if ($period->ends_on === $end) {
                continue;
            }
            DB::table('clinic_staff')->where('id', $period->id)->update(['ends_on' => $end, 'updated_at' => now()]);
            $otherId = $doctor ? $period->clinic_id : $period->staff_id;
            $changed[$otherId] = true;
            foreach ([false, true] as $auditDoctor) {
                $this->audit->record($request, $clinic->facility_id, $auditDoctor ? $period->staff_id : $period->clinic_id, $auditDoctor ? 'clinics_changed' : 'doctors_changed', (array) $period, array_replace((array) $period, ['ends_on' => $end, 'reason' => 'archive']), $auditDoctor ? 'doctor' : 'clinic');
            }
        }
        if ($changed) {
            DB::table($doctor ? 'clinics' : 'staff')->whereIn('id', array_keys($changed))->increment('lock_version', 1, ['updated_at' => now()]);
        }
    }

    private function fail(bool $doctor, string $code, string $message, int $status = 409): never
    {
        throw $doctor ? new DoctorException('DOCTOR_'.$code, $message, $status) : new ClinicException('CLINIC_'.$code, $message, $status);
    }
}
