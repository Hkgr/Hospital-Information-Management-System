<?php

namespace App\Services\Doctors;

use App\Exceptions\DoctorException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DoctorQueries
{
    public function __construct(private DoctorCounts $counts) {}

    public function query(array $facility, array $filters): Builder
    {
        $clinics = $this->counts->currentClinics($facility)->select('cs.staff_id')->selectRaw('COUNT(DISTINCT c.id) as clinic_count')->groupBy('cs.staff_id');
        $query = $this->counts->directory()->leftJoinSub($clinics, 'cc', 'cc.staff_id', '=', 's.id')
            ->leftJoinSub($this->counts->patients($facility['id']), 'pc', 'pc.attending_staff_id', '=', 's.id')
            ->select('s.*', 'st.code as type_code', 'st.name_ar as type_name')
            ->selectRaw('COALESCE(cc.clinic_count, 0) as clinic_count, COALESCE(pc.patient_count, 0) as patient_count');
        if (($search = $filters['search'] ?? '') !== '' && $search !== null) {
            $query->where(fn ($q) => $q->whereLike('s.staff_code', '%'.$search.'%')->orWhereLike('s.full_name', '%'.$search.'%')->orWhereLike('s.description', '%'.$search.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('s.is_active', $status === 'active');
        }
        if ($specialty = $filters['specialty_id'] ?? null) {
            $query->whereIn('s.id', DB::table('staff_specialties')->where('specialty_id', $specialty)->select('staff_id'));
        }
        if ($clinic = $filters['clinic_id'] ?? null) {
            $query->whereIn('s.id', $this->counts->currentClinics($facility)->where('c.id', $clinic)->select('cs.staff_id'));
        }
        $sort = $filters['sort'] ?? 'code';
        $column = ['code' => 's.staff_code', 'name' => 's.full_name', 'is_active' => 's.is_active', 'clinic_count' => 'clinic_count', 'patient_count' => 'patient_count'][$sort];

        return $query->orderBy($column, $filters['direction'] ?? 'asc')->orderBy('s.id');
    }

    public function list(array $facility, array $filters): array
    {
        $page = $this->query($facility, $filters)->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['data' => $this->present($page->getCollection(), $facility), 'meta' => $this->meta($page)];
    }

    public function find(array $facility, int $id): array
    {
        $row = $this->query($facility, [])->where('s.id', $id)->first();
        if (! $row) {
            throw new DoctorException('DOCTOR_NOT_FOUND', 'الطبيب غير موجود في الدليل المتاح.', 404);
        }

        return $this->present(collect([$row]), $facility)[0];
    }

    public function present(Collection $rows, array $facility): array
    {
        $ids = $rows->pluck('id');
        $specialties = DB::table('staff_specialties as ss')->join('specialties as sp', 'sp.id', '=', 'ss.specialty_id')
            ->whereIn('ss.staff_id', $ids)->orderBy('sp.name_ar')->orderBy('sp.id')->get(['ss.staff_id', 'sp.id', 'sp.name_ar', 'sp.is_active'])->groupBy('staff_id');
        $clinics = $this->counts->currentClinics($facility)->whereIn('cs.staff_id', $ids)->select('cs.staff_id', 'c.id', 'c.code', 'c.name_ar')->distinct()->orderBy('c.code')->get()->groupBy('staff_id');

        return $rows->map(fn ($s) => [
            'id' => (int) $s->id, 'code' => $s->staff_code, 'name' => $s->full_name, 'description' => $s->description,
            'staff_type' => ['id' => (int) $s->staff_type_id, 'code' => $s->type_code, 'name_ar' => $s->type_name],
            'specialties' => ($specialties->get($s->id) ?? collect())->map(fn ($sp) => ['id' => (int) $sp->id, 'name_ar' => $sp->name_ar, 'is_active' => (bool) $sp->is_active])->values()->all(),
            'license_no' => $s->license_no, 'phone' => $s->phone, 'is_active' => (bool) $s->is_active, 'lock_version' => (int) $s->lock_version,
            'clinic_count' => (int) $s->clinic_count, 'patient_count' => (int) $s->patient_count,
            'clinics_preview' => ($clinics->get($s->id) ?? collect())->take(3)->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name_ar' => $c->name_ar])->values()->all(),
            'patient_count_definition' => DoctorCounts::PATIENT_DEFINITION,
        ])->all();
    }

    public function clinics(array $facility, array $filters, ?int $doctorId = null): array
    {
        if ($doctorId !== null) {
            $this->find($facility, $doctorId);
            $query = $this->counts->currentClinics($facility)->where('cs.staff_id', $doctorId)->select('c.id', 'c.code', 'c.name_ar')
                ->selectRaw('MIN(cs.starts_on) as starts_on')->groupBy('c.id', 'c.code', 'c.name_ar');
        } else {
            $query = DB::table('clinics as c')->where('c.facility_id', $facility['id'])->where('c.is_active', true)->select('c.id', 'c.code', 'c.name_ar');
        }
        if (($search = $filters['search'] ?? '') !== '' && $search !== null) {
            $query->where(fn ($q) => $q->whereLike('c.code', '%'.$search.'%')->orWhereLike('c.name_ar', '%'.$search.'%'));
        }
        $page = $query->orderBy('c.code')->orderBy('c.id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $linked = [];
        if ($doctorId === null && ! empty($filters['doctor_id'])) {
            $this->find($facility, (int) $filters['doctor_id']);
            $linked = $this->counts->currentClinics($facility)->where('cs.staff_id', $filters['doctor_id'])->whereIn('c.id', $page->getCollection()->pluck('id'))->pluck('c.id')->all();
        }

        return ['data' => $page->getCollection()->map(fn ($c) => ['id' => (int) $c->id, 'code' => $c->code, 'name_ar' => $c->name_ar,
            'starts_on' => $c->starts_on ?? null, 'is_linked' => $doctorId !== null || in_array($c->id, $linked),
            'can_view' => in_array('clinics.view', $facility['permissions'], true)])->all(), 'meta' => $this->meta($page)];
    }

    private function meta($page): array
    {
        return ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }
}
