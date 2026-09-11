<?php

namespace App\Services\Doctors;

use App\Exceptions\DoctorException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class DoctorReports
{
    public const COLUMNS = ['number' => 'م', 'code' => 'كود الطبيب', 'name' => 'اسم الطبيب', 'specialties' => 'التخصصات', 'description' => 'التوصيف المهني', 'clinics' => 'العيادات الحالية', 'clinic_count' => 'عدد العيادات', 'patient_count' => 'عدد المرضى', 'is_active' => 'الحالة'];

    public function __construct(private DoctorQueries $queries, private DoctorCounts $counts, private ClinicAudit $audit, private ReportMetadata $metadata, private DirectoryReport $renderer) {}

    public function export(Request $request, array $facility, array $filters, string $format, ?int $id = null): Response
    {
        $rows = DB::transaction(function () use ($facility, $filters, $id) {
            $query = $this->queries->query($facility, $filters, $id !== null);
            if ($id !== null) {
                $query->where('s.id', $id);
            }
            $raw = $query->limit(config('clinics.export_limit') + 1)->get();
            if ($id !== null && $raw->isEmpty()) {
                throw new DoctorException('DOCTOR_NOT_FOUND', 'الطبيب غير موجود في الدليل المتاح.', 404);
            }
            if ($raw->count() > config('clinics.export_limit')) {
                throw new DoctorException('EXPORT_LIMIT_EXCEEDED', 'نتائج التقرير أكبر من الحد الآمن. ضيّق الفلاتر.', 422);
            }
            $links = $this->counts->currentClinics($facility)->whereIn('cs.staff_id', $raw->pluck('id'))->select('cs.staff_id', 'c.id', 'c.code', 'c.name_ar')
                ->selectRaw('MIN(cs.starts_on) as starts_on')->groupBy('cs.staff_id', 'c.id', 'c.code', 'c.name_ar')->orderBy('c.code')->orderBy('c.id')->limit(config('clinics.export_doctor_limit') + 1)->get();
            if ($links->count() > config('clinics.export_doctor_limit')) {
                throw new DoctorException('EXPORT_LIMIT_EXCEEDED', 'عدد الارتباطات أكبر من الحد الآمن للتقرير. ضيّق النطاق.', 422);
            }
            $grouped = $links->groupBy('staff_id');

            return array_map(function ($row) use ($grouped) {
                $links = $grouped->get($row['id'], collect())->map(fn ($c) => ['code' => $c->code, 'name' => $c->name_ar, 'starts_on' => $c->starts_on])->all();
                $specialties = implode('، ', array_column($row['specialties'], 'name_ar'));

                return array_replace($row, ['specialties' => $specialties, 'is_active' => $row['archived_at'] ? 'مؤرشف' : ($row['is_active'] ? 'فعال' : 'غير فعال'),
                    'clinics' => implode('، ', array_map(fn ($c) => $c['name'].' ('.$c['code'].')', $links)), 'links' => $links,
                    'details' => ['نوع الطبيب' => $row['staff_type']['name_ar'], 'التخصصات' => $specialties, 'الترخيص' => $row['license_no'], 'الهاتف' => $row['phone'], 'الحالة' => $row['archived_at'] ? 'مؤرشف' : ($row['is_active'] ? 'فعال' : 'غير فعال'), 'عدد العيادات' => $row['clinic_count'], 'عدد المرضى' => $row['patient_count']]]);
            }, $this->queries->present($raw, $facility));
        });
        $columns = $id === null ? ($filters['columns'] ?? array_keys(self::COLUMNS)) : array_keys(self::COLUMNS);
        $metadata = $this->metadata->make($request, $facility, $filters, self::COLUMNS, 'doctor', $id !== null, DoctorCounts::PATIENT_DEFINITION);
        $response = $this->renderer->response(['rows' => $rows, 'columns' => $columns, 'labels' => self::COLUMNS, 'metadata' => $metadata, 'detail' => $id !== null, 'linkTitle' => 'العيادات الحالية في المنشأة'], $format);
        $this->audit->record($request, $facility['id'], $id ?? 0, 'exported', null, ['report_number' => $metadata['number'], 'format' => $format, 'row_count' => count($rows), 'filters' => $filters, 'columns' => $columns], 'doctor');

        return $response;
    }
}
