<?php

// Opt-in synthetic workload. Never run against a development/production database.
use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\ClinicPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/directory-performance.json');
app('files')->ensureDirectoryExists(dirname($path));
if (is_file($path)) {
    throw new RuntimeException('A workload manifest already exists. Keep it for matched before/after runs.');
}
$started = microtime(true);
$fixture = DB::transaction(function () {
    $prefix = 'PERF-'.Str::upper(Str::random(6));
    app(ClinicPermissionsSeeder::class)->run();
    app(DoctorPermissionsSeeder::class)->run();
    $role = DB::table('roles')->insertGetId(['code' => $prefix, 'name_ar' => 'دور أداء اصطناعي']);
    foreach (DB::table('permissions')->where(fn ($q) => $q->whereLike('code', 'clinics.%')->orWhereLike('code', 'doctors.%'))->where('is_active', true)->pluck('id') as $permission) {
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
    }
    $facilities = [];
    foreach ([1, 2] as $n) {
        $facilities[] = DB::table('facilities')->insertGetId(['code' => $prefix.'-'.$n, 'name_ar' => 'منشأة أداء اصطناعية '.$n, 'timezone' => 'Asia/Damascus']);
    }
    $users = [];
    foreach (range(1, 5) as $n) {
        $password = Str::random(32);
        $user = User::factory()->create(['username' => strtolower($prefix).'-'.$n, 'name' => 'موظف أداء اصطناعي '.$n, 'password' => $password, 'must_change_password' => false]);
        foreach ($facilities as $facility) {
            DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role]);
        }
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $users[] = ['id' => $user->id, 'username' => $user->username, 'password' => $password, 'token' => $user->createToken('synthetic-performance', ['api'])->plainTextToken];
    }
    $type = DB::table('staff_types')->where('code', 'PERF_DOCTOR')->value('id') ?? DB::table('staff_types')->insertGetId(['code' => 'PERF_DOCTOR', 'name_ar' => 'طبيب أداء اصطناعي']);
    $specialty = DB::table('specialties')->insertGetId(['code' => $prefix, 'name_ar' => 'تخصص أداء اصطناعي']);
    $visitType = DB::table('visit_types')->insertGetId(['code' => $prefix, 'name_ar' => 'زيارة أداء اصطناعية']);
    $procedure = DB::table('procedures')->insertGetId(['code' => $prefix, 'name_ar' => 'إجراء أداء اصطناعي']);
    $doctors = [];
    foreach (range(1, 400) as $n) {
        $doctors[] = DB::table('staff')->insertGetId(['staff_code' => $prefix.'-D'.str_pad($n, 4, '0', STR_PAD_LEFT), 'full_name' => 'طبيب اختباري '.$n, 'search_name' => 'طبيب اختباري '.$n, 'staff_type_id' => $type, 'description' => 'بيانات اصطناعية لقياس الأداء فقط.']);
    }
    $clinics = [];
    foreach (range(1, 80) as $n) {
        $clinics[] = DB::table('clinics')->insertGetId(['facility_id' => $facilities[$n > 64 ? 1 : 0], 'code' => $prefix.'-C'.str_pad($n, 3, '0', STR_PAD_LEFT), 'name_ar' => 'عيادة اختبارية '.$n, 'specialty_id' => $specialty]);
    }
    foreach ($doctors as $index => $doctor) {
        DB::table('staff_specialties')->insert(['staff_id' => $doctor, 'specialty_id' => $specialty]);
        DB::table('clinic_staff')->insert(['staff_id' => $doctor, 'clinic_id' => $clinics[$index % 80], 'starts_on' => '2025-09-01']);
    }
    // 12 months × 5000 distinct patients; three repeat visits and one procedure each.
    foreach (range(0, 11) as $month) {
        $date = new DateTimeImmutable('2025-09-01 +'.$month.' months');
        $periods = [];
        foreach ($facilities as $facility) {
            $periods[$facility] = DB::table('reporting_periods')->insertGetId(['facility_id' => $facility, 'starts_on' => $date->format('Y-m-01'), 'ends_on' => $date->format('Y-m-t')]);
        }
        foreach (range(0, 4) as $batch) {
            $patients = [];
            foreach (range(0, 999) as $index) {
                $n = $month * 5000 + $batch * 1000 + $index;
                $patients[] = ['patient_code' => $prefix.'-P'.$n, 'first_name' => 'مريض اصطناعي', 'family_name' => (string) $n, 'search_name' => 'مريض اصطناعي '.$n, 'identity_document_type' => 'synthetic', 'created_by' => $users[0]['id']];
            }
            DB::table('patients')->insert($patients);
            $ids = DB::table('patients')->whereIn('patient_code', array_column($patients, 'patient_code'))->pluck('id', 'patient_code');
            $visits = [];
            foreach ($patients as $index => $patient) {
                $n = $month * 5000 + $batch * 1000 + $index;
                $facility = $facilities[$n % 80 >= 64 ? 1 : 0];
                foreach ([5, 12, 25] as $day) {
                    $visits[] = ['visit_no' => $prefix.'-V'.$n.'-'.$day, 'facility_id' => $facility, 'patient_id' => $ids[$patient['patient_code']], 'reporting_period_id' => $periods[$facility], 'visit_date' => $date->format('Y-m-').$day, 'visit_type_id' => $visitType, 'clinic_id' => $clinics[$n % 80], 'attending_staff_id' => $doctors[$n % 400], 'status' => $n % 20 === 0 && $day === 25 ? 'draft' : 'complete', 'client_request_id' => $prefix.'-'.$n.'-'.$day, 'entered_by' => $users[0]['id']];
                }
            }
            foreach (array_chunk($visits, 500) as $chunk) {
                DB::table('visits')->insert($chunk);
            }
            $procedures = [];
            $visitIds = DB::table('visits')->whereIn('visit_no', array_column($visits, 'visit_no'))->pluck('id', 'visit_no');
            foreach ($visits as $index => $visit) {
                if ($index % 3 !== 0) {
                    continue;
                }
                $procedures[] = ['visit_id' => $visitIds[$visit['visit_no']], 'facility_id' => $visit['facility_id'], 'reporting_period_id' => $visit['reporting_period_id'], 'performed_on' => $visit['visit_date'], 'procedure_id' => $procedure, 'specialist_id' => $visit['attending_staff_id'], 'client_request_id' => $visit['client_request_id'], 'entered_by' => $users[0]['id']];
            }
            DB::table('visit_procedures')->insert($procedures);
        }
        echo 'Synthetic month '.($month + 1)."/12 ready.\n";
    }

    return compact('prefix', 'users', 'facilities', 'doctors', 'clinics', 'type', 'specialty') + ['patients' => 60000, 'visits' => 180000, 'procedures' => 60000];
});
if (file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR)) === false) {
    throw new RuntimeException('Synthetic rows were inserted but the local manifest could not be written. Do not repeat preparation before inspecting the testing database.');
}
echo 'Synthetic workload ready in '.round(microtime(true) - $started, 2)."s. Credentials remain in ignored storage.\n";
