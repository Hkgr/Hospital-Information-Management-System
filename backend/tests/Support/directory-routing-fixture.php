<?php

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
$path = storage_path('framework/testing/directory-routing.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('A routing fixture already exists; clean up its tokens before starting another run.');
    }
    $fixture = DB::transaction(function () {
        $suffix = Str::lower(Str::random(10));
        $user = User::factory()->create(['username' => 'routing-'.$suffix, 'name' => 'اختبار تحويلات الدليل']);
        $denied = User::factory()->create(['username' => 'routing-denied-'.$suffix]);
        $facility = DB::table('facilities')->insertGetId(['code' => 'ROUTING-'.$suffix, 'name_ar' => 'منشأة اختبار التحويلات', 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->insertGetId(['code' => 'ROUTING-'.$suffix, 'name_ar' => 'دور اختبار التحويلات']);
        app(ClinicPermissionsSeeder::class)->run();
        app(DoctorPermissionsSeeder::class)->run();
        foreach (array_merge(['clinics.view', 'clinics.create', 'clinics.update', 'clinics.delete', 'clinics.export'], array_keys(DoctorPermissionsSeeder::PERMISSIONS)) as $code) {
            $permission = DB::table('permissions')->where('code', $code)->where('is_active', true)->value('id');
            if (! $permission) {
                throw new RuntimeException('Required test permission is disabled; no existing permission was reactivated.');
            }
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role]);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $type = DB::table('staff_types')->where('code', 'ROUTING_DOCTOR')->value('id') ?? DB::table('staff_types')->insertGetId(['code' => 'ROUTING_DOCTOR', 'name_ar' => 'طبيب اختبار التحويلات']);
        $specialty = DB::table('specialties')->insertGetId(['code' => 'ROUTING-'.$suffix, 'name_ar' => 'تخصص اختباري']);

        return ['suffix' => $suffix, 'facility_id' => $facility, 'staff_type_id' => $type, 'specialty_id' => $specialty,
            'today' => now('Asia/Damascus')->toDateString(), 'users' => [$user->id, $denied->id],
            'token' => $user->createToken('routing-integration', ['api'])->plainTextToken,
            'denied_token' => $denied->createToken('routing-integration', ['api'])->plainTextToken];
    });
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    echo "Synthetic routing fixture prepared; credentials are local and not printed.\n";
} elseif ($mode === 'verify') {
    $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $results = json_decode(file_get_contents(storage_path('framework/testing/directory-routing-result.json')), true, 512, JSON_THROW_ON_ERROR);
    if (count($results) !== 2) {
        throw new RuntimeException('Both HTTP workflows must complete before persistence verification.');
    }
    foreach ($results as $result) {
        $doctor = DB::table('staff')->find($result['doctor_id']);
        $clinic = DB::table('clinics')->where('facility_id', $fixture['facility_id'])->find($result['clinic_id']);
        $periods = DB::table('clinic_staff')->where('staff_id', $doctor?->id)->where('clinic_id', $clinic?->id)->get();
        $doctorTarget = $result['kind'] === 'doctors';
        $target = $doctorTarget ? $doctor : $clinic;
        $entity = $doctorTarget ? 'doctor' : 'clinic';
        if (! $doctor || ! $clinic || ! $doctor->is_active || ! $clinic->is_active || $doctor->archived_at !== null || $clinic->archived_at !== null
            || ! str_contains($doctor->staff_code, $fixture['suffix']) || ! str_contains($clinic->code, $fixture['suffix'])
            || (int) $doctor->lock_version !== ($doctorTarget ? 5 : 3) || (int) $clinic->lock_version !== ($doctorTarget ? 2 : 4)
            || $periods->count() !== 1 || $periods[0]->starts_on !== $fixture['today'] || $periods[0]->ends_on !== $fixture['today']
            || DB::table($doctorTarget ? 'staff' : 'clinics')->where('id', $result['deleted_id'])->exists()) {
            throw new RuntimeException('Persisted rows do not match the routing lifecycle contract.');
        }
        foreach (['archived', 'restored', 'reactivated'] as $event) {
            if (DB::table('audit_logs')->where('entity_type', $entity)->where('entity_id', $target->id)->where('event', $event)->count() !== 1) {
                throw new RuntimeException('Expected one persisted lifecycle audit event.');
            }
        }
    }
    echo "PASS persisted MySQL: deleted records absent, both endpoints active/unarchived, exact versions, closed periods unchanged, lifecycle audit events once.\n";
} elseif ($mode === 'cleanup') {
    if (is_file($path)) {
        $fixture = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach ($fixture['users'] as $id) {
            $user = User::findOrFail($id);
            if (! str_starts_with($user->username, 'routing-') || ! str_ends_with($user->username, $fixture['suffix'])) {
                throw new RuntimeException('Refusing to revoke tokens outside this synthetic run.');
            }
            $user->tokens()->where('name', 'routing-integration')->delete();
        }
        unlink($path);
    }
    echo "Synthetic run tokens revoked; no database or historical record deleted.\n";
} else {
    throw new RuntimeException('Use prepare, verify or cleanup.');
}
