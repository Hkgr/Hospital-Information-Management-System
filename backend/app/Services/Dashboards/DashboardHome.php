<?php

namespace App\Services\Dashboards;

use Illuminate\Support\Facades\DB;

class DashboardHome
{
    /** Known local destinations only. The API never returns URLs. */
    private const LINKS = [
        'patient-cards' => ['title' => 'بطاقة المريض', 'permission' => 'dossiers.view'],
        'visits' => ['title' => 'الزيارات', 'permission' => 'dossiers.view'],
        'doctors' => ['title' => 'الأطباء', 'permission' => 'doctors.view'],
        'clinics' => ['title' => 'العيادات', 'permission' => 'clinics.view'],
        'services-procedures' => ['title' => 'الخدمات والإجراءات', 'permission' => 'catalog.view'],
        'medications' => ['title' => 'الأدوية', 'permission' => 'catalog.view'],
        'stock' => ['title' => 'المخزون', 'permission' => 'stock.view'],
        'blood-bank' => ['title' => 'بنك الدم', 'permission' => 'blood_bank.view'],
    ];

    public function assemble(array $access): array
    {
        return ['links' => $this->links($access), 'stats' => $this->stats($access)];
    }

    private function links(array $access): array
    {
        $links = [];
        foreach (self::LINKS as $key => $definition) {
            if ($this->entries($access, $definition['permission']) !== []) {
                $links[] = ['key' => $key, 'title' => $definition['title']];
            }
        }

        return $links;
    }

    private function stats(array $access): array
    {
        $appointments = $this->appointments($access);

        return [
            'counters' => $this->counters($access, count($appointments)),
            'visit_status' => $this->statusSeries($access, 'dossiers.view', 'visits', ['complete' => 'مكتملة', 'draft' => 'مسودة'], fn ($query) => $query->whereNull('voided_at')->whereIn('status', ['complete', 'draft'])),
            'dossier_status' => $this->statusSeries($access, 'dossiers.view', 'patient_dossiers', ['active' => 'فعّالة', 'draft' => 'مسودة']),
            'clinics' => $this->clinicRanks($access),
            'doctors' => $this->doctorRanks($access),
            'appointments' => $appointments,
        ];
    }

    private function counters(array $access, int $upcoming): array
    {
        $counters = [];
        $this->pushCounter($counters, $access, 'dossiers', 'بطاقات المرضى', 'dossiers.view', fn (array $ids) => (int) DB::table('patient_dossiers')->whereIn('facility_id', $ids)->count());
        $this->pushCounter($counters, $access, 'visits', 'الزيارات', 'dossiers.view', fn (array $ids) => (int) DB::table('visits')->whereIn('facility_id', $ids)->whereNull('voided_at')->whereIn('status', ['complete', 'draft'])->count());
        $this->pushCounter($counters, $access, 'clinics', 'العيادات', 'clinics.view', fn (array $ids) => (int) DB::table('clinics')->whereIn('facility_id', $ids)->where('is_active', true)->whereNull('archived_at')->count());
        $this->pushCounter($counters, $access, 'doctors', 'الأطباء', 'doctors.view', fn (array $ids) => $this->assignedDoctors($access, $ids));
        $this->pushCounter($counters, $access, 'catalog', 'الخدمات والإجراءات', 'catalog.view', fn () => (int) DB::table('services')->whereNull('archived_at')->count() + (int) DB::table('procedures')->whereNull('archived_at')->count());
        $this->pushCounter($counters, $access, 'medications', 'الأدوية', 'catalog.view', fn () => (int) DB::table('medications')->whereNull('archived_at')->count());
        $this->pushCounter($counters, $access, 'stock', 'دفعات المخزون', 'stock.view', fn (array $ids) => (int) DB::table('medication_batches')->whereIn('facility_id', $ids)->where('status', 'active')->count());
        $this->pushCounter($counters, $access, 'blood_bank', 'وقائع بنك الدم', 'blood_bank.view', fn (array $ids) => (int) DB::table('blood_bank_events')->whereIn('facility_id', $ids)->whereNull('voided_at')->count());
        $this->pushCounter($counters, $access, 'appointments', 'المواعيد القادمة', 'dossiers.treatment.view', fn () => $upcoming, ['dossiers.view']);

        return $counters;
    }

    private function pushCounter(array &$counters, array $access, string $key, string $label, string $permission, callable $count, array $extra = []): void
    {
        $needed = [$permission, ...$extra];
        $entries = $access;
        foreach ($needed as $code) {
            $entries = $this->entries($entries, $code);
        }
        if ($entries === []) {
            return;
        }
        $counters[] = ['key' => $key, 'label' => $label, 'value' => (int) $count($this->ids($entries))];
    }

    private function statusSeries(array $access, string $permission, string $table, array $labels, ?callable $scope = null): array
    {
        $ids = $this->ids($this->entries($access, $permission));
        if ($ids === []) {
            return [];
        }
        $query = DB::table($table)->whereIn('facility_id', $ids);
        if ($scope) {
            $scope($query);
        }
        $found = $query->select('status', DB::raw('COUNT(*) as value'))->groupBy('status')->pluck('value', 'status');
        $series = [];
        foreach ($labels as $key => $label) {
            $series[] = ['key' => $key, 'label' => $label, 'value' => (int) ($found[$key] ?? 0)];
        }

        return $series;
    }

