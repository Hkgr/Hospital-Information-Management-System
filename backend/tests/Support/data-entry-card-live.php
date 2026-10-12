<?php

use App\Models\User;
use App\Services\Users\DataEntryCardPermissions;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionFixture;
use Tests\Support\SessionDoseFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/data-entry-card-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'legacy-session-dose') {
    $f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    $old = DB::table('oncology_session_doses')->where('facility_id', $f['facility'])->where('entered_by', $f['clerk_id'])->orderByDesc('id')->first();
    if (! $old) {
        throw new RuntimeException('Create the owned synthetic session dose first.');
    }
    $row = (array) $old;
    unset($row['id']);
    $row['dose_name'] = 'اسم جرعة تاريخي غير مرتبط بدواء';
    $row['medication_id'] = null;
    $row['nurse_id'] = $f['workflow_doctors'][0];
    $row['nurse_name'] = null;
    $row['client_request_id'] = (string) Str::uuid();
    $row['lock_version'] = 1;
    $row['id'] = DB::table('oncology_session_doses')->insertGetId($row);
    $row['nurse_display_name'] = DB::table('staff')->where('id', $row['nurse_id'])->value('full_name');
    echo json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($mode === 'counts') {
    $counts = [];
    foreach (['clinics', 'staff', 'services', 'procedures', 'medications', 'service_categories', 'diagnoses'] as $table) {
        $counts[$table] = DB::table($table)->count();
    }
    echo json_encode($counts, JSON_THROW_ON_ERROR);
} elseif ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Review/cleanup the previous data-entry fixture first.');
    }
    $f = DB::transaction(function () {
        $f = DossierCompletionFixture::make();
        $role = DB::table('roles')->where('code', 'data_entry')->where('is_active', true)->where('is_system_super_admin', false)->first();
        if (! $role) {
            throw new RuntimeException('This preserved test database requires its existing ordinary data_entry role. No role was created.');
        }
        $f['role_before'] = (array) $role;
        $f['grants_before'] = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        app(DataEntryCardPermissions::class)->reconcile(true, $f['facility'], 'LOCAL/data-entry-card-live');
        $f['role_after_version'] = DB::table('roles')->where('id', $role->id)->value('lock_version');
        $f['grants_after'] = DB::table('role_permissions')->where('role_id', $role->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $clerk = User::factory()->create(['username' => 'entry-live-'.$f['tag'], 'email' => 'entry-live-'.$f['tag'].'@example.test']);
        DB::table('facility_user_roles')->insert(['user_id' => $clerk->id, 'facility_id' => $f['facility'], 'role_id' => $role->id]);
        $f['clerk_id'] = $clerk->id;
        $f['clerk_token'] = $clerk->createToken('data-entry-card-live', ['api'])->plainTextToken;
        foreach (DB::table('permissions')->whereIn('code', ['clinics.create', 'doctors.directory.create', 'catalog.directory.create', 'diagnoses.create', 'medications.create', 'clinics.view', 'doctors.view', 'catalog.view'])->pluck('id') as $id) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => $id]);
        }
        $f['creator_token'] = $f['user']->createToken('data-entry-directory-author', ['api'])->plainTextToken;
        $f['creator_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        $f['session_medication'] = SessionDoseFixture::medication($f['tag']);
        $f['session_medication_name'] = DB::table('medications')->where('id', $f['session_medication'])->value('name_ar');
        $f['staff_type'] = DB::table('staff')->where('id', $f['workflow_doctors'][0])->value('staff_type_id');
        $f['category'] = DB::table('service_categories')->where('is_active', true)->value('id');
        if (Artisan::call('dossiers:setup-prescription-funding', ['--apply' => true, '--facility' => $f['facility'], '--execution-reference' => 'LOCAL real invoice verification']) !== 0) {
            throw new RuntimeException('Approved funding setup failed; review the local definitions.');
        }
        $f['fundings'] = DB::table('funding_sources')->whereIn('code', ['ministry_of_health', 'al_rowad', 'personal_expense'])->where('is_active', true)->orderBy('display_order')->get(['id', 'name_ar'])->all();
        if (count($f['fundings']) !== 3) {
            throw new RuntimeException('Real verification requires the three approved active sources; no disabled definition was reactivated.');
        }
        $f['funding'] = $f['fundings'][0]->id;
        $f['diagnosis_name'] = DB::table('diagnoses')->where('id', $f['diagnosis'])->value('name_ar');
        $f['clinic_name'] = DB::table('clinics')->where('id', $f['clinics'][0])->value('name_ar');
        $f['doctor_name'] = DB::table('staff')->where('id', $f['workflow_doctors'][0])->value('full_name');
        $f['service_name'] = DB::table('services')->where('id', $f['service'])->value('name_ar');
        $f['procedure_name'] = DB::table('procedures')->where('id', $f['procedure'])->value('name_ar');
        $f['medication_name'] = DB::table('medications')->where('id', $f['medication'])->value('name_ar');
        $f['invoice_medications'] = [];
        foreach ([1, 2, 3] as $i) {
            $name = 'دواء فاتورة اختبار '.$i.' '.$f['tag'];
            $id = DB::table('medications')->insertGetId(['code' => 'ENTRY-RX-'.$i.'-'.$f['tag'], 'name_ar' => $name, 'is_active' => true]);
            $f['invoice_medications'][] = ['id' => $id, 'name_ar' => $name];
        }
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared local synthetic accounts: clerk has data_entry only, no global assignment.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    DB::transaction(function () use ($f) {
        DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('roles')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('permissions')->orderBy('id')->lockForUpdate()->get(['id']);
        DB::table('role_permissions')->orderBy('id')->lockForUpdate()->get(['id']);
        $role = $f['role_before'];
        $current = DB::table('role_permissions')->where('role_id', $role['id'])->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        if ($current !== $f['grants_after'] || DB::table('roles')->where('id', $role['id'])->value('lock_version') != $f['role_after_version']) {
            throw new RuntimeException('Role changed outside this fixture; refusing to overwrite it during cleanup. Review the saved fixture.');
        }
        $oldIds = array_column($f['grants_before'], 'permission_id');
        DB::table('role_permissions')->where('role_id', $role['id'])->whereNotIn('permission_id', $oldIds)->delete();
        foreach ($f['grants_before'] as $row) {
            DB::table('role_permissions')->insertOrIgnore($row);
        }
        DB::table('roles')->where('id', $role['id'])->update(['lock_version' => $role['lock_version'], 'updated_at' => $role['updated_at']]);
        foreach ([$f['clerk_id'], $f['creator_id'], $f['viewer_id']] as $id) {
            $user = User::findOrFail($id);
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
        }
    });
    unlink($path);
    echo "Restored the isolated role snapshot and disabled synthetic accounts; saved clinical/audit history retained.\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup.');
}
