<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\VisitDiagnosisAdditionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierWorkflowFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/diagnosis-notes-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup the previous owned diagnosis fixture first.');
    }
    $fixture = DB::transaction(function () {
        $f = DossierWorkflowFixture::make();
        app(VisitDiagnosisAdditionsSeeder::class)->run();

        return ['facility' => $f['facility'], 'user_id' => $f['user']->id, 'token' => $f['user']->createToken('diagnosis-notes-live', ['api'])->plainTextToken,
            'clinic_name' => DB::table('clinics')->where('id', $f['clinics'][0])->value('name_ar'),
            'doctor_name' => DB::table('staff')->where('id', $f['workflow_doctors'][0])->value('full_name'),
            'diagnosis_names' => VisitDiagnosisAdditionsSeeder::LABELS];
    });
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    echo "Prepared isolated synthetic diagnosis fixture.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    User::findOrFail($f['user_id'])->tokens()->where('name', 'diagnosis-notes-live')->delete();
    unlink($path);
    echo "Revoked owned test token; synthetic clinical history retained.\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup.');
}
