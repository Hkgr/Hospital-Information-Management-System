<?php

use App\Models\User;
use App\Services\Auth\TaskPermissions;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/unified-registration-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Clean up the previous synthetic fixture before preparing another.');
    }
    $fixture = DB::transaction(function () {
        DB::table('permissions')->insertOrIgnore(['code' => 'dossiers.treatment.view', 'name_ar' => 'عرض العلاج الورمي', 'is_active' => true]);
        $tag = Str::lower(Str::random(10));
        $f = ['tag' => $tag, 'users' => [], 'facilities' => []];
        foreach (['أ', 'ب'] as $i => $name) {
            $f['facilities'][] = DB::table('facilities')->insertGetId(['code' => 'UNIFIED-'.$tag.'-'.$i, 'name_ar' => 'مشفى التدريب '.$name, 'timezone' => 'Asia/Damascus']);
        }
        $basic = ['patients.basic.view', 'patient_cards.register', 'patients.basic.search', 'patients.basic.create', 'patients.own.correct', 'patients.corrections.request'];
        $medical = [...$basic, 'dossiers.medical.view', 'dossiers.visits.view', 'dossiers.medical.update', 'dossiers.visits.create', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update', 'dossiers.services.update', 'dossiers.procedures.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update', 'dossiers.visits.complete'];
        $manager = array_unique([...array_keys(TaskPermissions::TASKS), ...$medical, 'users.view', 'users.create', 'roles.view', 'roles.create', 'roles.update', 'stock.view', 'blood_bank.view', 'clinics.view', 'doctors.view', 'catalog.view', 'dossiers.treatment.view']);
        foreach (['clerk' => $basic, 'medical' => $medical, 'manager' => $manager, 'service' => ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.services.update'], 'none' => []] as $kind => $codes) {
            $u = User::factory()->create(['username' => 'unified-'.$tag.'-'.$kind, 'name' => 'مستخدم تدريب '.$kind]);
            $role = DB::table('roles')->insertGetId(['code' => 'unified-'.$tag.'-'.$kind, 'name_ar' => 'دور تدريب '.$kind]);
            $ids = DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id');
            if ($ids->count() !== count($codes)) {
                $present = DB::table('permissions')->whereIn('id', $ids)->pluck('code')->all();
                throw new RuntimeException('Missing active definitions: '.implode(', ', array_diff($codes, $present)));
            }
            foreach ($ids as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
            }
            DB::table('facility_user_roles')->insert(['facility_id' => $f['facilities'][0], 'user_id' => $u->id, 'role_id' => $role]);
            if (in_array($kind, ['clerk', 'medical', 'manager'])) {
                DB::table('global_user_roles')->insert(['user_id' => $u->id, 'role_id' => $role]);
            }
            $f['users'][$kind] = ['id' => $u->id, 'username' => $u->username];
        }

        return $f;
    });
    file_put_contents($path, json_encode($fixture));
    echo "Synthetic fixture created in isolated testing database; no existing grants changed.\n";
} elseif ($mode === 'verify') {
    $f = json_decode(file_get_contents($path), true);
    $people = DB::table('patients')->where('created_by', $f['users']['clerk']['id'])->get();
    foreach ($people as $person) {
        $cards = DB::table('patient_dossiers')->where('patient_id', $person->id)->where('facility_id', $f['facilities'][0])->get();
        if ($cards->count() !== 1 || ! $cards[0]->registration_visit_id) {
            throw new RuntimeException('Expected one canonical local card and saved first visit.');
        }
        if (DB::table('visits')->where('dossier_id', $cards[0]->id)->where('dossier_visit_kind', 'initial')->count() !== 1) {
            throw new RuntimeException('The first visit was duplicated.');
        }
    }
    if ($people->isEmpty()) {
        throw new RuntimeException('No browser-created patient found.');
    }
    echo "Canonical patient/card/initial-visit database invariants verified.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true);
    foreach ($f['users'] as $row) {
        $u = User::findOrFail($row['id']);
        $u->tokens()->delete();
        $u->forceFill(['is_active' => false])->save();
    }
    unlink($path);
    echo "Synthetic accounts disabled and tokens revoked; history retained.\n";
} else {
    throw new RuntimeException('Expected prepare, verify or cleanup.');
}
