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
        // Faker uniqueness is process-local; live fixtures retain earlier users.
        $user = User::factory()->create(['username' => 'catalog-'.$tag, 'email' => 'catalog-'.$tag.'@example.test']);
        $viewer = User::factory()->create(['username' => 'catalog-viewer-'.$tag, 'email' => 'catalog-viewer-'.$tag.'@example.test']);
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
        foreach (['service' => 'services', 'procedure' => 'procedures'] as $kind => $table) {
            for ($n = 1; $n <= 2; $n++) {
                $items[$kind][$n] = DB::table($table)->insertGetId(['code' => $tag.'-00'.$n, 'name_ar' => ($kind === 'service' ? 'خدمة' : 'إجراء').' اختبار '.$n, 'description' => 'وصف اختباري', ...($kind === 'service' ? ['category_id' => $category] : [])]);
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

        return compact('tag', 'user', 'viewer', 'facility', 'other', 'category', 'items', 'patients', 'today');
    }
}
