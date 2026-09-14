<?php

namespace Tests\Support;

use Database\Seeders\BloodBankPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BloodBankFixture
{
    public static function make(): array
    {
        $f = CatalogFixture::make();
        app(BloodBankPermissionsSeeder::class)->run();
        $role = DB::table('global_user_roles')->where('user_id', $f['user']->id)->value('role_id');
        foreach (DB::table('permissions')->whereIn('code', array_keys(BloodBankPermissionsSeeder::PERMISSIONS))->pluck('id') as $p) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $p]);
        }
        $f['staff'] = DB::table('staff')->where('staff_code', 'CAT-'.$f['tag'])->value('id');
        $f['clinic'] = DB::table('clinics')->insertGetId(['facility_id' => $f['facility'], 'code' => 'BB-'.$f['tag'], 'name_ar' => 'عيادة بنك الدم الاختبارية']);
        DB::table('clinic_staff')->insert(['clinic_id' => $f['clinic'], 'staff_id' => $f['staff'], 'starts_on' => $f['today']]);
        $f['component'] = DB::table('blood_components')->where('code', 'CAT-'.$f['tag'])->value('id');
        $f['test'] = DB::table('screening_tests')->insertGetId(['code' => 'SYNTHETIC-'.$f['tag'], 'name_ar' => 'طريقة اختبار اصطناعية', 'blood_bank_analyte' => 'HCV']);
        $role = DB::table('facility_user_roles')->where('user_id', $f['viewer']->id)->value('role_id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'blood_bank.view')->value('id')]);
        config(['clinics.doctor_staff_types' => ['CAT-'.$f['tag']]]);

        return $f;
    }

    public static function profile(array $f, string $kind = 'donor'): array
    {
        return ['facility_id' => $f['facility'], 'request_id' => (string) Str::uuid(), 'kind' => $kind, 'person_mode' => 'direct',
            'first_name' => 'أحمد', 'family_name' => 'محمد', 'gender' => 'unknown', 'birth_date_accuracy' => 'unknown', 'displacement_status' => 'unknown',
            'clinic_id' => $f['clinic'], 'responsible_staff_id' => $f['staff'], 'blood_group' => null, 'rh' => null,
            'screenings' => array_map(fn ($a) => ['analyte' => $a, 'screening_test_id' => null, 'status' => 'not_requested', 'result' => null], ['HBsAg', 'HCV', 'HIV'])];
    }
}
