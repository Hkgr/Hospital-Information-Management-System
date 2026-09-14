<?php

namespace Tests\Support;

use Database\Seeders\DossierPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DossierFixture
{
    public static function make(): array
    {
        $f = CatalogFixture::make();
        app(DossierPermissionsSeeder::class)->run();
        $role = DB::table('roles')->insertGetId(['code' => 'DOS-'.$f['tag'], 'name_ar' => 'قارئ إضبارات اختباري']);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'dossiers.view')->value('id')]);
        DB::table('facility_user_roles')->insert(['user_id' => $f['user']->id, 'facility_id' => $f['facility'], 'role_id' => $role]);
        $f['dossier_role'] = $role;
        $f['dossiers'] = [];
        foreach ($f['patients'] as $n => $patient) {
            $f['dossiers'][] = DB::table('patient_dossiers')->insertGetId(['facility_id' => $f['facility'], 'patient_id' => $patient, 'code' => 'DOS-'.$f['tag'].'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'opening_date' => '2010-02-03', 'status' => 'active', 'entered_by' => $f['user']->id]);
        }
        $id = $f['dossiers'][0];
        DB::table('patient_dossiers')->where('id', $id)->update(['is_oncology' => true, 'disability_text' => 'صعوبة حركة؛ ضعف سمع — بيانات اصطناعية', 'clinical_history' => 'قصة مرضية اصطناعية للمراجعة فقط', 'previous_examinations' => 'فحوص سابقة موثقة في عينة الاختبار', 'medication_source' => 'other_organization', 'other_organization' => 'جهة اختبارية']);
        foreach (['history' => ['medical', 'family'], 'treatment' => ['surgical', 'chemotherapy']] as $group => $codes) {
            foreach ($codes as $code) {
                DB::table('dossier_oncology_selections')->insert(['dossier_id' => $id, 'facility_id' => $f['facility'], 'selection_group' => $group, 'code' => $code, 'entered_by' => $f['user']->id]);
            }
        }
        DB::table('patients')->where('id', $f['patients'][1])->update(['first_name' => 'أحمد', 'family_name' => 'محمد الطويل لاختبار عرض الأسماء', 'search_name' => 'أحمد محمد الطويل']);
        $base = DB::table('visits')->where('patient_id', $f['patients'][1])->where('facility_id', $f['facility'])->first();
        DB::table('visits')->where('id', $base->id)->update(['dossier_id' => $id, 'created_at' => '2090-01-01']);
        $copy = (array) $base;
        unset($copy['id']);
        $copy['dossier_id'] = $id;
        $copy['visit_date'] = now()->subDays(10)->toDateString();
        $copy['created_at'] = '2099-01-01';
        $copy['visit_no'] = 'OLD-'.Str::uuid();
        $copy['client_request_id'] = (string) Str::uuid();
        $f['old_visit'] = DB::table('visits')->insertGetId($copy);
        $copy['visit_date'] = $f['today'];
        $copy['created_at'] = '2000-01-01';
        $copy['visit_no'] = 'LATEST-'.$f['tag'];
        $copy['client_request_id'] = (string) Str::uuid();
        $f['latest_visit'] = DB::table('visits')->insertGetId($copy);
        $f['clinics'] = [];
        for ($n = 1; $n <= 2; $n++) {
            $clinic = DB::table('clinics')->insertGetId(['facility_id' => $f['facility'], 'code' => 'DOS-'.$n.'-'.$f['tag'], 'name_ar' => 'عيادة التشخيص '.$n]);
            $f['clinics'][] = $clinic;
            $staff = DB::table('staff')->insertGetId(['staff_code' => 'DOS-'.$n.'-'.$f['tag'], 'full_name' => 'الطبيب المسؤول '.$n, 'search_name' => 'الطبيب المسؤول '.$n, 'staff_type_id' => DB::table('staff')->where('id', $base->attending_staff_id)->value('staff_type_id')]);
            $diagnosis = DB::table('diagnoses')->insertGetId(['code' => 'DX-'.$n.'-'.$f['tag'], 'name_ar' => 'تشخيص اختباري '.$n.' طويل للتأكد من عرض جميع التشخيصات دون تكرار الإضبارة']);
            DB::table('visit_diagnoses')->insert(['visit_id' => $f['latest_visit'], 'facility_id' => $f['facility'], 'reporting_period_id' => $base->reporting_period_id, 'diagnosed_on' => $n === 1 ? null : $f['today'], 'diagnosis_id' => $diagnosis, 'diagnosing_staff_id' => $staff, 'clinic_id' => $clinic, 'client_request_id' => (string) Str::uuid(), 'entered_by' => $f['user']->id]);
        }
        foreach (['visit_services', 'visit_procedures'] as $table) {
            $row = (array) DB::table($table)->where('visit_id', $base->id)->first();
            unset($row['id']);
            $row['visit_id'] = $f['latest_visit'];
            $row['client_request_id'] = (string) Str::uuid();
            DB::table($table)->insert($row);
        }
        $fund = DB::table('funding_sources')->insertGetId(['code' => 'DOS-'.$f['tag'], 'name_ar' => 'مصدر اختباري']);
        $med = DB::table('medications')->insertGetId(['code' => 'DOS-'.$f['tag'], 'name_ar' => 'دواء اختباري']);
        $common = ['visit_id' => $f['latest_visit'], 'facility_id' => $f['facility'], 'reporting_period_id' => $base->reporting_period_id, 'client_request_id' => (string) Str::uuid(), 'entered_by' => $f['user']->id];
        DB::table('visit_medications')->insert($common + ['dispensed_on' => $f['today'], 'medication_id' => $med, 'medication_name_snapshot' => 'دواء مصروف اصطناعي', 'funding_source_id' => $fund, 'quantity' => 2, 'quantity_unit' => 'قرص', 'dose_text' => 'تعليمات اختبارية فقط']);
        $session = DB::table('dose_sessions')->insertGetId($common + ['administered_on' => $f['today']]);
        DB::table('dose_session_items')->insert(['dose_session_id' => $session, 'medication_id' => $med, 'medication_name_snapshot' => 'دواء جلسة اصطناعي', 'funding_source_id' => $fund, 'entered_by' => $f['user']->id]);
        $result = DB::table('visit_results')->insertGetId(['code' => 'DOS-'.$f['tag'], 'name_ar' => 'نتيجة مسجلة اختبارية', 'result_group' => 'other']);
        DB::table('visit_outcomes')->insert($common + ['outcome_on' => $f['today'], 'result_id' => $result, 'decided_by' => $base->attending_staff_id]);

        return $f;
    }
}
