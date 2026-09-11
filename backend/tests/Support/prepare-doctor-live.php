<?php

// Creates only synthetic fixtures in the explicitly confirmed MySQL test database.
// Credentials stay in ignored local storage; never print them or commit the file.
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
$fixture = DB::transaction(function () {
    $suffix = Str::lower(Str::random(6));
    $password = Str::random(32);
    $user = User::factory()->create(['username' => 'doctor-live-'.$suffix, 'name' => 'مستخدم التكامل الاختباري', 'password' => $password, 'must_change_password' => false]);
    $facility = DB::table('facilities')->insertGetId(['code' => 'LIVE-'.$suffix, 'name_ar' => 'منشأة التكامل الاختبارية', 'timezone' => 'Asia/Damascus']);
    $role = DB::table('roles')->insertGetId(['code' => 'LIVE-'.$suffix, 'name_ar' => 'دور تكامل اختباري']);
    app(ClinicPermissionsSeeder::class)->run();
    app(DoctorPermissionsSeeder::class)->run();
    foreach (DB::table('permissions')->where(fn ($q) => $q->whereLike('code', 'clinics.%')->orWhereLike('code', 'doctors.%'))->where('is_active', true)->pluck('id') as $permission) {
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
    }
    DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role]);
    DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
    $type = DB::table('staff_types')->where('code', 'LIVE_DOCTOR')->value('id') ?? DB::table('staff_types')->insertGetId(['code' => 'LIVE_DOCTOR', 'name_ar' => 'طبيب اختبار حي']);
    $specialty = DB::table('specialties')->where('code', 'LIVE_SPECIALTY')->value('id') ?? DB::table('specialties')->insertGetId(['code' => 'LIVE_SPECIALTY', 'name_ar' => 'تخصص اختبار حي']);
    $clinics = [];
    foreach ([1, 2] as $index) {
        $clinics[] = DB::table('clinics')->insertGetId(['facility_id' => $facility, 'code' => 'LIVE-C'.$index, 'name_ar' => 'عيادة التكامل '.$index]);
    }

    return ['username' => $user->username, 'password' => $password, 'facility_id' => $facility, 'staff_type_id' => $type, 'specialty_id' => $specialty, 'clinics' => $clinics, 'suffix' => $suffix];
});
file_put_contents(storage_path('framework/testing/doctor-live.json'), json_encode($fixture, JSON_THROW_ON_ERROR));
echo "Synthetic fixture ready in confirmed mysql/hospital_testing. Credentials stored locally, not printed.\n";
