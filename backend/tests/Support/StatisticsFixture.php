<?php

namespace Tests\Support;

use App\Models\User;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Database\Seeders\PermissionMatrixPhaseThreeSeeder;
use Database\Seeders\PermissionMatrixPhaseTwoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StatisticsFixture
{
    public static function make(): array
    {
        foreach ([PermissionMatrixPhaseOneSeeder::class, PermissionMatrixPhaseTwoSeeder::class, PermissionMatrixPhaseThreeSeeder::class] as $seeder) {
            app($seeder)->run();
        }
        $tag = Str::random(12);
        $f = DB::table('facilities')->insertGetId(['code' => 'STATS-'.$tag, 'name_ar' => 'مشفى اختبار الإحصاء', 'timezone' => 'Asia/Damascus']);
        $other = DB::table('facilities')->insertGetId(['code' => 'OTHER-'.$tag, 'name_ar' => 'منشأة أخرى', 'timezone' => 'Asia/Damascus']);
        $users = [];
        foreach (['statistics', 'hospital_admin', 'data_entry'] as $code) {
            $u = User::factory()->create();
            $role = DB::table('roles')->where('code', $code)->value('id');
            DB::table('facility_user_roles')->insert(['facility_id' => $f, 'user_id' => $u->id, 'role_id' => $role]);
            $users[$code] = $u;
        }
        $patients = [];
        $visits = [];
        $type = DB::table('staff_types')->insertGetId(['code' => 'ST-'.$tag, 'name_ar' => 'طبيب']);
        $doctor = DB::table('staff')->insertGetId(['staff_code' => 'DOC-'.$tag, 'full_name' => 'طبيب اختبار', 'search_name' => 'طبيب اختبار', 'staff_type_id' => $type]);
        for ($n = 0; $n < 11; $n++) {
            $p = DB::table('patients')->insertGetId(['patient_code' => '000SECRET-'.$tag.'-'.$n, 'first_name' => 'SECRET-NAME-'.$tag, 'family_name' => 'SECRET-FAMILY', 'search_name' => 'SECRET-NAME', 'identity_document_type' => 'unknown', 'father_name' => 'SECRET-FATHER', 'phone' => '0900999000', 'address_line' => 'SECRET-ADDRESS', 'created_by' => $users['hospital_admin']->id, 'gender' => $n < 5 ? 'male' : ($n < 10 ? 'female' : 'unknown'), 'birth_date_accuracy' => $n < 10 ? 'exact' : 'unknown', 'birth_date' => $n < 10 ? '2000-01-01' : null]);
            $patients[] = $p;
            $visits[] = DB::table('visits')->insertGetId(['visit_no' => 'SECRET-VISIT-'.$tag.'-'.$n, 'client_request_id' => (string) Str::uuid(), 'facility_id' => $f, 'patient_id' => $p, 'visit_date' => '2020-01-15', 'attending_staff_id' => $doctor, 'status' => $n < 9 ? 'complete' : 'draft', 'entered_by' => $users['hospital_admin']->id]);
        }
        $copy = (array) DB::table('visits')->where('id', $visits[0])->first();
        unset($copy['id']);
        foreach (['repeat', 'void', 'outside', 'foreign'] as $case) {
            $row = array_replace($copy, ['visit_no' => Str::uuid()->toString(), 'client_request_id' => Str::uuid()->toString()]);
            if ($case === 'void') {
                $row = array_replace($row, ['status' => 'void', 'voided_at' => now(), 'voided_by' => $users['hospital_admin']->id, 'void_reason' => 'test']);
            }
            if ($case === 'outside') {
                $row['visit_date'] = '2020-02-01';
            }
            if ($case === 'foreign') {
                $row['facility_id'] = $other;
            }
            DB::table('visits')->insert($row);
        }
        $category = DB::table('service_categories')->insertGetId(['code' => 'CAT-'.$tag, 'name_ar' => 'خدمات اختبار']);
        $serviceA = DB::table('services')->insertGetId(['code' => 'SA-'.$tag, 'name_ar' => 'خدمة شائعة', 'category_id' => $category]);
        $serviceB = DB::table('services')->insertGetId(['code' => 'SB-'.$tag, 'name_ar' => 'RARE-SERVICE-SECRET', 'category_id' => $category]);
        foreach ([0, 0, 1, 2, 3, 4, 5] as $n) {
            DB::table('visit_services')->insert(['facility_id' => $f, 'visit_id' => $visits[$n], 'service_id' => $n === 5 ? $serviceB : $serviceA, 'performed_on' => '2020-01-20', 'client_request_id' => (string) Str::uuid(), 'entered_by' => $users['hospital_admin']->id]);
        }

        return ['facility' => $f, 'other' => $other, 'users' => $users, 'patients' => $patients, 'visits' => $visits, 'tag' => $tag, 'doctor' => $doctor];
    }
}
