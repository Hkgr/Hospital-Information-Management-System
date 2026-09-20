<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\DossierCompletionPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierWorkflowFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-workflow-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup prior workflow fixture first.');
    }
    $f = DB::transaction(fn () => DossierWorkflowFixture::make());
    app(DossierCompletionPermissionsSeeder::class)->run();
    DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', 'dossiers.export')->value('id')]);
    DB::table('patients')->where('patient_code', $f['search_patient_code'])->update(['mother_name' => 'أم اختبار البطاقة', 'phone' => '00963900123456', 'paper_file_number' => '000072', 'birth_date' => '1980-01-01', 'birth_date_accuracy' => 'year_only']);
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('dossier-workflow-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('dossier-workflow-live', ['api'])->plainTextToken;
    unset($f['user'], $f['viewer']);
    $f['before'] = [];
    foreach (['patients', 'patient_dossiers', 'visits', 'visit_diagnoses', 'dossier_section_progress', 'dossier_requests'] as $table) {
        $f['before'][$table] = DB::table($table)->count();
    }
    $f['clinical_before'] = [];
    foreach (['reporting_periods', 'visit_services', 'visit_procedures', 'visit_medications', 'blood_transfusions', 'blood_donations', 'blood_bank_events'] as $table) {
        $f['clinical_before'][$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo 'Prepared synthetic wizard fixture on '.DB::getDriverName().' / '.DB::selectOne('SELECT VERSION() AS version')->version."; no production access.\n";
} else {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($mode === 'verify-empty') {
        foreach ($f['before'] as $table => $count) {
            if (DB::table($table)->count() !== $count) {
                throw new RuntimeException('Opening wizard wrote '.$table);
            }
        }
        echo "Opening new wizard did not write domain records.\n";
    } elseif ($mode === 'verify') {
        foreach ($f['clinical_before'] as $table => $rows) {
            if (DB::table($table)->orderBy('id')->get()->toJson() !== $rows) {
                throw new RuntimeException('Unrelated domain changed: '.$table);
            }
        }
        $new = DB::table('patient_dossiers as d')->join('patients as p', 'p.id', '=', 'd.patient_id')->where('d.facility_id', $f['facility'])->where('p.patient_code', 'like', 'WIZ-'.$f['tag'].'-%')->get(['d.*']);
        if ($new->count() < 3) {
            throw new RuntimeException('Expected all three viewport workflows.');
        }
        foreach ($new as $d) {
            if ($d->status !== 'draft') {
                throw new RuntimeException('Dossier unexpectedly activated');
            }
            $v = DB::table('visits')->where('dossier_id', $d->id)->get();
            if ($v->count() !== 1 || $v[0]->status !== 'draft' || $v[0]->reporting_period_id !== null) {
                throw new RuntimeException('Draft visit identity or period contract failed');
            }
            if (DB::table('visit_diagnoses')->where('visit_id', $v[0]->id)->where(fn ($q) => $q->whereNotNull('reporting_period_id')->orWhere('is_primary', true))->exists()) {
                throw new RuntimeException('Diagnosis period/primary changed');
            }
        }
        echo "Verified persisted draft identities, one initial visit each, NULL periods and unchanged reporting/blood/catalog domain rows.\n";
    } elseif ($mode === 'cleanup') {
        foreach ([$f['user_id'], $f['viewer_id']] as $id) {
            User::findOrFail($id)->tokens()->where('name', 'dossier-workflow-live')->delete();
        }
        unlink($path);
        echo "Revoked owned wizard tokens.\n";
    } else {
        throw new RuntimeException('Unknown command');
    }
}
