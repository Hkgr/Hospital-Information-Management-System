<?php

use App\Console\Commands\InitializeClinicalEditing;
use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionFixture;
use Tests\Support\SessionDoseFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/clinical-edit-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup the previous synthetic clinical editing fixture first.');
    }
    $f = DB::transaction(function () {
        $f = DossierCompletionFixture::make();
        app(OncologyPermissionsSeeder::class)->run();
        $codes = [...array_keys(OncologyPermissionsSeeder::CODES), 'doctors.view', 'doctors.link', 'clinics.view', 'clinics.edit'];
        foreach (DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => $id]);
        }
        $reader = User::factory()->create();
        $role = DB::table('roles')->insertGetId(['code' => 'CLINICAL-EDIT-'.Str::random(12), 'name_ar' => 'محرر سريري اصطناعي']);
        foreach (DB::table('permissions')->whereIn('code', ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view', ...InitializeClinicalEditing::UPDATES, ...InitializeClinicalEditing::TREATMENT])->where('is_active', true)->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $reader->id, 'role_id' => $role, 'facility_id' => $f['facility']]);
        $f['reader_id'] = $reader->id;
        $f['reader_role'] = $role;
        $f['reader_token'] = $reader->createToken('clinical-edit', ['api'])->plainTextToken;
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        $f['token'] = $f['user']->createToken('clinical-edit', ['api'])->plainTextToken;
        $f['session_medication'] = SessionDoseFixture::medication($f['tag']);
        $f['session_medication_b'] = SessionDoseFixture::medication('second-'.$f['tag']);
        $f['doctor'] = $f['workflow_doctors'][0];
        $f['clinic'] = $f['clinics'][0];
        $f['doctor_name'] = DB::table('staff')->where('id', $f['doctor'])->value('full_name');
        $f['clinic_name'] = DB::table('clinics')->where('id', $f['clinic'])->value('name_ar');
        $f['diagnosis_name'] = DB::table('diagnoses')->where('id', $f['diagnosis'])->value('name_ar');
        $f['assignment'] = DB::table('clinic_staff')->where('clinic_id', $f['clinic'])->where('staff_id', $f['doctor'])->value('id');
        DB::table('clinic_staff')->where('id', $f['assignment'])->update(['starts_on' => '2024-01-01']);
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Synthetic fixture prepared.\n";
} elseif ($mode === 'legacy-session-dose' || $mode === 'read-session-dose') {
    $f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $row = DB::table('oncology_session_doses')->where('id', (int) ($argv[2] ?? 0))->where('facility_id', $f['facility'])->where('entered_by', $f['user_id'])->first();
    if (! $row) {
        throw new RuntimeException('An owned synthetic session-dose record is required.');
    }
    $row = (array) $row;
    if ($mode === 'legacy-session-dose') {
        unset($row['id']);
        $row['dose_name'] = 'اسم جرعة تاريخي بلا ربط دوائي';
        $row['medication_id'] = null;
        $row['nurse_id'] = $f['doctor'];
        $row['nurse_name'] = null;
        $row['client_request_id'] = (string) Str::uuid();
        $row['lock_version'] = 1;
        $row['id'] = DB::table('oncology_session_doses')->insertGetId($row);
    }
    echo json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($mode === 'revoke' || $mode === 'restore') {
    $f = json_decode(file_get_contents($path), true);
    $key = ['role_id' => $f['reader_role'], 'permission_id' => DB::table('permissions')->where('code', 'dossiers.diagnoses.update')->value('id')];
    if ($mode === 'revoke') {
        DB::table('role_permissions')->where($key)->delete();
    } else {
        DB::table('role_permissions')->insertOrIgnore($key);
    }
    echo "Only synthetic reader diagnosis grant changed.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true);
    foreach (['user_id', 'viewer_id', 'reader_id'] as $key) {
        $user = User::findOrFail($f[$key]);
        $user->tokens()->delete();
        $user->forceFill(['is_active' => false])->save();
    }
    unlink($path);
    echo "Synthetic accounts disabled; all saved clinical history retained.\n";
} else {
    throw new RuntimeException('Expected prepare/revoke/restore/cleanup.');
}
