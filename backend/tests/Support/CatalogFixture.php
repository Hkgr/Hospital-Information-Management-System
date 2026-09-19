<?php

namespace Tests\Support;

use App\Models\User;
use Database\Seeders\CatalogPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogFixture
{
    /** Synthetic medical events are test fixtures only; no treatment-entry API is introduced. */
    public static function make(): array
    {
        $tag = Str::lower(Str::random(8));
        $user = User::factory()->create(['username' => 'catalog-'.$tag]);
        $viewer = User::factory()->create(['username' => 'catalog-viewer-'.$tag]);
        $facility = DB::table('facilities')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'منشأة اختبار الخدمات', 'timezone' => 'Asia/Damascus']);
        $other = DB::table('facilities')->insertGetId(['code' => 'OTHER-'.$tag, 'name_ar' => 'منشأة محجوبة', 'timezone' => 'Asia/Damascus']);
        app(CatalogPermissionsSeeder::class)->run();
        foreach ([$user, $viewer] as $account) {
            $role = DB::table('roles')->insertGetId(['code' => 'CAT-'.$account->id.'-'.$tag, 'name_ar' => 'اختبار']);
            foreach (array_keys(CatalogPermissionsSeeder::PERMISSIONS) as $code) {
                if ($account->is($viewer) && ! in_array($code, ['catalog.view', 'catalog.export'], true)) {
                    continue;
                }
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
            }
            DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $account->id, 'role_id' => $role]);
            if ($account->is($user)) {
                DB::table('global_user_roles')->insert(['user_id' => $account->id, 'role_id' => $role]);
            }
        }
        $category = DB::table('service_categories')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'فئة اختبارية']);
        $medicationCategory = DB::table('medication_categories')->insertGetId(['code' => 'MED-'.$tag, 'name_ar' => 'فئة دواء اختبار']);
        $funding = DB::table('funding_sources')->insertGetId(['code' => 'FUND-'.$tag, 'name_ar' => 'جهة تمويل اختبار']);
        $type = DB::table('staff_types')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'طبيب اختبار']);
        $staff = DB::table('staff')->insertGetId(['staff_code' => 'CAT-'.$tag, 'full_name' => 'طبيب اختبار', 'search_name' => 'طبيب اختبار', 'staff_type_id' => $type]);
        $visitType = DB::table('visit_types')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'زيارة اختبار']);
        $component = DB::table('blood_components')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'مكون اختبار']);
        $today = now('Asia/Damascus')->toDateString();
        $periods = [];
        foreach ([$facility, $other] as $f) {
            $periods[$f] = DB::table('reporting_periods')->insertGetId(['facility_id' => $f, 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString()]);
        }
        $items = [];
        foreach (['service' => 'services', 'procedure' => 'procedures', 'medication' => 'medications'] as $kind => $table) {
            for ($n = 1; $n <= 2; $n++) {
                $items[$kind][$n] = DB::table($table)->insertGetId(['code' => $tag.($kind === 'medication' ? '-M0' : '-00').$n, 'name_ar' => ($kind === 'service' ? 'خدمة' : ($kind === 'procedure' ? 'إجراء' : 'دواء')).' اختبار '.$n, 'description' => 'وصف اختباري', 'lock_version' => 1, ...($kind === 'service' ? ['category_id' => $category] : ($kind === 'medication' ? ['category_id' => $medicationCategory] : []))]);
            }
        }
        $patients = [];
        for ($n = 1; $n <= 10; $n++) {
            $patients[$n] = DB::table('patients')->insertGetId(['patient_code' => $tag.'-P'.$n, 'first_name' => 'مستفيد', 'family_name' => 'اختبار '.$n, 'search_name' => 'مستفيد اختبار '.$n, 'identity_document_type' => 'unknown', 'created_by' => $user->id]);
        }
        $void = ['voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => 'اختبار إلغاء'];
        $visit = function (int $patient, int $f, string $status = 'complete') use ($visitType, $periods, $staff, $today, $user, $void) {
            return DB::table('visits')->insertGetId(['visit_no' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'facility_id' => $f, 'patient_id' => $patient, 'reporting_period_id' => $periods[$f],
                'visit_date' => $today, 'visit_type_id' => $visitType, 'attending_staff_id' => $staff, 'status' => $status, 'entered_by' => $user->id, ...($status === 'void' ? $void : [])]);
        };
        $event = function (int $v, int $f, int $n = 1, bool $cancelled = false, bool $future = false) use ($items, $periods, $today, $user, $staff, $void) {
            foreach (['service', 'procedure'] as $kind) {
                DB::table('visit_'.$kind.'s')->insert(['visit_id' => $v, 'facility_id' => $f, 'reporting_period_id' => $periods[$f], $kind.'_id' => $items[$kind][$n], 'performed_on' => $future ? now()->addDays(3)->toDateString() : $today,
                    'client_request_id' => (string) Str::uuid(), 'entered_by' => $user->id, ...($kind === 'procedure' ? ['specialist_id' => $staff] : []), ...($cancelled ? $void : [])]);
            }
            DB::table('visit_medications')->insert(['visit_id' => $v, 'facility_id' => $f, 'reporting_period_id' => $periods[$f], 'medication_id' => $items['medication'][$n],
                'medication_name_snapshot' => 'دواء اختبار '.$n, 'dispensed_on' => $future ? now()->addDays(3)->toDateString() : $today,
                'client_request_id' => (string) Str::uuid(), 'entered_by' => $user->id, ...($cancelled ? $void : [])]);
        };
        $v1 = $visit($patients[1], $facility);
        $event($v1, $facility);
        $event($v1, $facility);
        $event($v1, $facility, 2);
        $event($visit($patients[2], $facility), $facility);
        $draft = $visit($patients[3], $facility, 'draft');
        $event($draft, $facility);
        $event($visit($patients[4], $facility), $facility, 1, true);
        $event($visit($patients[5], $facility, 'void'), $facility);
        $event($visit($patients[6], $facility), $facility, 1, false, true);
        $event($visit($patients[7], $other), $other);
        foreach ([['p' => 1], ['p' => 8], ['p' => 9, 'void' => true], ['p' => null], ['p' => 3, 'visit' => $draft], ['p' => 10, 'future' => true]] as $spec) {
            $blood = DB::table('blood_transfusions')->insertGetId(['facility_id' => $facility, 'reporting_period_id' => $periods[$facility], 'patient_id' => $spec['p'] ? $patients[$spec['p']] : null,
                'external_recipient_name' => $spec['p'] ? null : 'متلقٍ خارجي', 'visit_id' => $spec['visit'] ?? null, 'blood_component_id' => $component, 'units' => '1.0000', 'transfused_on' => $today, 'entered_by' => $user->id, ...(! empty($spec['void']) ? $void : [])]);
            DB::table('blood_recipient_procedures')->insert(['blood_transfusion_id' => $blood, 'procedure_id' => $items['procedure'][1], 'performed_on' => empty($spec['future']) ? $today : now()->addDays(3)->toDateString(), 'entered_by' => $user->id]);
        }
        $dose = function (int $patient, int $f, array $spec = []) use ($items, $periods, $today, $user, $visit, $void, $funding) {
            $visitId = $spec['visit'] ?? $visit($patient, $f, $spec['status'] ?? 'complete');
            $session = DB::table('dose_sessions')->insertGetId(['visit_id' => $visitId, 'facility_id' => $f, 'reporting_period_id' => $periods[$f],
                'administered_on' => empty($spec['future']) ? $today : now()->addDays(3)->toDateString(), 'client_request_id' => (string) Str::uuid(),
                'entered_by' => $user->id, ...(! empty($spec['void']) ? $void : [])]);
            DB::table('dose_session_items')->insert(['dose_session_id' => $session, 'medication_id' => $items['medication'][1], 'medication_name_snapshot' => 'دواء اختبار 1',
                'funding_source_id' => $funding, 'entered_by' => $user->id]);

            return $session;
        };
        $dose($patients[1], $facility, ['visit' => $v1]);
        $dose($patients[8], $facility);
        $dose($patients[9], $facility, ['void' => true]);
        $dose($patients[10], $facility, ['future' => true]);

        return compact('tag', 'user', 'viewer', 'facility', 'other', 'category', 'medicationCategory', 'items', 'patients', 'today');
    }

    public static function token(User $user, string $name = 'catalog-test'): string
    {
        $plain = $user->createToken($name, ['api'])->plainTextToken;
        $user->tokens()->update(['last_used_at' => now()]);

        return $plain;
    }
}
