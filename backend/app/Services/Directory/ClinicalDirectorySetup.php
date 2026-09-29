<?php

namespace App\Services\Directory;

use Database\Seeders\BloodBankReferenceSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Operator-reviewed directory corrections, never a clinical-record backfill. */
class ClinicalDirectorySetup
{
    public const DOCTORS = [
        'RESIDENT' => ['محمود مقرش', 'سدرة كوردي', 'بتول الحكيم', 'أديل خاجو', 'سليمان سروخان', 'زين دوبا', 'سمية طبشو', 'ايمان المحمد', 'سوزان محفوض', 'سدرة حياني', 'محمد سليم', 'رؤى شيط', 'نجوى ناصر', 'عبد الله طه', 'راما البر', 'هبة حاج صالح'],
        'SPECIALIST' => ['محمد مواس', 'هماء المحمد', 'ياسمين قنينة', 'زينة زكور', 'ريما صناع', 'أوراما كورية', 'أحمد العيسى', 'اياد العريان', 'روعة سرميني', 'سامر نسطة', 'عدنان عكش'],
    ];

    public const CLINICS = ['primary' => 'العيادات الأولية الخارجية', 'blood' => 'عيادة دم', 'oncology' => 'عيادة أورام', 'thalassemia' => 'عيادة تلاسيميا', 'surgical' => 'عيادة جراحية', 'radiology' => 'قسم الأشعة'];

