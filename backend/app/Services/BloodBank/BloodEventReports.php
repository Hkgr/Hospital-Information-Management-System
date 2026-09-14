<?php

namespace App\Services\BloodBank;

use App\Exceptions\BloodBankException;
use App\Services\Clinics\ClinicAudit;
use App\Services\Directory\DirectoryReport;
use App\Services\Directory\ReportMetadata;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BloodEventReports
{
    public const COLUMNS = ['code' => 'كود الواقعة', 'type' => 'نوع الواقعة', 'occurred_on' => 'التاريخ', 'name' => 'الشخص وأكواده', 'blood' => 'ABO / Rh', 'component_name' => 'المكوّن', 'quantity' => 'الكمية', 'quantity_unit' => 'وحدة القياس', 'clinic_name' => 'العيادة', 'doctor_name' => 'الطبيب'];

    public static function type(array $e): string
    {
        return $e['kind'] === 'donation' ? 'تبرع' : ($e['benefit_kind'] === 'issue' ? 'استفادة · صرف مكوّن' : 'استفادة · نقل دم فعلي');
    }

    public function document(Request $r, array $f, array $filters, ?int $personId = null, ?int $eventId = null): array
    {
        return DB::transaction(function () use ($r, $f, $filters, $personId, $eventId) {
            $queries = app(BloodEventQueries::class);
            $event = $eventId ? $queries->event($eventId, $f['id']) : null;
            $person = $personId || $event ? $queries->person($personId ?? $event['person_id'], $f['id']) : null;
            $q = $queries->events($f['id'], $personId ? ['person_id' => $personId] : $filters);
            if ($event) {
                $q->where('e.id', $eventId);
            }
            $totals = $queries->totals($q);
            $rows = $queries->ordered($q, $filters)->limit(1001)->get();
            if ($rows->count() > 1000) {
                throw new BloodBankException('EXPORT_LIMIT_EXCEEDED', 'أكثر من 1000 واقعة؛ ضيّق الفلاتر. لم يُنشأ تقرير جزئي.', 422);
            }
            $summary = 'تبرعات: '.$totals['donations'].'؛ استفادات: '.$totals['benefits'].'؛ أشخاص فريدون: '.$totals['unique_people'].'. مرحلتا الصرف والنقل المرتبطتان استفادة واحدة. الإلغاءات لا تدخل الإجماليات. لا تُجمع وحدات قياس مختلفة.';
            $sections = [];
            if ($person) {
                $details = ['كود الشخص' => $person['code'], 'الاسم' => $person['name'], 'كود المريض' => $person['patient_code'], 'الأكواد السابقة' => implode('، ', $person['aliases']), 'زمرة الشخص الحالية' => $this->blood($person)];
                if (! $event) {
                    foreach (['first_name' => 'الاسم الأول', 'family_name' => 'العائلة', 'father_name' => 'اسم الأب', 'mother_name' => 'اسم الأم', 'birth_date' => 'الميلاد', 'phone' => 'الهاتف', 'alt_phone' => 'الهاتف البديل', 'address_line' => 'عنوان السكن'] as $key => $label) {
                        $details[$label] = $person['person'][$key] ?? null;
                    }
                    $details += ['المحافظة' => $person['governorate_name'], 'المدينة' => $person['city_name']];
                }
                $sections[] = $this->details('بيانات الشخص الحالية', $details, 'بيانات الشخص الحالية؛ زمرة كل واقعة ومعلوماتها التاريخية مستقلة.');
            }
            if ($event) {
                $issue = $event['issue_event_id'] ? DB::table('blood_bank_events')->where('id', $event['issue_event_id'])->value('code') : null;
                $transfusion = $event['linked_transfusion_id'] ? DB::table('blood_bank_events')->where('id', $event['linked_transfusion_id'])->value('code') : null;
                $sections[] = $this->details('بيانات الواقعة', ['كود الواقعة' => $event['code'], 'النوع' => self::type($event), 'التاريخ الفعلي' => $event['occurred_on'], 'الكمية' => $event['quantity'], 'وحدة القياس' => $event['quantity_unit'] === 'kg' ? 'كغ' : 'وحدة (تاريخية)', 'الصرف المرتبط' => $issue, 'النقل الفعلي المرتبط' => $transfusion, 'جهة المستفيد' => $event['beneficiary_entity'], 'عنوان الجهة' => $event['entity_address'], 'عنوان تاريخي غير مصنف' => $event['legacy_address'], 'الأكواد السابقة' => $event['aliases']->implode('، ')], 'الصرف وحده لا يثبت نقل دم. تاريخ إنشاء الملف لا يمثل واقعة.');
                $screens = $event['screenings']->map(fn ($s) => ['analyte' => $s->analyte, 'status' => self::screenStatus($s->status)])->all();
                $sections[] = ['title' => 'فحوص الواقعة', 'labels' => ['analyte' => 'الفحص', 'status' => 'الحالة'], 'rows' => $screens, 'types' => [], 'note' => 'الفحوص المرتبطة بهذه الواقعة فقط؛ النتائج والطرق التاريخية محفوظة دون تضمينها في التقرير.', 'empty' => 'لا توجد فحوص مسجلة'];
                if ($event['legacy_screenings']) {
                    $sections[] = ['title' => 'مراجع الفحوص التاريخية المثبتة', 'labels' => ['name_ar' => 'الفحص', 'tested_on' => 'تاريخ الفحص'], 'rows' => $event['legacy_screenings'], 'types' => ['tested_on' => 'date'], 'note' => 'فحوص ذات رابط أصلي مثبت بالتبرع؛ لا نتائج حساسة في التقرير.', 'empty' => ''];
                }
            }
            $eventRows = $rows->map(function ($value) {
                $e = (array) $value;
                $row = array_intersect_key($e, self::COLUMNS) + ['type' => self::type($e).($e['voided_at'] ? ' · ملغى' : '').($e['issue_event_id'] ? ' · مرتبط بصرف' : ''), 'blood' => $this->blood($e)];
                $row['name'] .= "\n".$e['person_code'].($e['patient_code'] ? "\n".$e['patient_code'] : '');
                $row['quantity_unit'] = $e['quantity_unit'] === 'kg' ? 'كغ' : 'وحدة (تاريخية)';
                $row += ['id' => $e['id']];
                // Excel keeps only 15 significant numeric digits; retain larger exact decimals as text.
                if (strlen(ltrim(str_replace('.', '', $e['quantity']), '0')) > 15) {
                    $row['_types'] = ['quantity' => 'text'];
                }

                return $row;
            })->all();
            $sections[] = ['title' => 'سجل التبرع والاستفادة', 'labels' => self::COLUMNS, 'rows' => $eventRows, 'types' => ['occurred_on' => 'date', 'quantity' => 'decimal'], 'note' => $summary, 'empty' => 'لا توجد وقائع مسجلة؛ الملف القديم لا يمثل تبرعًا أو استفادة.'];
            if ($person && ! $event && $person['legacy_screenings']->count()) {
                $sections[] = ['title' => 'فحوص قديمة غير مسندة لواقعة', 'labels' => ['analyte' => 'الفحص', 'status' => 'الحالة'], 'rows' => $person['legacy_screenings']->map(fn ($s) => ['analyte' => $s->analyte, 'status' => self::screenStatus($s->status)])->all(), 'types' => [], 'note' => 'لا يوجد دليل يربط هذه الفحوص بواقعة محددة؛ لا تدخل عدد الوقائع.', 'empty' => ''];
            }
            foreach ($sections as &$section) {
                $section['rows'] = array_map(fn ($row) => array_map(fn ($v) => $v === null || $v === '' ? 'غير مسجل' : $v, (array) $row) + ['id' => $eventId ?? $personId ?? 0, 'code' => $event['code'] ?? $person['code'] ?? '', 'name' => $person['name'] ?? ''], $section['rows']);
            }
            unset($section);
            $meta = app(ReportMetadata::class)->make($r, $f, $filters, self::COLUMNS, 'blood_bank', (bool) $person, $summary);
            $meta['title'] = $event ? 'واقعة '.self::type($event) : ($person ? 'ملف الشخص ووقائعه' : 'سجل التبرع والاستفادة');
            $meta['definition_label'] = 'الإجماليات';
            $filterLabels = ['search' => 'البحث', 'kind' => 'نوع الواقعة', 'benefit_kind' => 'نوع الاستفادة', 'from' => 'من تاريخ', 'to' => 'إلى تاريخ', 'blood_component_id' => 'المكوّن', 'clinic_id' => 'العيادة', 'blood_group' => 'ABO', 'rh' => 'Rh', 'sort' => 'الترتيب', 'direction' => 'الاتجاه'];
            $filterValues = ['donation' => 'تبرع', 'benefit' => 'استفادة', 'issue' => 'صرف مكوّن', 'transfusion' => 'نقل دم فعلي', 'positive' => 'موجب', 'negative' => 'سالب', 'asc' => 'تصاعدي', 'desc' => 'تنازلي', 'code' => 'الكود', 'occurred_on' => 'التاريخ', 'name' => 'الاسم', 'quantity' => 'الكمية'];
            $parts = [];
            foreach ($filterLabels as $key => $label) {
                if (isset($filters[$key]) && $filters[$key] !== '') {
                    $parts[] = $label.': '.($filterValues[$filters[$key]] ?? $filters[$key]);
                }
            }
            $meta['filters'] = $person ? 'تقرير فردي في المنشأة المحددة' : ($parts ? implode(' | ', $parts) : 'جميع الوقائع؛ التاريخ تنازليًا');

            return ['metadata' => $meta, 'detail' => (bool) $person, 'identity' => $person ? ['name' => $person['name'], 'code' => $person['code']] : null, 'columns' => array_keys($sections[0]['labels']), 'sections' => $sections];
        });
    }

    public function export(Request $r, array $f, array $filters, string $format, ?int $person = null, ?int $event = null)
    {
        $doc = $this->document($r, $f, $filters, $person, $event);
        $bytes = $format === 'pdf' ? app(DirectoryReport::class)->pdf($doc, 'reports.blood-bank') : app(BloodBankReports::class)->xlsx($doc);
        app(ClinicAudit::class)->record($r, $f['id'], $event ?? $person ?? 0, 'exported', null, ['number' => $doc['metadata']['number'], 'format' => $format], 'blood_bank_event');

        return response($bytes, 200, ['Content-Type' => $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.$doc['metadata']['number'].'.'.$format.'"', 'X-Report-Number' => $doc['metadata']['number'], 'X-Content-Type-Options' => 'nosniff']);
    }

    private function details(string $title, array $values, string $note): array
    {
        return ['title' => $title, 'labels' => ['field' => 'البيان', 'value' => 'القيمة'], 'rows' => collect($values)->map(fn ($v, $k) => ['field' => $k, 'value' => $v])->values()->all(), 'types' => [], 'note' => $note, 'empty' => ''];
    }

    private function blood(array $p): string
    {
        return ($p['blood_group'] ?: 'غير مسجل').' / '.(['positive' => '+', 'negative' => '−'][$p['rh'] ?? ''] ?? 'غير مسجل');
    }

    public static function screenStatus(string $s): string
    {
        return ['not_requested' => 'لم يُطلب', 'requested' => 'مطلوب', 'pending' => 'بانتظار النتيجة', 'complete' => 'مكتمل', 'cancelled' => 'ملغى'][$s] ?? $s;
    }
}
