<?php

namespace App\Services\Clinics;

use App\Exceptions\ClinicException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mpdf\Container\ContainerInterface;
use Mpdf\Http\ClientInterface;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Response;

class ClinicReports
{
    public const COLUMNS = ['number' => 'م', 'code' => 'كود العيادة', 'name_ar' => 'اسم العيادة', 'description' => 'التوصيف', 'doctors' => 'الأطباء العاملون', 'doctor_count' => 'عدد الأطباء', 'patient_count' => 'عدد المرضى'];

    public function __construct(private ClinicQueries $queries, private ClinicCounts $counts, private ClinicAudit $audit) {}

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
                ->groupBy('cs.clinic_id', 's.id', 's.staff_code', 's.full_name')->orderBy('s.full_name')->orderBy('s.id')
                ->limit(config('clinics.export_doctor_limit') + 1)->get();
            if ($doctors->count() > config('clinics.export_doctor_limit')) {
                throw new ClinicException('EXPORT_LIMIT_EXCEEDED', 'عدد ارتباطات الأطباء أكبر من الحد الآمن للتقرير. ضيّق النطاق.', 422);
            }
            $byClinic = $doctors->groupBy('clinic_id');

            return $rows->map(fn ($row) => (array) $row + ['doctors' => $byClinic->get($row->id, collect())->all()])->all();
        });
        $issued = now($facility['timezone']);
        $number = DB::transaction(function () use ($facility, $issued) {
            $key = ['sequence_key' => 'clinic_report', 'scope_key' => 'facility:'.$facility['id'], 'period_key' => $issued->format('Y')];
            DB::table('number_sequences')->insertOrIgnore($key + ['current_value' => 0, 'created_at' => now()]);
            $sequence = DB::table('number_sequences')->where($key)->lockForUpdate()->first();
            DB::table('number_sequences')->where('id', $sequence->id)->update(['current_value' => $sequence->current_value + 1, 'updated_at' => now()]);

            return 'CL-'.$facility['id'].'-'.$issued->format('Y').'-'.str_pad((string) ($sequence->current_value + 1), 6, '0', STR_PAD_LEFT);
        }, 3);
        $columns = $id === null ? ($filters['columns'] ?? array_keys(self::COLUMNS)) : array_keys(self::COLUMNS);
        if ($format === 'xlsx' && in_array('doctors', $columns, true)) {
            foreach ($rows as $row) {
                if (mb_strlen(implode('، ', array_map(fn ($d) => $d->full_name, $row['doctors']))) > 32767) {
                    throw new ClinicException('EXPORT_LIMIT_EXCEEDED', 'أسماء الأطباء تتجاوز حد خلية Excel. أخفِ عمود الأسماء مع الإبقاء على عدد الأطباء.', 422);
                }
            }
        }
        if ($format === 'pdf' && $id === null) {
            foreach ($rows as $row) {
                // mPDF cannot split an oversized table cell without shrinking its text.
                if ((in_array('description', $columns, true) && mb_strlen($row['description'] ?? '') > 1800)
                    || (in_array('doctors', $columns, true) && mb_strlen(implode('، ', array_map(fn ($d) => $d->full_name, $row['doctors']))) > 600)) {
                    throw new ClinicException('PDF_LAYOUT_LIMIT_EXCEEDED', 'النص في إحدى الخلايا طويل جدًا لتقرير القائمة. أخفِ عمود التوصيف أو الأطباء، أو استخدم Excel أو تقرير العيادة المفردة.', 422);
                }
            }
        }
        $metadata = [
            'title' => $id === null ? 'قائمة العيادات' : 'تفاصيل عيادة', 'number' => $number,
            'issued_at' => $issued->format('Y-m-d H:i:s').' '.$facility['timezone'],
            'issuer' => $request->user()->name, 'facility' => $facility['name_ar'],
            'filters' => $this->filterDescription($filters), 'definition' => ClinicCounts::PATIENT_DEFINITION,
        ];
        $bytes = $format === 'xlsx' ? $this->xlsx($rows, $columns, $metadata) : $this->pdf($rows, $columns, $metadata, $id !== null);
        $this->audit->record($request, $facility['id'], $id ?? 0, 'exported', null, ['report_number' => $number, 'format' => $format, 'row_count' => count($rows), 'filters' => $filters, 'columns' => $columns]);

        return response($bytes, 200, [
            'Content-Type' => $format === 'xlsx' ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$number.'.'.$format.'"',
            'X-Report-Number' => $number, 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filterDescription(array $filters): string
    {
        $labels = ['search' => 'البحث', 'status' => 'الحالة', 'doctor_id' => 'معرّف الطبيب', 'specialty_id' => 'معرّف التخصص', 'sort' => 'الترتيب', 'direction' => 'الاتجاه'];
        $parts = [];
        foreach ($labels as $key => $label) {
            if (isset($filters[$key])) {
                $parts[] = $label.': '.$filters[$key];
            }
        }

        return $parts ? implode(' | ', $parts) : 'جميع عيادات المنشأة — مرتبة بالكود تصاعديًا';
    }

    private function xlsx(array $rows, array $columns, array $metadata): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator($metadata['issuer'])->setTitle($metadata['title'])->setSubject($metadata['number']);
        $sheet = $book->getActiveSheet()->setTitle('العيادات')->setRightToLeft(true);
        $drawing = new Drawing;
        $drawing->setPath(resource_path('reports/logo-ar-color.png'))->setName('هوية المشفى')->setHeight(65)->setCoordinates('A1')->setWorksheet($sheet);
        $sheet->getRowDimension(1)->setRowHeight(55);
        $headerLines = [$metadata['title'].' — '.$metadata['number'], 'المنشأة: '.$metadata['facility'], 'أصدره: '.$metadata['issuer'].' | '.$metadata['issued_at'], 'الفلاتر: '.$metadata['filters'], $metadata['definition']];
        foreach ($headerLines as $index => $line) {
            $sheet->setCellValueExplicit([1, $index + 2], $line, DataType::TYPE_STRING);
            if (count($columns) > 1) {
                $sheet->mergeCells([1, $index + 2, count($columns), $index + 2]);
            }
        }
        $headerRow = 8;
        foreach ($columns as $col => $key) {
            $sheet->setCellValueExplicit([$col + 1, $headerRow], self::COLUMNS[$key], DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($col + 1)->setWidth(match ($key) {
                'number', 'doctor_count', 'patient_count' => 14, 'description', 'doctors' => 50, default => 28
            });
        }
        foreach ($rows as $index => $row) {
            foreach ($columns as $col => $key) {
                $value = match ($key) {
                    'number' => $index + 1,
                    'doctors' => implode('، ', array_map(fn ($d) => $d->full_name, $row['doctors'])),
                    default => $row[$key] ?? '',
                };
                $sheet->setCellValueExplicit([$col + 1, $headerRow + $index + 1], $value,
                    in_array($key, ['number', 'doctor_count', 'patient_count'], true) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $last = max($headerRow, $headerRow + count($rows));
        $sheet->setAutoFilter([1, $headerRow, count($columns), $last])->freezePane('A9');
        $sheet->getStyle([1, 2, count($columns), $last])->getAlignment()->setWrapText(true)->setVertical('top');
        $sheet->getStyle([1, $headerRow, count($columns), $headerRow])->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle([1, $headerRow, count($columns), $headerRow])->getFill()->setFillType('solid')->getStartColor()->setARGB('FF176B68');
        $stream = fopen('php://temp', 'w+b');
        try {
            (new Xlsx($book))->save($stream);
            rewind($stream);

            return stream_get_contents($stream);
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
            unset($sheet, $drawing, $book);
            gc_collect_cycles();
        }
    }

    private function pdf(array $rows, array $columns, array $metadata, bool $detail): string
    {
        // Only trusted templates and in-memory brand artwork. No outbound HTTP client.
        $container = new class implements ContainerInterface
        {
            public function has($id)
            {
                return $id === 'httpClient';
            }

            public function get($id)
            {
                return new class implements ClientInterface
                {
                    public function sendRequest(RequestInterface $request)
                    {
                        throw new \RuntimeException('External report assets are forbidden.');
                    }
                };
            }
        };
        $pdf = new Mpdf(['mode' => 'utf-8', 'format' => $detail ? 'A4' : 'A4-L', 'default_font' => 'dejavusans',
            'default_font_size' => 10, 'margin_top' => 12, 'margin_bottom' => 18, 'tempDir' => storage_path('framework/cache/clinic-pdf')], $container);
        try {
            $pdf->SetDirectionality('rtl');
            $pdf->SetTitle($metadata['title']);
            $pdf->SetAuthor($metadata['issuer']);
            $pdf->imageVars['hospitalLogo'] = file_get_contents(resource_path('reports/logo-ar-color.png'));
            $pdf->SetHTMLFooter('<div dir="ltr" style="text-align:center;font-size:9pt">'.e($metadata['number']).' — {PAGENO} / {nbpg}</div>');
            $pdf->WriteHTML(view('reports.clinics', compact('rows', 'columns', 'metadata', 'detail'))->render());

            return $pdf->Output('', 'S');
        } finally {
            // mPDF keeps cyclic service/font references; release them between reports
            // in long-lived workers instead of waiting for PHP's root-count threshold.
            unset($pdf);
            gc_collect_cycles();
        }
    }
}
