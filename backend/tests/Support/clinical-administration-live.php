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
$path = storage_path('framework/testing/clinical-administration-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Revoke the prior owned fixture tokens first.');
    }
    $f = DB::transaction(function () {
        $f = DossierCompletionFixture::make();
        $f['procedure'] = DB::table('procedures')->insertGetId(['code' => 'CLINICAL-LIVE-'.$f['tag'], 'name_ar' => 'إجراء جراحي اصطناعي '.$f['tag'], 'execution_location' => 'surgical_clinic']);
        $f['procedure_name'] = DB::table('procedures')->where('id', $f['procedure'])->value('name_ar');
        DB::table('clinics')->where('id', $f['clinics'][0])->update(['clinic_kind' => 'surgical', 'care_setting' => 'outpatient']);
        $f['clinic_name'] = DB::table('clinics')->where('id', $f['clinics'][0])->value('name_ar');
        $f['doctor_name'] = DB::table('staff')->where('id', $f['workflow_doctors'][0])->value('full_name');

        return $f;
    });
    $f['user_id'] = $f['user']->id;
    $f['token'] = $f['user']->createToken('clinical-administration-live', ['api'])->plainTextToken;
    unset($f['user'], $f['viewer']);
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared synthetic clinical fixture on guarded MariaDB.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    if (is_file($path)) {
        $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        User::findOrFail($f['user_id'])->tokens()->where('name', 'clinical-administration-live')->delete();
        unlink($path);
    }
    echo "Revoked owned tokens; retained synthetic clinical history.\n";
} else {
    throw new RuntimeException('Use prepare or cleanup.');
}
