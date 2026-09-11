<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ClinicReports
{
    public const COLUMNS = ['number' => 'م', 'code' => 'كود العيادة', 'name_ar' => 'اسم العيادة', 'description' => 'التوصيف', 'doctors' => 'الأطباء العاملون', 'doctor_count' => 'عدد الأطباء', 'patient_count' => 'عدد المرضى'];

    public function __construct(private ClinicQueries $queries, private ClinicCounts $counts, private ClinicAudit $audit, private ReportMetadata $metadata, private DirectoryReport $renderer) {}

    public function export(Request $request, array $facility, array $filters, string $format, ?int $id = null): Response
    {
        $rows = DB::transaction(function () use ($facility, $filters, $id) {
            $query = $this->queries->query($facility, $filters);
            if ($id !== null) {
                $this->queries->find($facility, $id);
                $query->where('clinics.id', $id);
            }
            $rows = $query->limit(config('clinics.export_limit') + 1)->get();
            if ($rows->count() > config('clinics.export_limit')) {
                throw new ClinicException('EXPORT_LIMIT_EXCEEDED', 'نتائج التقرير أكبر من الحد الآمن. ضيّق نطاق الفلاتر ثم أعد المحاولة.', 422);
            }
            $doctors = $this->counts->currentDoctors($facility)->whereIn('cs.clinic_id', $rows->pluck('id'))
                ->select('cs.clinic_id', 's.id', 's.staff_code', 's.full_name')->selectRaw('MIN(cs.starts_on) as starts_on')
                ->groupBy('cs.clinic_id', 's.id', 's.staff_code', 's.full_name')->orderBy('s.full_name')->orderBy('s.id')->limit(config('clinics.export_doctor_limit') + 1)->get();
            if ($doctors->count() > config('clinics.export_doctor_limit')) {
                throw new ClinicException('EXPORT_LIMIT_EXCEEDED', 'عدد الارتباطات أكبر من الحد الآمن للتقرير. ضيّق النطاق.', 422);
            }
            $byClinic = $doctors->groupBy('clinic_id');

            return $rows->map(function ($row) use ($byClinic) {
                $links = $byClinic->get($row->id, collect())->map(fn ($d) => ['code' => $d->staff_code, 'name' => $d->full_name, 'starts_on' => $d->starts_on])->all();

                return (array) $row + ['doctors' => implode('، ', array_map(fn ($d) => $d['name'].' ('.$d['code'].')', $links)), 'links' => $links,
                    'details' => ['الحالة' => $row->is_active ? 'فعالة' : 'غير فعالة', 'التخصص' => $row->specialty_name ?? 'غير محدد', 'عدد الأطباء' => $row->doctor_count, 'عدد المرضى' => $row->patient_count]];
            })->all();
        });
        $columns = $id === null ? ($filters['columns'] ?? array_keys(self::COLUMNS)) : array_keys(self::COLUMNS);
        $metadata = $this->metadata->make($request, $facility, $filters, self::COLUMNS, 'clinic', $id !== null, ClinicCounts::PATIENT_DEFINITION);
        $response = $this->renderer->response(['rows' => $rows, 'columns' => $columns, 'labels' => self::COLUMNS, 'metadata' => $metadata, 'detail' => $id !== null, 'linkTitle' => 'الأطباء الحاليون'], $format);
        $this->audit->record($request, $facility['id'], $id ?? 0, 'exported', null, ['report_number' => $metadata['number'], 'format' => $format, 'row_count' => count($rows), 'filters' => $filters, 'columns' => $columns]);

        return $response;
    }
}