    private function clinicRanks(array $access): array
    {
        $ids = $this->ids($this->entries($access, 'clinics.view'));
        if ($ids === []) {
            return [];
        }

        return DB::table('visits as v')->join('clinics as c', 'c.id', '=', 'v.clinic_id')
            ->whereIn('v.facility_id', $ids)->whereIn('c.facility_id', $ids)
            ->where('v.status', 'complete')->whereNull('v.voided_at')
            ->groupBy('c.id', 'c.name_ar')->orderByDesc(DB::raw('COUNT(*)'))->orderBy('c.name_ar')->orderBy('c.id')
            ->limit(6)->get(['c.id', 'c.name_ar', DB::raw('COUNT(*) as visit_count')])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name_ar' => $row->name_ar, 'visit_count' => (int) $row->visit_count])->all();
    }

    private function doctorRanks(array $access): array
    {
        $ids = $this->ids($this->entries($access, 'doctors.view'));
        if ($ids === []) {
            return [];
        }

        return DB::table('visits as v')->join('staff as s', 's.id', '=', 'v.attending_staff_id')
            ->whereIn('v.facility_id', $ids)->where('v.status', 'complete')->whereNull('v.voided_at')
            ->groupBy('s.id', 's.full_name')->orderByDesc(DB::raw('COUNT(*)'))->orderBy('s.full_name')->orderBy('s.id')
            ->limit(6)->get(['s.id', 's.full_name as name_ar', DB::raw('COUNT(*) as visit_count')])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name_ar' => $row->name_ar, 'visit_count' => (int) $row->visit_count])->all();
    }

    private function assignedDoctors(array $access, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }
        $query = DB::table('clinic_staff as cs')->join('clinics as c', 'c.id', '=', 'cs.clinic_id')->join('staff as s', 's.id', '=', 'cs.staff_id')
            ->whereIn('c.facility_id', $ids)->where('c.is_active', true)->whereNull('c.archived_at')
            ->where('s.is_active', true)->whereNull('s.archived_at');
        $query->where(function ($outer) use ($access, $ids) {
            foreach ($access as $entry) {
                $id = $entry['facility']['id'];
                if (! in_array($id, $ids, true)) {
                    continue;
                }
                $today = now($entry['facility']['timezone'])->toDateString();
                $outer->orWhere(function ($inner) use ($id, $today) {
                    $inner->where('c.facility_id', $id)->where('cs.starts_on', '<=', $today)
                        ->where(fn ($ends) => $ends->whereNull('cs.ends_on')->orWhere('cs.ends_on', '>', $today));
                });
            }
        });

        return (int) $query->selectRaw('COUNT(DISTINCT s.id) as value')->value('value');
    }

    private function appointments(array $access): array
    {
        $entries = $this->entries($this->entries($access, 'dossiers.view'), 'dossiers.treatment.view');
        if ($entries === []) {
            return [];
        }
        $query = DB::table('oncology_sessions as s')
            ->join('oncology_plans as plan', 'plan.id', '=', 's.plan_id')
            ->join('patient_dossiers as d', function ($join) {
                $join->on('d.id', '=', 's.dossier_id')->on('d.facility_id', '=', 's.facility_id');
            })
            ->join('patients as p', 'p.id', '=', 'd.patient_id')
            ->leftJoin('clinics as c', 'c.id', '=', 's.clinic_id')
            ->leftJoin('staff as doc', 'doc.id', '=', 's.doctor_id')
            ->where('s.status', 'scheduled')
            ->whereNotIn('plan.status', ['cancelled', 'completed']);
        $query->where(function ($outer) use ($entries) {
            foreach ($entries as $entry) {
                $id = $entry['facility']['id'];
                $today = now($entry['facility']['timezone'])->toDateString();
                $outer->orWhere(fn ($inner) => $inner->where('s.facility_id', $id)->where('s.planned_on', '>=', $today));
            }
        });

        return $query->orderBy('s.planned_on')->orderBy('s.id')->limit(8)
            ->get(['s.id', 's.planned_on', 's.session_number', 's.dossier_id', 'p.patient_code', 'p.first_name', 'p.family_name', 'c.name_ar as clinic_name', 'doc.full_name as doctor_name'])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'planned_on' => (string) $row->planned_on,
                'session_number' => (int) $row->session_number,
                'dossier_id' => (int) $row->dossier_id,
                'patient_code' => (string) $row->patient_code,
                'patient_name' => trim($row->first_name.' '.$row->family_name),
                'clinic_name' => (string) ($row->clinic_name ?? ''),
                'doctor_name' => (string) ($row->doctor_name ?? ''),
            ])->all();
    }

    private function entries(array $access, string $permission): array
    {
        return array_values(array_filter($access, fn ($entry) => in_array($permission, $entry['permissions'], true)));
    }

    private function ids(array $entries): array
    {
        return array_values(array_unique(array_map(fn ($entry) => $entry['facility']['id'], $entries)));
    }
}
