<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\DirectorySpreadsheet;
use App\Services\Directory\ReportLayout;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class BloodBankReports
{
    public const LIMIT = 1000;

    public const COLUMNS = ['code' => 'كود الملف', 'name' => 'الاسم', 'kind' => 'النوع', 'phone' => 'الهاتف', 'governorate_name' => 'المحافظة', 'city_name' => 'المدينة', 'blood' => 'ABO / Rh', 'component_name' => 'المكوّن', 'clinic_name' => 'العيادة', 'doctor_name' => 'الطبيب المسؤول'];

    public const DONATIONS = ['code' => 'كود التبرع', 'donated_on' => 'التاريخ الفعلي', 'blood' => 'ABO / Rh', 'units' => 'الكمية', 'quantity_unit' => 'وحدة القياس', 'status' => 'الحالة'];

    public static function widths(array $labels): array
    {
        return match (array_keys($labels)) {
            array_keys(self::COLUMNS) => ['code' => 31, 'name' => 45, 'kind' => 16, 'phone' => 28, 'governorate_name' => 26, 'city_name' => 24, 'blood' => 20, 'component_name' => 20, 'clinic_name' => 32, 'doctor_name' => 31],
            array_keys(self::DONATIONS) => ['code' => 48, 'donated_on' => 27, 'blood' => 22, 'units' => 24, 'quantity_unit' => 26, 'status' => 39],
            array_keys(BloodEventReports::COLUMNS) => ['code' => 35, 'type' => 27, 'occurred_on' => 23, 'name' => 40, 'blood' => 20, 'component_name' => 20, 'quantity' => 23, 'quantity_unit' => 23, 'clinic_name' => 30, 'doctor_name' => 32],
            ['field', 'value'] => ['field' => 52, 'value' => 134],
            default => ReportLayout::widths(array_keys($labels)),
        };
    }

    private const SCREENING = ['not_requested' => 'غير مطلوب', 'requested' => 'مطلوب', 'pending' => 'قيد الإنجاز', 'complete' => 'منجز', 'cancelled' => 'ملغى'];

    public function document(Request $request, array $facility, array $filters, ?string $kind = null, ?int $id = null, ?int $donation = null): array
    {
        $queries = app(BloodBankQueries::class);
        $sections = DB::transaction(function () use ($queries, $facility, $filters, $kind, $id, $donation) {
            if ($id === null) {
                $rows = $queries->files($facility, $filters)->limit(self::LIMIT + 1)->get();
                $this->limit($rows->count());

                return [$this->section('ملفات بنك الدم', self::COLUMNS, $rows->map(function ($record) {
                    $row = (array) $record;
                    $row['kind'] = $row['kind'] === 'donor' ? 'متبرع' : 'مستفيد';
                    $row['blood'] = $this->blood($row);

                    return array_intersect_key($row, self::COLUMNS) + ['id' => $row['id']];
                })->all(), count($rows).' ملف مطابق؛ جميع النتائج، لا الصفحة الحالية')];
            }
            $p = $queries->profile($kind, $id, $facility['id']);
            $details = ['كود الملف' => $p['code'], 'الاسم' => $p['name'], 'نوع الملف' => $kind === 'donor' ? 'متبرع' : 'مستفيد'];
            if ($donation === null) {
                $labels = ['first_name' => 'الاسم الأول', 'family_name' => 'العائلة', 'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم', 'birth_date' => 'الميلاد', 'birth_date_accuracy' => 'دقة الميلاد', 'gender' => 'الجنس', 'phone' => 'الهاتف', 'alt_phone' => 'الهاتف البديل', 'address_line' => 'العنوان', 'displacement_status' => 'حالة النزوح'];
                $values = ['male' => 'ذكر', 'female' => 'أنثى', 'unknown' => 'غير مسجل', 'exact' => 'دقيق', 'year_only' => 'السنة فقط', 'estimated' => 'تقديري', 'resident' => 'مقيم', 'idp' => 'نازح', 'returnee' => 'عائد'];
                foreach ($labels as $key => $label) {
                    $value = $p['person'][$key] ?? null;
                    $details[$label] = in_array($key, ['gender', 'birth_date_accuracy', 'displacement_status'], true) ? ($values[$value] ?? $value) : $value;
                }
                $details += ['المحافظة' => $p['governorate_name'], 'المدينة' => $p['city_name'], 'ABO / Rh' => $this->blood($p), 'المكوّن' => $p['component_name'], 'العيادة' => $p['clinic_name'], 'الطبيب المسؤول' => $p['doctor_name']];
                if ($kind === 'recipient') {
                    $details += ['كود المريض المرتبط' => $p['patient_code'], 'جهة المستفيد' => $p['beneficiary_entity']];
                }
            }
            $sections = [$this->section('البيانات الأساسية', ['field' => 'البيان', 'value' => 'القيمة'], collect($details)->map(fn ($value, $field) => ['field' => $field, 'value' => $value, 'id' => $id, 'code' => $p['code'], 'name' => $p['name'], '_types' => $field === 'الميلاد' && $value ? ['value' => 'date'] : []])->values()->all(), $kind === 'recipient' ? 'ملف تسجيل مستفيد؛ لا يمثل عملية نقل دم.' : 'ملف متبرع؛ لا يمثل شهادة قبول أو أهلية.')];
            if ($donation === null) {
                $sections[] = $this->section('فحوص الملف', ['analyte' => 'الفحص', 'status' => 'الحالة'], array_map(fn ($s) => ['analyte' => $s['analyte'], 'status' => self::SCREENING[$s['status']]], $p['screenings']), 'حالات الفحوص المسجلة فقط؛ لا تُنسخ إلى التبرعات.', 'لا توجد فحوص مسجلة');
            }
            if ($kind === 'donor') {
                $events = $donation !== null ? [$queries->donation($id, $donation, $facility['id'])] : DB::table('blood_donations')->where('facility_id', $facility['id'])->where('donor_id', $id)->orderByDesc('donated_on')->orderByDesc('id')->limit(self::LIMIT + 1)->get()->map(fn ($r) => (array) $r)->all();
                $this->limit(count($events));
                $voided = count(array_filter($events, fn ($d) => $d['voided_at'] !== null));
                $sections[] = $this->section('التبرعات', self::DONATIONS, array_map(fn ($d) => ['id' => $d['id'], 'code' => $d['donation_code'], 'name' => $p['name'], 'donated_on' => $d['donated_on'], 'blood' => $this->blood($d), 'units' => $d['units'], 'quantity_unit' => ($d['quantity_unit'] ?? 'unit') === 'kg' ? 'كغ' : 'وحدة (تاريخية)', 'status' => $d['voided_at'] ? 'ملغى' : (['pending' => 'بانتظار المراجعة', 'accepted' => 'مقبول', 'rejected' => 'مرفوض'][$d['status']] ?? $d['status'])], $events), 'عدد الوقائع: '.count($events).'؛ منها الملغى: '.$voided.'؛ غير الملغى: '.(count($events) - $voided).'. كل كمية بوحدتها المسجلة، دون تحويل أو جمع وحدات مختلفة.', 'لا توجد تبرعات مسجلة', ['donated_on' => 'date', 'units' => 'decimal']);
            }

            return $sections;
        });
        $title = $id === null ? 'ملفات بنك الدم' : ($donation !== null ? 'واقعة تبرع' : ($kind === 'donor' ? 'ملف متبرع' : 'ملف مستفيد'));
        $meta = app(ReportMetadata::class)->make($request, $facility, $filters, self::COLUMNS, 'blood_bank', $id !== null, $sections[0]['note']);
        $meta['title'] = $title;
        $meta['definition_label'] = 'نطاق البيانات';
        $meta['filters'] = $id !== null ? 'تقرير فردي ضمن المنشأة المحددة' : 'النوع: '.(['donor' => 'متبرعون', 'recipient' => 'مستفيدون'][$filters['kind'] ?? ''] ?? 'الكل').' | البحث: '.($filters['search'] ?? 'الكل').' | الترتيب: '.(['code' => 'الكود', 'name' => 'الاسم', 'updated_at' => 'آخر تحديث'][$filters['sort'] ?? 'code']).' '.(($filters['direction'] ?? 'asc') === 'desc' ? 'تنازلي' : 'تصاعدي');

        return ['metadata' => $meta, 'detail' => $id !== null, 'identity' => $id === null ? null : ['code' => $sections[0]['rows'][0]['value'], 'name' => $sections[0]['rows'][1]['value']], 'columns' => array_keys($sections[0]['labels']), 'sections' => $sections];
    }

    public function export(Request $request, array $facility, array $filters, string $format, ?string $kind = null, ?int $id = null, ?int $donation = null): Response
    {
        $document = $this->document($request, $facility, $filters, $kind, $id, $donation);
        $bytes = $format === 'pdf' ? app(DirectoryReport::class)->pdf($document, 'reports.blood-bank') : $this->xlsx($document);
        app(ClinicAudit::class)->record($request, $facility['id'], $donation ?? $id ?? 0, 'exported', null, ['report_number' => $document['metadata']['number'], 'format' => $format], 'blood_bank');

        return response($bytes, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$document['metadata']['number'].'.'.$format.'"', 'X-Report-Number' => $document['metadata']['number'], 'X-Content-Type-Options' => 'nosniff']);
    }

    public function xlsx(array $document): string
    {
        $renderer = app(DirectorySpreadsheet::class);
        $book = null;
        foreach ($document['sections'] as $index => $section) {
            $meta = array_replace($document['metadata'], ['title' => $section['title'], 'definition' => $section['note'].($section['rows'] ? '' : ' '.$section['empty'])]);
            $part = $renderer->workbook(['metadata' => $meta, 'columns' => array_keys($section['labels']), 'labels' => $section['labels'], 'rows' => $section['rows'], 'types' => $section['types'], 'widths' => self::widths($section['labels']), 'landscape' => ! $document['detail']]);
            // Cairo headers may wrap (notably the explicit quantity unit). Excel does
            // not auto-fit a fixed-height repeated header when printing the workbook.
            $widths = self::widths($section['labels']);
            $headerLines = max(array_map(fn ($key) => ReportLayout::lines($section['labels'][$key], $widths[$key] - 3), array_keys($section['labels'])));
            $part->getSheet(0)->getRowDimension(8)->setRowHeight(max(29, 4 + $headerLines * 23.25));
            foreach ($part->getAllSheets() as $sheet) {
                if ($sheet->getTitle() === 'النصوص للطباعة') {
                    $sheet->setTitle('نصوص '.($index + 1));
                    foreach ($part->getSheet(0)->getHyperlinkCollection() as $link) {
                        $link->setUrl("sheet://'نصوص ".($index + 1)."'!A1");
                    }
                } elseif ($sheet->getTitle() === 'بيانات النصوص') {
                    $sheet->setTitle('بيانات نصوص '.($index + 1));
                }
            }
            if ($book === null) {
                $book = $part;
            } else {
                foreach ($part->getAllSheets() as $sheet) {
                    $book->addExternalSheet($sheet);
                }
            }
        }

        return $renderer->bytes($book);
    }

    private function section(string $title, array $labels, array $rows, string $note, string $empty = 'لا توجد نتائج مطابقة', array $types = []): array
    {
        $rows = array_map(fn ($r) => array_map(fn ($v) => $v === null || $v === '' ? 'غير مسجل' : $v, $r), $rows);

        return compact('title', 'labels', 'rows', 'note', 'empty', 'types');
    }

    private function blood(array $row): string
    {
        return ($row['blood_group'] ?: 'غير مسجل').' / '.(['positive' => '+', 'negative' => '−'][$row['rh'] ?? ''] ?? 'غير مسجل');
    }

    private function limit(int $count): void
    {
        if ($count > self::LIMIT) {
            throw new BloodBankException('EXPORT_LIMIT_EXCEEDED', 'التقرير يتجاوز 1000 سجل؛ ضيّق البحث أو راجع مسؤول النظام. لم يُصدّر تقرير جزئي.', 422);
        }
    }
}
