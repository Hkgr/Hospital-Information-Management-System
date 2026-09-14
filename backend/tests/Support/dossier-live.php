<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup prior dossier fixture before another run.');
    }
    $f = DB::transaction(fn () => DossierFixture::make());
    // Prove dossiers.view alone works without doctor, clinic or catalog access.
    DB::table('facility_user_roles')->where('user_id', $f['user']->id)->where('role_id', '!=', $f['dossier_role'])->delete();
    DB::table('global_user_roles')->where('user_id', $f['user']->id)->delete();
    $f['second'] = DB::table('facilities')->insertGetId(['code' => 'DOS-SECOND-'.$f['tag'], 'name_ar' => 'مشفى اختباري ثانٍ']);
    DB::table('facility_user_roles')->insert(['facility_id' => $f['second'], 'role_id' => $f['dossier_role'], 'user_id' => $f['user']->id]);
    // A second page, without adding any visit or copying patient identity.
    $p = (array) DB::table('patients')->where('id', $f['patients'][10])->first();
    unset($p['id']);
    $p['patient_code'] .= '-EXTRA';
    $patient = DB::table('patients')->insertGetId($p);
    DB::table('patient_dossiers')->insert(['facility_id' => $f['facility'], 'patient_id' => $patient, 'code' => 'DOS-'.$f['tag'].'-011', 'opening_date' => '2011-01-01', 'status' => 'active', 'entered_by' => $f['user']->id]);
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('dossier-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('dossier-live', ['api'])->plainTextToken;
    unset($f['user'], $f['viewer']);
    $f['counts'] = [];
    foreach (['patients', 'patient_dossiers', 'visits', 'visit_diagnoses', 'blood_transfusions', 'blood_recipient_procedures', 'visit_services', 'visit_procedures'] as $table) {
        $f['counts'][$table] = DB::table($table)->count();
    }
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared synthetic dossier records; credentials only in ignored local storage.\n";
} else {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($argv[1] ?? '') === 'verify') {
        foreach ($f['counts'] as $table => $count) {
            if (DB::table($table)->count() !== $count) {
                throw new RuntimeException('Read-only workflow changed '.$table);
            }
        }
        echo "Verified read-only row counts and preserved clinical/blood-bank records.\n";
    } elseif (($argv[1] ?? '') === 'cleanup') {
        foreach ([$f['user_id'], $f['viewer_id']] as $id) {
            User::findOrFail($id)->tokens()->where('name', 'dossier-live')->delete();
        }
        unlink($path);
        echo "Revoked owned test tokens.\n";
    } else {
        throw new RuntimeException('Unknown fixture command.');
    }
}
