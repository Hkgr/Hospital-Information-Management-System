<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierWorkflowFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-review-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Clean up the prior review fixture first.');
    }
    $f = DB::transaction(function () {
        $f = DossierWorkflowFixture::make();
        $f['token'] = $f['user']->createToken('dossier-review-live', ['api'])->plainTextToken;
        $f['actors'] = [$f['user']->id];
        foreach (['no_identity' => ['dossiers.create'], 'search' => ['dossiers.create', 'patients.search'], 'new_patient' => ['dossiers.create', 'patients.create'], 'visit_create' => ['dossiers.visits.create'], 'visit_update' => ['dossiers.visits.update']] as $name => $codes) {
            $u = User::factory()->create();
            $role = DB::table('roles')->insertGetId(['code' => 'REVIEW-'.$u->id.'-'.$f['tag'], 'name_ar' => 'اختبار صلاحيات المراجعة']);
            foreach (['dossiers.view', ...$codes] as $code) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
            }
            DB::table('facility_user_roles')->insert(['user_id' => $u->id, 'facility_id' => $f['facility'], 'role_id' => $role]);
            if (in_array($name, ['search', 'new_patient'])) {
                DB::table('global_user_roles')->insert(['user_id' => $u->id, 'role_id' => $role]);
            }
            $f[$name.'_token'] = $u->createToken('dossier-review-live', ['api'])->plainTextToken;
            $f['actors'][] = $u->id;
        }
        $f['empty_dossier'] = $f['dossiers'][2];
        $f['context_dossier'] = $f['dossiers'][1];
        DB::table('patient_dossiers')->whereIn('id', [$f['empty_dossier'], $f['context_dossier']])->update(['status' => 'draft']);
        $v = DB::table('visits')->where('facility_id', $f['facility'])->where('patient_id', $f['patients'][2])->whereNull('voided_at')->first();
        DB::table('visits')->where('id', $v->id)->update(['dossier_id' => $f['context_dossier'], 'status' => 'draft', 'visit_date' => '2001-03-02']);
        $f['context_visit'] = $v->id;
        DB::table('visit_diagnoses')->insert(['visit_id' => $v->id, 'facility_id' => $f['facility'], 'reporting_period_id' => null, 'diagnosis_id' => $f['diagnosis'], 'clinic_id' => $f['clinics'][0], 'diagnosing_staff_id' => $f['workflow_doctors'][0], 'client_request_id' => (string) Str::uuid(), 'entered_by' => $f['user']->id]);
        DB::table('clinic_staff')->where('clinic_id', $f['clinics'][0])->update(['starts_on' => '2000-01-01', 'ends_on' => '2002-01-01']);
        DB::table('clinics')->where('id', $f['clinics'][0])->update(['is_active' => false]);
        DB::table('staff')->where('id', $f['workflow_doctors'][0])->update(['is_active' => false]);
        $p = (array) DB::table('patients')->where('id', $f['patients'][1])->first();
        unset($p['id']);
        $f['race_patients'] = [];
        foreach ([390, 768, 1440] as $width) {
            $f['race_patients'][$width] = DB::table('patients')->insertGetId(array_replace($p, ['patient_code' => 'RACE-'.$f['tag'].'-'.$width]));
        }
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared synthetic review fixture on isolated MariaDB.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($f['actors'] as $id) {
        User::findOrFail($id)->tokens()->where('name', 'dossier-review-live')->delete();
    }
    unlink($path);
    echo "Revoked review tokens.\n";
} else {
    throw new RuntimeException('Unknown command');
}
