<?php

namespace Tests\Support;

use Database\Seeders\DossierDiagnosisReferenceSeeder;
use Database\Seeders\DossierWorkflowPermissionsSeeder;
use Illuminate\Support\Facades\DB;

class DossierWorkflowFixture
{
    public static function make(): array
    {
        $f = DossierFixture::make();
        DB::table('patients')->where('id', $f['patients'][1])->update(['family_name' => 'محمد '.$f['tag'], 'search_name' => 'أحمد محمد '.$f['tag']]);
        $f['search_patient_name'] = 'أحمد محمد '.$f['tag'];
        $f['search_patient_code'] = DB::table('patients')->where('id', $f['patients'][1])->value('patient_code');
        $f['governorate'] = DB::table('governorates')->insertGetId(['code' => 'WIZ-'.$f['tag'], 'name_ar' => 'محافظة اختبار '.$f['tag'], 'country_code' => 'SY']);
        $f['city'] = DB::table('cities')->insertGetId(['governorate_id' => $f['governorate'], 'name_ar' => 'مدينة اختبار '.$f['tag']]);
        app(DossierWorkflowPermissionsSeeder::class)->run();
        app(DossierDiagnosisReferenceSeeder::class)->run();
        foreach (array_keys(DossierWorkflowPermissionsSeeder::CODES) as $code) {
            DB::table('role_permissions')->insert(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        DB::table('global_user_roles')->insert(['user_id' => $f['user']->id, 'role_id' => $f['dossier_role']]);
        DB::table('staff_types')->insertOrIgnore(['code' => 'DWF-DOCTOR', 'name_ar' => 'طبيب اختبار المعالج']);
        $type = DB::table('staff_types')->where('code', 'DWF-DOCTOR')->value('id');
        config(['clinics.doctor_staff_types' => ['DWF-DOCTOR']]);
        $f['workflow_doctors'] = [];
        foreach ($f['clinics'] as $index => $clinic) {
            $id = DB::table('staff')->where('staff_code', 'DOS-'.($index + 1).'-'.$f['tag'])->value('id');
            $f['workflow_doctors'][] = $id;
            DB::table('staff')->where('id', $id)->update(['staff_type_id' => $type]);
            DB::table('clinic_staff')->insert(['clinic_id' => $clinic, 'staff_id' => $id, 'starts_on' => '1990-01-01']);
        }
        $f['visit_type'] = DB::table('visits')->where('id', $f['latest_visit'])->value('visit_type_id');
        $f['diagnosis'] = DB::table('diagnoses')->where('code', 'DOS-DX-01')->value('id');

        return $f;
    }
}