    public const PROCEDURES = [
        'خزعات نسجية' => ['surgical_clinic', null], 'استشارة جراحية' => ['surgical_clinic', null],
        'تغيير ضماد' => ['surgical_clinic', null], 'بزل' => ['surgical_clinic', null],
        'لطاخة' => ['surgical_clinic', null], 'خزعة نقي العظم' => ['surgical_clinic', null],
        'خزعة موجهة بالإيكو' => ['radiology', 'ultrasound'], 'خزعة موجهة بالطبقي' => ['radiology', 'ct'],
    ];

    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['أ', 'إ', 'آ'], 'ا', $name)));
    }

    public function preview(int $facility, string $starts, array $mapping = [], bool $lock = false): array
    {
        $f = DB::table('facilities')->where('id', $facility)->where('is_active', true)->first();
        if (! $f || ! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $starts) || ! strtotime($starts) || date('Y-m-d', strtotime($starts)) !== $starts || $starts > now($f->timezone)->toDateString()) {
            throw new RuntimeException('حدد منشأة فعالة وتاريخ بدء روابط صحيحًا غير مستقبلي.');
        }
        $errors = [];
        $types = DB::table('staff_types')->when($lock, fn ($q) => $q->lockForUpdate())->get()->keyBy('code');
        $staff = DB::table('staff')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $clinics = DB::table('clinics')->where('facility_id', $facility)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $procedures = DB::table('procedures')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $components = DB::table('blood_components')->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $match = function ($rows, string $name, string $field) use (&$errors) {
            $found = $rows->filter(fn ($row) => self::normalize($row->$field) === self::normalize($name))->values();
            if ($found->count() > 1) {
                $errors[] = 'اسم ملتبس: '.$name.'؛ المعرفات: '.$found->pluck('id')->implode(',');
            }

            return $found->count() === 1 ? $found[0] : null;
        };
        $plan = ['facility_id' => $facility, 'starts_on' => $starts, 'doctors' => [], 'clinics' => [], 'procedures' => [], 'components' => [], 'links' => []];
        foreach (self::DOCTORS as $code => $names) {
            $type = $types[$code] ?? null;
            if (! $type || ! $type->is_active) {
                $errors[] = "نوع مطلوب مفقود أو معطل: {$code}؛ راجع ClinicalStaffTypesSeeder والتعريف المعتمد.";
            }
            foreach ($names as $name) {
                $row = $match($staff, $name, 'full_name');
                $plan['doctors'][] = ['name' => $name, 'before' => $row, 'staff_type_id' => $type?->id, 'practice_group' => strtolower($code)];
            }
        }
        foreach (self::CLINICS as $kind => $name) {
            $row = isset($mapping[$kind]) ? $clinics->firstWhere('id', (int) $mapping[$kind]) : $match($clinics, $name, 'name_ar');
            if (isset($mapping[$kind]) && ! $row) {
                $errors[] = "خريطة العيادة $kind خارج المنشأة أو غير موجودة.";
            }
            $setting = ['primary' => 'outpatient', 'radiology' => 'radiology'][$kind] ?? ($row->care_setting ?? null);
            if ($row && in_array($kind, ['primary', 'radiology']) && $row->care_setting && $row->care_setting !== $setting) {
                $errors[] = "تصنيف مكان $name الحالي مختلف؛ راجعه صراحة من دليل العيادات.";
            }
            $target = in_array($kind, ['primary', 'radiology']) ? null : $kind;
            if ($row && $row->clinic_kind && $row->clinic_kind !== $target) {
                $errors[] = "تصنيف عيادة مختلف: {$name}؛ لا تعاد كتابة التصنيف تلقائيًا.";
            }
            $plan['clinics'][$kind] = ['name' => $name, 'before' => $row, 'clinic_kind' => $target, 'care_setting' => $setting];
        }
        if (count(array_unique(array_values($mapping))) !== count($mapping) || array_diff(array_keys($mapping), array_keys(self::CLINICS))) {
            $errors[] = 'خريطة العيادات تحتوي مفتاحًا غير معروف أو تعيينًا مكررًا.';
        }
        foreach (self::PROCEDURES as $name => [$location, $method]) {
            $row = $match($procedures, $name, 'name_ar');
            if ($row && (($row->execution_location && $row->execution_location !== $location) || ($row->guidance_method && $row->guidance_method !== $method))) {
                $errors[] = "تصنيف إجراء مختلف: $name.";
            }
            $plan['procedures'][] = ['name' => $name, 'before' => $row, 'execution_location' => $location, 'guidance_method' => $method];
        }
        foreach (BloodBankReferenceSeeder::COMPONENTS as $kind => [$code, $name, $codes, $names]) {
            $found = $components->filter(fn ($r) => $r->registration_kind === $kind || in_array($r->code, $codes, true) || in_array(self::normalize($r->name_ar), array_map(self::normalize(...), $names), true))->values();
            if ($found->count() > 1 || ($found->first()?->registration_kind && $found->first()->registration_kind !== $kind)) {
                $errors[] = "مكوّن دم ملتبس: {$kind}؛ لا دمج للمعرفات.";
            }
            $plan['components'][] = ['name' => $name, 'code' => $code, 'registration_kind' => $kind, 'before' => $found->count() === 1 ? $found[0] : null];
        }
        $plan['links'] = DB::table('clinic_staff')->whereIn('clinic_id', $clinics->pluck('id'))->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->all();
        $primary = $plan['clinics']['primary']['before'];
        $plan['proposed_links'] = [];
        foreach ($plan['doctors'] as $entry) {
            if ($entry['practice_group'] !== 'resident') {
                continue;
            }
            $doctor = $entry['before'];
            $prior = collect($plan['links'])->filter(fn ($r) => $primary && $doctor && $r->clinic_id === $primary->id && $r->staff_id === $doctor->id);
            $blocked = ($primary && (! $primary->is_active || $primary->archived_at)) || ($doctor && (! $doctor->is_active || $doctor->archived_at));
            $plan['proposed_links'][] = ['doctor' => $entry['name'], 'clinic' => self::CLINICS['primary'], 'starts_on' => $starts,
                'action' => $blocked ? 'keep_inactive_or_archived' : ($prior->isNotEmpty() ? 'preserve_existing_periods' : 'create'), 'existing' => $prior->values()->all()];
        }
        $plan['errors'] = $errors;
        $plan['fingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR));

        return $plan;
    }

    public function apply(int $facility, string $starts, array $mapping, string $expected, string $operator): array
    {
        return DB::transaction(function () use ($facility, $starts, $mapping, $expected, $operator) {
            $plan = $this->preview($facility, $starts, $mapping, true);
            if ($plan['errors'] || ! hash_equals($plan['fingerprint'], $expected)) {
                throw new RuntimeException(implode("\n", $plan['errors']) ?: 'تغيّرت المعاينة؛ أعد مراجعتها قبل التطبيق.');
            }
            $codes = app(IssuedCodes::class);
            $write = function (string $table, ?object $before, array $fields, callable $create) use ($facility, $operator) {
                $changed = ! $before || collect($fields)->contains(fn ($v, $k) => (string) $before->$k !== (string) $v);
                if (! $changed) {
                    return $before->id;
                }
                if ($before) {
                    if (property_exists($before, 'lock_version')) {
                        $fields['lock_version'] = $before->lock_version + 1;
                    }
                    DB::table($table)->where('id', $before->id)->update($fields + ['updated_at' => now()]);
                    $id = $before->id;
                } else {
                    $id = DB::table($table)->insertGetId($fields + $create() + ['created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('audit_logs')->insert(['facility_id' => $facility, 'actor_id' => null, 'entity_type' => $table, 'entity_id' => $id, 'event' => 'clinical_directory_setup', 'old_values' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null, 'new_values' => json_encode(['operator_reference' => $operator, 'fields' => $fields], JSON_UNESCAPED_UNICODE), 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);

                return $id;
            };
            $residents = [];
            foreach ($plan['doctors'] as $entry) {
                $id = $write('staff', $entry['before'], ['staff_type_id' => $entry['staff_type_id'], 'practice_group' => $entry['practice_group']], fn () => ['staff_code' => $codes->doctor(), 'full_name' => $entry['name'], 'search_name' => $entry['name'], 'is_active' => true]);
                if ($entry['practice_group'] === 'resident') {
                    $residents[] = $id;
                }
            }
            $ids = [];
            foreach ($plan['clinics'] as $kind => $entry) {
                $ids[$kind] = $write('clinics', $entry['before'], ['care_setting' => $entry['care_setting'], 'clinic_kind' => $entry['clinic_kind']], fn () => ['facility_id' => $facility, 'code' => $codes->clinic(), 'name_ar' => $entry['name'], 'is_active' => true]);
            }
            foreach ($plan['procedures'] as $entry) {
                $write('procedures', $entry['before'], ['execution_location' => $entry['execution_location'], 'guidance_method' => $entry['guidance_method']], fn () => ['code' => $codes->catalog('procedure'), 'name_ar' => $entry['name'], 'is_active' => true]);
            }
            foreach ($plan['components'] as $entry) {
                $write('blood_components', $entry['before'], ['name_ar' => $entry['name'], 'registration_kind' => $entry['registration_kind']], fn () => ['code' => $entry['code'], 'is_active' => true]);
            }
            $primary = DB::table('clinics')->find($ids['primary']);
            foreach ($residents as $id) {
                $doctor = DB::table('staff')->find($id);
                if (! $primary->is_active || $primary->archived_at || ! $doctor->is_active || $doctor->archived_at) {
                    continue;
                }
                $periods = DB::table('clinic_staff')->where('clinic_id', $primary->id)->where('staff_id', $id)->get();
                // Existing assignments, including ended periods, require explicit human management.
                if ($periods->isEmpty()) {
                    $write('clinic_staff', null, ['clinic_id' => $primary->id, 'staff_id' => $id, 'starts_on' => $starts], fn () => []);
                    DB::table('staff')->where('id', $id)->increment('lock_version');
                    DB::table('clinics')->where('id', $primary->id)->increment('lock_version');
                }
            }

            return $plan;
        }, 3);
    }
}
