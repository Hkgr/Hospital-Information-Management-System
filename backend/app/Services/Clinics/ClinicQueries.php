<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClinicQueries
{
    public function __construct(private ClinicCounts $counts) {}

    public function query(array $facility, array $filters): Builder
    {
        $doctors = $this->counts->currentDoctors($facility)->select('cs.clinic_id')
            ->selectRaw('COUNT(DISTINCT s.id) as doctor_count')->groupBy('cs.clinic_id');
        $query = DB::table('clinics as clinics')->where('clinics.facility_id', $facility['id'])
            ->leftJoin('specialties as specialty', 'specialty.id', '=', 'clinics.specialty_id')
            ->leftJoinSub($doctors, 'dc', 'dc.clinic_id', '=', 'clinics.id')
            ->leftJoinSub($this->counts->patients($facility['id']), 'pc', 'pc.clinic_id', '=', 'clinics.id')
            ->select('clinics.*', 'specialty.name_ar as specialty_name')
            ->selectRaw('COALESCE(dc.doctor_count, 0) as doctor_count, COALESCE(pc.patient_count, 0) as patient_count');
        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $query->where(fn (Builder $q) => $q->whereLike('clinics.code', '%'.$search.'%')
                ->orWhereLike('clinics.name_ar', '%'.$search.'%')->orWhereLike('clinics.description', '%'.$search.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('clinics.is_active', $status === 'active');
        }
        if ($specialty = $filters['specialty_id'] ?? null) {
            $query->where('clinics.specialty_id', $specialty);
        }
        if ($doctor = $filters['doctor_id'] ?? null) {
            $query->whereIn('clinics.id', $this->counts->currentDoctors($facility)->where('s.id', $doctor)->select('cs.clinic_id'));
        }
        $sort = $filters['sort'] ?? 'code';
        $sort = in_array($sort, ['doctor_count', 'patient_count'], true) ? $sort : 'clinics.'.$sort;

        return $query->orderBy($sort, $filters['direction'] ?? 'asc')->orderBy('clinics.id');
    }

    public function list(array $facility, array $filters): array
    {
        $page = $this->query($facility, $filters)->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $this->present($page->getCollection(), $facility), 'meta' => $this->meta($page)];
    }

    public function find(array $facility, int $id): array
    {
        $row = $this->query($facility, [])->where('clinics.id', $id)->first();
        if (! $row) {
            throw new ClinicException('CLINIC_NOT_FOUND', 'العيادة غير موجودة في المنشأة المحددة.', 404);
        }

        return $this->present(collect([$row]), $facility)[0];
    }

    public function present(Collection $rows, array $facility): array
    {
        $doctors = $rows->isEmpty() ? collect() : $this->counts->currentDoctors($facility)
            ->whereIn('cs.clinic_id', $rows->pluck('id'))->select('cs.clinic_id', 's.id', 's.full_name')
            ->distinct()->orderBy('s.full_name')->orderBy('s.id')->get()->groupBy('clinic_id');

        return $rows->map(fn ($row) => [
            'id' => (int) $row->id, 'facility_id' => (int) $row->facility_id,
            'code' => $row->code, 'name_ar' => $row->name_ar, 'description' => $row->description,
            'specialty' => $row->specialty_id ? ['id' => (int) $row->specialty_id, 'name_ar' => $row->specialty_name] : null,
            'is_active' => (bool) $row->is_active, 'lock_version' => (int) $row->lock_version,
            'doctor_count' => (int) $row->doctor_count, 'patient_count' => (int) $row->patient_count,
            'doctors_preview' => ($doctors->get($row->id) ?? collect())->take(3)
                ->map(fn ($doctor) => ['id' => (int) $doctor->id, 'name' => $doctor->full_name])->values()->all(),
            'patient_count_definition' => ClinicCounts::PATIENT_DEFINITION,
        ])->all();
    }

    public function doctors(array $facility, array $filters, ?int $clinicId = null): array
    {
        $ids = $clinicId === null && isset($filters['ids']) ? array_values(array_unique(array_map('intval', $filters['ids']))) : null;
        if ($clinicId !== null) {
            $this->find($facility, $clinicId);
            $query = $this->counts->currentDoctors($facility)->where('cs.clinic_id', $clinicId)
                ->select('s.id', 's.staff_code', 's.full_name')->selectRaw('MIN(cs.starts_on) as starts_on')
                ->groupBy('s.id', 's.staff_code', 's.full_name');
        } else {
            $query = $this->counts->eligibleDoctors()->select('s.id', 's.staff_code', 's.full_name');
        }
        if (($search = $filters['search'] ?? null) !== null && $search !== '') {
            $query->where(fn (Builder $q) => $q->whereLike('s.full_name', '%'.$search.'%')->orWhereLike('s.staff_code', '%'.$search.'%'));
        }
        if ($ids !== null) {
            $query->whereIn('s.id', $ids);
        }
        $page = $query->orderBy('s.full_name')->orderBy('s.id')->paginate($ids !== null ? 100 : ($filters['per_page'] ?? 20), ['*'], 'page', $ids !== null ? 1 : ($filters['page'] ?? 1));
        $linked = [];
        if ($clinicId === null && ! empty($filters['clinic_id'])) {
            $this->find($facility, (int) $filters['clinic_id']);
            $linked = $this->counts->currentDoctors($facility)->where('cs.clinic_id', $filters['clinic_id'])
                ->whereIn('s.id', $page->getCollection()->pluck('id'))->pluck('s.id')->all();
        }
        $specialties = DB::table('staff_specialties as ss')->join('specialties as sp', 'sp.id', '=', 'ss.specialty_id')
            ->whereIn('ss.staff_id', $page->getCollection()->pluck('id'))->where('sp.is_active', true)
            ->orderBy('sp.name_ar')->orderBy('sp.id')->get(['ss.staff_id', 'sp.id', 'sp.name_ar'])->groupBy('staff_id');

        $unavailable = [];
        if ($ids !== null) {
            $missing = array_values(array_diff($ids, $page->getCollection()->pluck('id')->all()));
            $inactive = DB::table('staff as s')->join('staff_types as st', 'st.id', '=', 's.staff_type_id')
                ->whereIn('s.id', $missing)->whereIn('st.code', config('clinics.doctor_staff_types'))
                ->where(fn ($q) => $q->where('s.is_active', false)->orWhere('st.is_active', false))->pluck('s.id')->all();
            $unavailable = array_map(fn ($id) => ['id' => $id, 'reason' => in_array($id, $inactive) ? 'INACTIVE' : 'UNAVAILABLE'], $missing);
        }

        return ['data' => $page->getCollection()->map(fn ($doctor) => [
            'id' => (int) $doctor->id, 'code' => $doctor->staff_code, 'name' => $doctor->full_name,
            'starts_on' => $doctor->starts_on ?? null,
            'is_linked' => $clinicId !== null || in_array($doctor->id, $linked),
            'specialties' => ($specialties->get($doctor->id) ?? collect())->map(fn ($s) => ['id' => (int) $s->id, 'name_ar' => $s->name_ar])->all(),
        ])->all(), 'meta' => $this->meta($page)] + ($clinicId === null ? ['unavailable' => $unavailable] : []);
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
