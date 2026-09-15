<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-completion-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup prior workflow fixture first.');
    }
    $f = DB::transaction(fn () => DossierCompletionFixture::make());
    $f['service_name'] = DB::table('services')->where('id', $f['service'])->value('name_ar');
    $f['procedure_name'] = DB::table('procedures')->where('id', $f['procedure'])->value('name_ar');
    $f['medication_name'] = DB::table('medications')->where('id', $f['medication'])->value('name_ar');
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('dossier-completion-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('dossier-completion-live', ['api'])->plainTextToken;
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
        foreach (['reporting_periods', 'visit_medications', 'blood_transfusions', 'blood_donations', 'blood_bank_events'] as $table) {
            if (DB::table($table)->orderBy('id')->get()->toJson() !== $f['clinical_before'][$table]) {
                throw new RuntimeException('Unrelated domain changed: '.$table);
            }
        }
        $visits = DB::table('visits')->where('facility_id', $f['facility'])->where('phase_three', true)->get();
        if ($visits->count() < 3) {
            throw new RuntimeException('Expected Phase 3 visits');
        }
        foreach ($visits as $v) {
            if (DB::table('visit_prescriptions')->where('visit_id', $v->id)->whereNull('voided_at')->count() > 1) {
                throw new RuntimeException('Multiple active prescriptions');
            }
        }
        echo "Verified actual Phase 3 records, prescription separation and unchanged periods/blood/dispensing.\n";
    } elseif ($mode === 'cleanup') {
        foreach ([$f['user_id'], $f['viewer_id']] as $id) {
            User::findOrFail($id)->tokens()->where('name', 'dossier-completion-live')->delete();
        }
        unlink($path);
        echo "Revoked owned wizard tokens.\n";
    } else {
        throw new RuntimeException('Unknown command');
    }
}
