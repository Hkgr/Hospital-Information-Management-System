<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnonymousStatistics
{
    public const MINIMUM = 5;

    public const POLICY = 'خمسة مرضى على الأقل لكل خلية. تُجمع الفئات الصغيرة دون أسمائها، مع فئة إضافية عند الحاجة. لا تُجمع أعداد المرضى الفريدين بين الصفوف أو الأشهر.';

    public function filters(Request $r, array $f): array
    {
        $input = $r->validate(['from_month' => 'required|date_format:Y-m', 'to_month' => 'required|date_format:Y-m']);
        // No arbitrary sub-month, patient, clinic or cross-dimensional filters.
        $unknown = array_diff(array_keys($r->all()), ['facility_id', 'from_month', 'to_month']);
        if ($unknown !== []) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'STATISTICS_FILTERS_INVALID', 'message' => 'الفلاتر المتقاطعة غير متاحة لحماية الخصوصية.']], 422));
        }
        $from = CarbonImmutable::createFromFormat('!Y-m', $input['from_month'], $f['timezone']);
        $to = CarbonImmutable::createFromFormat('!Y-m', $input['to_month'], $f['timezone']);
        if ($from->year < 1900 || $to < $from || $from->diffInMonths($to) > 11 || $to >= CarbonImmutable::now($f['timezone'])->startOfMonth()) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'STATISTICS_PERIOD_INVALID', 'message' => 'اختر من شهر إلى اثني عشر شهرًا مكتملًا، بدءًا من سنة 1900.']], 422));
        }

        return $input;
    }

    private function visits(array $f): Builder
    {
        return DB::table('visits as v')->where('v.facility_id', $f['id'])->whereNull('v.voided_at')->whereIn('v.status', ['draft', 'complete'])
            ->where('v.visit_date', '<=', now($f['timezone'])->toDateString());
    }

    /** The only public projection is aggregate labels and protected counts. */
    public function assemble(array $f, array $filters): array
    {
        return DB::transaction(function () use ($f, $filters) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $filters['from_month'], $f['timezone']);
            $last = CarbonImmutable::createFromFormat('!Y-m', $filters['to_month'], $f['timezone']);
            $months = [];
            $outputRows = 0;
            while ($month <= $last) {
                $start = $month->toDateString();
                $end = $month->endOfMonth()->toDateString();
                $v = $this->visits($f)->whereBetween('v.visit_date', [$start, $end]);
                $sections = [];
                $sections[] = $this->section(clone $v, "CASE v.status WHEN 'draft' THEN 'مسودة' ELSE 'مكتملة' END", 'status', 'حالة الزيارات', 'زيارات فعلية غير ملغاة بتاريخ الزيارة؛ تشمل المسودة والمكتملة.');
                $sections[] = $this->section((clone $v)->leftJoin('clinics as c', 'c.id', '=', 'v.clinic_id'), "COALESCE(c.name_ar, 'عيادة غير مسجلة')", 'clinics', 'العيادات', 'العيادة المسجلة على الزيارة الفعلية؛ المريض قد يراجع أكثر من عيادة.', [], 'c.id');
                $sections[] = $this->section((clone $v)->join('patients as p', 'p.id', '=', 'v.patient_id'), "CASE p.gender WHEN 'male' THEN 'ذكر' WHEN 'female' THEN 'أنثى' ELSE 'غير معروف' END", 'gender', 'الجنس', 'الهوية الحالية لمرضى الزيارات المؤهلة؛ العدد الثاني هو الزيارات.');
                // Only an exact DOB is used; unknown/estimated ages remain unknown.
                $age = "CASE WHEN p.birth_date IS NULL OR p.birth_date_accuracy <> 'exact' OR p.birth_date > ? THEN 'غير معروف' WHEN TIMESTAMPDIFF(YEAR,p.birth_date,?) < 18 THEN 'أقل من 18' WHEN TIMESTAMPDIFF(YEAR,p.birth_date,?) < 40 THEN '18–39' WHEN TIMESTAMPDIFF(YEAR,p.birth_date,?) < 60 THEN '40–59' ELSE '60 فأكثر' END";
                $sections[] = $this->section((clone $v)->join('patients as p', 'p.id', '=', 'v.patient_id'), $age, 'age', 'الفئات العمرية', 'العمر في آخر يوم من الشهر، من ميلاد دقيق فقط؛ العدد الثاني هو الزيارات.', [$end, $end, $end, $end]);
                foreach ([['diagnoses', 'visit_diagnoses', 'diagnosis_id', 'diagnosed_on', 'تشخيصات الزيارات'], ['services', 'visit_services', 'service_id', 'performed_on', 'خدمات الزيارات'], ['procedures', 'visit_procedures', 'procedure_id', 'performed_on', 'إجراءات الزيارات']] as [$directory, $table, $foreign, $date, $title]) {
                    $q = $this->visits($f)->where('v.status', 'complete')->join($table.' as e', 'e.visit_id', '=', 'v.id')->where('e.facility_id', $f['id'])->whereNull('e.voided_at')
                        ->whereBetween('e.'.$date, [$start, $end])->join($directory.' as g', 'g.id', '=', 'e.'.$foreign);
                    $sections[] = $this->section($q, 'g.name_ar', $directory, $title, 'وقائع غير ملغاة مرتبطة بزيارة مكتملة، بتاريخ الواقعة الفعلي داخل الشهر؛ كل صف محفوظ واقعة واحدة، وليس مجموع الكميات.', [], 'g.id');
                }
                foreach ($sections as $section) {
                    $outputRows += count($section['rows']);
                }
                if ($outputRows > 1000) {
                    $this->limit();
                }
                $months[] = ['month' => $month->format('Y-m'), 'starts_on' => $start, 'ends_on' => $end, 'sections' => $sections];
                $month = $month->addMonth();
            }

            return ['title' => 'الإحصاءات المجهلة', 'facility' => ['name_ar' => $f['name_ar'], 'timezone' => $f['timezone']], 'filters' => $filters,
                'privacy' => ['minimum_patients' => self::MINIMUM, 'policy' => self::POLICY], 'months' => $months,
                'occupancy' => ['value' => null, 'reason' => 'غير متاح: لا يوجد سجل موثوق للأسرة وأيام الإقامة الفعلية لحساب الإشغال.']];
        });
    }

    private function section(Builder $q, string $label, string $key, string $title, string $definition, array $bindings = [], ?string $identity = null): array
    {
        if ((clone $q)->count() > 100000) {
            $this->limit();
        }
        $q->selectRaw($label.' AS category, v.patient_id AS subject, COUNT(*) AS events', $bindings)->groupBy('category', 'v.patient_id');
        if ($identity) {
            $q->selectRaw($identity.' AS category_key')->groupBy('category_key');
        }
        $records = $q->orderBy('category')->orderBy($identity ? 'category_key' : 'category')->get();
        $groups = [];
        $all = [];
        $events = 0;
        foreach ($records as $record) {
            $name = (string) $record->category;
            $groupKey = $identity ? (string) ($record->category_key ?? 'unknown') : $name;
            $groups[$groupKey] ??= ['label' => $name, 'subjects' => [], 'events' => 0];
            $groups[$groupKey]['subjects'][$record->subject] = true;
            $groups[$groupKey]['events'] += (int) $record->events;
            $all[$record->subject] = true;
            $events += (int) $record->events;
        }
        $base = ['key' => $key, 'title' => $title, 'definition' => $definition];
        if (count($all) > 0 && count($all) < self::MINIMUM) {
            return $base + ['suppressed' => true, 'patients' => null, 'events' => null, 'rows' => []];
        }
        $small = ['label' => 'فئات مجمّعة لحماية الخصوصية', 'subjects' => [], 'events' => 0];
        $visible = [];
        foreach ($groups as $group) {
            if (count($group['subjects']) < self::MINIMUM) {
                $small['subjects'] += $group['subjects'];
                $small['events'] += $group['events'];
            } else {
                $visible[] = $group;
            }
        }
        // Complementary pooling keeps a sub-threshold residual from being
        // reconstructed by subtracting visible rows from a released total.
        usort($visible, fn ($a, $b) => count($a['subjects']) <=> count($b['subjects']) ?: strcmp($a['label'], $b['label']));
        while ($small['subjects'] && count($small['subjects']) < self::MINIMUM && $visible) {
            $extra = array_shift($visible);
            $small['subjects'] += $extra['subjects'];
            $small['events'] += $extra['events'];
        }
        usort($visible, fn ($a, $b) => strcmp($a['label'], $b['label']));
        if ($small['subjects']) {
            $visible[] = $small;
        }

        return $base + ['suppressed' => false, 'patients' => count($all), 'events' => $events,
            'rows' => array_map(fn ($g) => ['label' => $g['label'], 'patients' => count($g['subjects']), 'events' => $g['events']], $visible)];
    }

    private function limit(): never
    {
        throw new HttpResponseException(response()->json(['error' => ['code' => 'STATISTICS_LIMIT', 'message' => 'حجم التقرير يتجاوز الحد الآمن. قلّل عدد الأشهر؛ لن تُقطع النتائج.']], 422));
    }
}
