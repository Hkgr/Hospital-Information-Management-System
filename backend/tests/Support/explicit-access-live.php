<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/explicit-access-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup the previous synthetic fixture first.');
    }
    $fixture = DB::transaction(function () {
        $tag = Str::lower(Str::random(10));
        $fixture = ['tag' => $tag, 'users' => [], 'roles' => []];
        $fixture['facility'] = DB::table('facilities')->insertGetId(['code' => 'EA-LIVE-'.$tag, 'name_ar' => 'مشفى التدريب — بيانات اصطناعية', 'timezone' => 'Asia/Damascus']);
        foreach (['admin' => DB::table('permissions')->where('is_active', true)->pluck('code')->all(), 'clerk' => ['patients.basic.view'], 'viewer' => ['roles.view'], 'removal' => ['patients.basic.search', 'patients.basic.create'], 'addition' => ['patients.basic.search'], 'disabled' => ['patients.basic.view']] as $kind => $codes) {
            $user = User::factory()->create(['username' => 'ea-'.$tag.'-'.$kind, 'name' => 'حساب تدريب '.$kind]);
            $role = DB::table('roles')->insertGetId(['code' => 'ea-'.$tag.'-'.$kind, 'name_ar' => $kind === 'clerk' ? 'مدخل بيانات التدريب '.$tag : 'دور تدريب '.$kind.' '.$tag,
                'is_system_super_admin' => $kind === 'admin', 'access_consolidated_at' => $kind === 'admin' ? now() : null, 'is_active' => $kind !== 'disabled']);
            foreach (DB::table('permissions')->whereIn('code', $codes)->pluck('id') as $id) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
            }
            DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $fixture['facility'], 'role_id' => $role]);
            if (in_array($kind, ['admin', 'removal', 'addition'], true)) {
                DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
            }
            $fixture['users'][$kind] = ['id' => $user->id, 'username' => $user->username];
            $fixture['roles'][$kind] = $role;
        }
        $fixture['roles']['registration'] = DB::table('roles')->insertGetId(['code' => 'ea-'.$tag.'-registration', 'name_ar' => 'التسجيل المعتمد '.$tag]);
        DB::table('role_permissions')->insert(['role_id' => $fixture['roles']['registration'], 'permission_id' => DB::table('permissions')->where('code', 'patients.basic.view')->value('id')]);

        return $fixture;
    });
    file_put_contents($path, json_encode($fixture));
    echo "Synthetic access fixture prepared; no existing account changed.\n";
} elseif ($mode === 'verify') {
    $f = json_decode(file_get_contents($path), true);
    $patients = DB::table('patients')->where('created_by', $f['users']['clerk']['id'])->pluck('id');
    if ($patients->isEmpty()) {
        throw new RuntimeException('No saved browser registration.');
    }
    foreach ($patients as $patient) {
        $cards = DB::table('patient_dossiers')->where('patient_id', $patient)->where('facility_id', $f['facility'])->get();
        if ($cards->count() !== 1 || ! $cards[0]->registration_visit_id || DB::table('visits')->where('dossier_id', $cards[0]->id)->where('dossier_visit_kind', 'initial')->count() !== 1) {
            throw new RuntimeException('Canonical registration invariant failed.');
        }
    }
    echo "Saved patient, canonical card and first visit verified.\n";
} elseif ($mode === 'verify-reassignment') {
    $f = json_decode(file_get_contents($path), true);
    foreach (['disabled', 'registration'] as $role) {
        if (! DB::table('facility_user_roles')->where('user_id', $f['users']['disabled']['id'])->where('role_id', $f['roles'][$role])->where('facility_id', $f['facility'])->exists()) {
            throw new RuntimeException('Historical or new membership missing.');
        }
    }
    echo "Disabled membership retained alongside explicit new active membership.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true);
    foreach ($f['users'] as $row) {
        $user = User::findOrFail($row['id']);
        $user->tokens()->delete();
        $user->forceFill(['is_active' => false])->save();
    }
    unlink($path);
    echo "Synthetic accounts disabled; all history retained.\n";
} else {
    throw new RuntimeException('Expected prepare, verify or cleanup.');
}
