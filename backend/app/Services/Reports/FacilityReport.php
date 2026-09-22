<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\DirectoryReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class FacilityReport
{
    public const PATIENTS_DEFINITION = 'مرضى ظهرت لهم زيارة غير ملغاة بتاريخ داخل الفترة المحددة في المنشأة.';

    public function facility(User $user, int $id): array
    {
        foreach (app(UserAccessContext::class)->forUser($user) as $entry) {
            if ($entry['facility']['id'] === $id) {
                return $entry['facility'] + ['permissions' => $entry['permissions']];
            }
        }
        throw new HttpResponseException(response()->json(['error' => ['code' => 'FACILITY_ACCESS_DENIED', 'message' => 'ليس لديك وصول إلى المنشأة المطلوبة.']], 403));
    }

    public function assemble(array $f, array $filters): array
    {
        $window = $this->window($f, $filters);

        return [
            'facility' => ['id' => $f['id'], 'code' => $f['code'], 'name_ar' => $f['name_ar'], 'timezone' => $f['timezone']],
            'period' => $window,
            'counters' => $this->counters($f, $window),
            'visit_status' => $this->visitStatus($f, $window),
            'series' => $this->series($f, $window),
            'mix' => $this->mix($f, $window),
            'clinics' => $this->ranks($f, $window, 'clinics.view', 'clinics as c', 'c.id', 'c.name_ar', 'v.clinic_id'),
            'doctors' => $this->ranks($f, $window, 'doctors.view', 'staff as s', 's.id', 's.full_name', 'v.attending_staff_id'),
            'procedures' => $this->procedureRanks($f, $window),
            'patients' => $this->patients($f, $window),
            'patients_definition' => self::PATIENTS_DEFINITION,
        ];
    }

    public function pdf(Request $request, array $f, array $filters): Response
    {
        $payload = $this->assemble($f, $filters);
        $issued = now($f['timezone']);
        $number = DB::transaction(function () use ($f, $issued) {
            $key = ['sequence_key' => 'facility_report', 'scope_key' => 'facility:'.$f['id'], 'period_key' => $issued->format('Y')];
            DB::table('number_sequences')->insertOrIgnore($key + ['current_value' => 0, 'created_at' => now()]);
            $sequence = DB::table('number_sequences')->where($key)->lockForUpdate()->first();
            DB::table('number_sequences')->where('id', $sequence->id)->update(['current_value' => $sequence->current_value + 1, 'updated_at' => now()]);

            return 'RP-'.$f['id'].'-'.$issued->format('Y').'-'.str_pad((string) ($sequence->current_value + 1), 6, '0', STR_PAD_LEFT);
        }, 3);
        $metadata = [
            'title' => 'تقرير النشاط',
            'number' => $number,
            'issued_at' => $issued->format('Y-m-d H:i:s'),
            'timezone' => $f['timezone'],
            'issuer' => $request->user()->name,
            'facility' => $f['name_ar'],
            'filters' => $payload['period']['label'],
        ];
        $bytes = app(DirectoryReport::class)->pdf([
            'metadata' => $metadata,
            'detail' => true,
            'columns' => [],
            'rows' => [],
            'report' => $payload,
        ], 'reports.facility');
        app(ClinicAudit::class)->record($request, $f['id'], $f['id'], 'exported', null, [
            'report_number' => $number, 'format' => 'pdf', 'period' => $payload['period']['key'],
            'starts_on' => $payload['period']['starts_on'], 'ends_on' => $payload['period']['ends_on'],
        ], 'facility_report');

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$number.'.pdf"',
            'X-Report-Number' => $number,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function window(array $f, array $filters): array
    {
        $today = CarbonImmutable::now($f['timezone'])->startOfDay();
        $key = $filters['period'];
        if ($key === 'day') {
            $starts = $today;
            $ends = $today;
        } elseif ($key === 'week') {
            $starts = $today->subDays(6);
            $ends = $today;
        } else {
            $starts = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'], $f['timezone'])->startOfDay();
            $ends = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'], $f['timezone'])->startOfDay();
        }

        return [
            'key' => $key,
            'label' => match ($key) {
                'day' => 'اليوم · '.$starts->toDateString(),
                'week' => 'آخر 7 أيام · '.$starts->toDateString().' — '.$ends->toDateString(),
                default => 'فترة مخصصة · '.$starts->toDateString().' — '.$ends->toDateString(),
            },
            'starts_on' => $starts->toDateString(),
            'ends_on' => $ends->toDateString(),
        ];
    }

    private function counters(array $f, array $window): array
    {
        $counters = [];
        $this->push($counters, $f, 'dossiers.view', 'visits', 'الزيارات', fn () => $this->visits($f, $window)->count());
        $this->push($counters, $f, 'dossiers.view', 'completed_visits', 'زيارات مكتملة', fn () => $this->visits($f, $window)->where('status', 'complete')->count());
        $this->push($counters, $f, 'dossiers.view', 'dossiers', 'بطاقات فُتحت', fn () => (int) DB::table('patient_dossiers')->where('facility_id', $f['id'])
            ->whereBetween('opening_date', [$window['starts_on'], $window['ends_on']])->count());
        $this->push($counters, $f, 'dossiers.view', 'services', 'خدمات منفّذة', fn () => $this->events($f, $window, 'visit_services', 'performed_on')->count());
        $this->push($counters, $f, 'dossiers.view', 'procedures', 'إجراءات منفّذة', fn () => $this->events($f, $window, 'visit_procedures', 'performed_on')->count());
        $this->push($counters, $f, 'dossiers.view', 'medications', 'صرف أدوية', fn () => $this->events($f, $window, 'visit_medications', 'dispensed_on')->count());
        if ($this->can($f, 'dossiers.view') && $this->can($f, 'dossiers.treatment.view')) {
            $counters[] = ['key' => 'doses', 'label' => 'جرعات علاجية', 'value' => $this->events($f, $window, 'dose_sessions', 'administered_on')->count()];
        }
        $this->push($counters, $f, 'clinics.view', 'clinics', 'عيادات نشطة', fn () => (int) DB::table('clinics')->where('facility_id', $f['id'])->where('is_active', true)->whereNull('archived_at')->count());
        $this->push($counters, $f, 'blood_bank.view', 'blood_bank', 'وقائع بنك الدم', fn () => (int) DB::table('blood_bank_events')->where('facility_id', $f['id'])->whereNull('voided_at')
            ->whereBetween('occurred_on', [$window['starts_on'], $window['ends_on']])->count());
        $this->push($counters, $f, 'stock.view', 'stock', 'إيصالات مؤكدة', fn () => (int) DB::table('medication_receipts')->where('facility_id', $f['id'])->where('status', 'confirmed')
            ->whereBetween('received_on', [$window['starts_on'], $window['ends_on']])->count());

        return $counters;
    }

    private function visitStatus(array $f, array $window): array
    {
        if (! $this->can($f, 'dossiers.view')) {
            return [];
        }
        $found = $this->visits($f, $window)->select('status', DB::raw('COUNT(*) as value'))->groupBy('status')->pluck('value', 'status');

        return [
            ['key' => 'complete', 'label' => 'مكتملة', 'value' => (int) ($found['complete'] ?? 0)],
            ['key' => 'draft', 'label' => 'مسودة', 'value' => (int) ($found['draft'] ?? 0)],
        ];
    }

    private function series(array $f, array $window): array
    {
        if (! $this->can($f, 'dossiers.view')) {
            return [];
        }
        $found = $this->visits($f, $window)->select('visit_date', DB::raw('COUNT(*) as value'))->groupBy('visit_date')->pluck('value', 'visit_date');
        $series = [];
        for ($day = CarbonImmutable::createFromFormat('Y-m-d', $window['starts_on']); $day->toDateString() <= $window['ends_on']; $day = $day->addDay()) {
            $date = $day->toDateString();
            $series[] = ['key' => $date, 'label' => $date, 'value' => (int) ($found[$date] ?? 0)];
        }

        return $series;
    }

    private function mix(array $f, array $window): array
    {
        $mix = [];
        $this->push($mix, $f, 'dossiers.view', 'services', 'الخدمات', fn () => $this->events($f, $window, 'visit_services', 'performed_on')->count());
        $this->push($mix, $f, 'dossiers.view', 'procedures', 'الإجراءات', fn () => $this->events($f, $window, 'visit_procedures', 'performed_on')->count());
        $this->push($mix, $f, 'dossiers.view', 'medications', 'صرف الأدوية', fn () => $this->events($f, $window, 'visit_medications', 'dispensed_on')->count());
        if ($this->can($f, 'dossiers.view') && $this->can($f, 'dossiers.treatment.view')) {
            $mix[] = ['key' => 'doses', 'label' => 'الجرعات', 'value' => $this->events($f, $window, 'dose_sessions', 'administered_on')->count()];
        }
        $this->push($mix, $f, 'blood_bank.view', 'blood_bank', 'بنك الدم', fn () => (int) DB::table('blood_bank_events')->where('facility_id', $f['id'])->whereNull('voided_at')
            ->whereBetween('occurred_on', [$window['starts_on'], $window['ends_on']])->count());

        return $mix;
    }

    private function ranks(array $f, array $window, string $permission, string $table, string $id, string $name, string $join): array
    {
        if (! $this->can($f, $permission)) {
            return [];
        }

        return $this->visits($f, $window)->where('v.status', 'complete')->join($table, $id, '=', $join)
            ->groupBy($id, $name)->orderByDesc(DB::raw('COUNT(*)'))->orderBy($name)->orderBy($id)
            ->limit(8)->get([$id.' as id', $name.' as name_ar', DB::raw('COUNT(*) as visit_count')])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name_ar' => $row->name_ar, 'visit_count' => (int) $row->visit_count])->all();
    }

    private function procedureRanks(array $f, array $window): array
    {
        if (! $this->can($f, 'dossiers.view')) {
            return [];
        }

        return $this->events($f, $window, 'visit_procedures', 'performed_on')
            ->join('procedures as p', 'p.id', '=', 'e.procedure_id')
            ->groupBy('p.id', 'p.name_ar')->orderByDesc(DB::raw('COUNT(*)'))->orderBy('p.name_ar')->orderBy('p.id')
            ->limit(8)->get(['p.id', 'p.name_ar', DB::raw('COUNT(*) as visit_count')])
            ->map(fn ($row) => ['id' => (int) $row->id, 'name_ar' => $row->name_ar, 'visit_count' => (int) $row->visit_count])->all();
    }

    private function patients(array $f, array $window): array
    {
        if (! $this->can($f, 'dossiers.view')) {
            return [];
        }

        return $this->visits($f, $window)
            ->join('patients as p', 'p.id', '=', 'v.patient_id')
            ->leftJoin('patient_dossiers as d', function ($join) use ($f) {
                $join->on('d.patient_id', '=', 'p.id')->where('d.facility_id', $f['id']);
            })
            ->groupBy('p.id', 'p.patient_code', 'p.first_name', 'p.family_name')
            ->orderByDesc(DB::raw('COUNT(*)'))->orderBy('p.family_name')->orderBy('p.id')
            ->limit(50)->get([
                'p.id', 'p.patient_code', 'p.first_name', 'p.family_name',
                DB::raw('COUNT(*) as visit_count'), DB::raw('MAX(v.visit_date) as last_visit_on'),
                DB::raw('MAX(d.id) as dossier_id'),
            ])->map(fn ($row) => [
                'id' => (int) $row->id,
                'dossier_id' => $row->dossier_id ? (int) $row->dossier_id : null,
                'patient_code' => (string) $row->patient_code,
                'patient_name' => trim($row->first_name.' '.$row->family_name),
                'visit_count' => (int) $row->visit_count,
                'last_visit_on' => (string) $row->last_visit_on,
            ])->all();
    }

    private function visits(array $f, array $window)
    {
        return DB::table('visits as v')->where('v.facility_id', $f['id'])->whereNull('v.voided_at')
            ->whereIn('v.status', ['complete', 'draft'])
            ->whereBetween('v.visit_date', [$window['starts_on'], $window['ends_on']]);
    }

    private function events(array $f, array $window, string $table, string $date)
    {
        return DB::table($table.' as e')->where('e.facility_id', $f['id'])->whereNull('e.voided_at')
            ->whereBetween('e.'.$date, [$window['starts_on'], $window['ends_on']]);
    }

    private function push(array &$rows, array $f, string $permission, string $key, string $label, callable $count): void
    {
        if (! $this->can($f, $permission)) {
            return;
        }
        $rows[] = ['key' => $key, 'label' => $label, 'value' => (int) $count()];
    }

    private function can(array $f, string $permission): bool
    {
        return in_array($permission, $f['permissions'], true);
    }
}
