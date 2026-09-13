<?php

namespace App\Services\Catalog;

use App\Exceptions\CatalogException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class CatalogReports
{
    public function export(Request $request, array $facility, array $filters, string $format, ?string $kind = null, ?int $id = null): Response
    {
        $queries = app(CatalogQueries::class);
        $rows = DB::transaction(function () use ($queries, $facility, $filters, $kind, $id) {
            if ($id !== null) {
                return [$queries->find($facility, $kind, $id)];
            }
            $rows = $queries->query($facility, $filters)->limit(1001)->get();
            if ($rows->count() > 1000) {
                throw new CatalogException('EXPORT_LIMIT_EXCEEDED', 'نتائج التقرير تتجاوز 1000 عنصر؛ ضيّق الفلاتر وأعد المحاولة.', 422);
            }

            return $rows->map(fn ($row) => $queries->row($row))->all();
        });
        $labels = CatalogQueries::COLUMNS;
        $metadata = app(ReportMetadata::class)->make($request, $facility, $filters, $labels, 'catalog', $id !== null, CatalogBeneficiaries::DEFINITION);
        if ($id === null) {
            $metadata['filters'] .= ' | النوع: '.match ($filters['kind'] ?? '') {
                'service' => 'خدمات', 'procedure' => 'إجراءات', default => 'الكل'
            }.' | جميع النتائج المطابقة، لا الصفحة الحالية';
        }
        foreach ($rows as &$row) {
            $row['kind'] = $row['kind'] === 'service' ? 'خدمة' : 'إجراء';
            $row['is_active'] = $row['archived_at'] ? 'مؤرشف' : ($row['is_active'] ? 'فعال' : 'غير فعال');
            $row['details'] = ['الكود' => $row['code'], 'الاسم' => $row['name_ar'], 'النوع' => $row['kind'], 'الحالة' => $row['is_active'], 'عدد المستفيدين' => $row['patient_count']];
            $row['links'] = [];
        }
        unset($row);
        $response = app(DirectoryReport::class)->response(['rows' => $rows, 'columns' => $filters['columns'] ?? array_keys($labels), 'labels' => $labels,
            'metadata' => $metadata, 'detail' => $id !== null, 'descriptionTitle' => $kind === 'service' ? 'وصف الخدمة' : 'وصف الإجراء', 'linkTitle' => 'لا توجد ارتباطات تعريف مباشرة بالعيادات في المخطط الحالي'], $format);
        app(ClinicAudit::class)->record($request, $facility['id'], $id ?? 0, 'exported', null, ['report_number' => $metadata['number'], 'format' => $format, 'row_count' => count($rows)], $kind ?? 'catalog');

        return $response;
    }
}
