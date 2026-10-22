<?php

use App\Models\User;
use App\Services\Users\DataEntryCardPermissions;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/card-blood-benefit-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Revoke the previous owned fixture tokens first.');
    }
    $f = DB::transaction(function () {
        $f = DossierCompletionFixture::make();
        app(DataEntryCardPermissions::class)->define();
        $role = DB::table('roles')->insertGetId(['code' => 'BLOOD-CARD-'.$f['tag'], 'name_ar' => 'مدخل بيانات اصطناعي · اختبار']);
        foreach (DataEntryCardPermissions::GRANTS as $code) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $clerk = User::factory()->create(['username' => 'blood-card-'.$f['tag'], 'email' => 'blood-card-'.$f['tag'].'@example.test']);
        DB::table('facility_user_roles')->insert(['role_id' => $role, 'user_id' => $clerk->id, 'facility_id' => $f['facility']]);
        $f['clerk_id'] = $clerk->id;
        $f['token'] = $clerk->createToken('card-blood-live', ['api'])->plainTextToken;
        $f['setup_token'] = $f['user']->createToken('card-blood-live', ['api'])->plainTextToken;
        $f['denied_token'] = $f['viewer']->createToken('card-blood-live', ['api'])->plainTextToken;
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        $f['blood_service'] = DB::table('services')->where('code', 'SER-4-001')->value('id') ?? DB::table('services')->insertGetId(['category_id' => DB::table('services')->where('id', $f['service'])->value('category_id'), 'code' => 'SER-4-001', 'name_ar' => 'نقل دم', 'is_active' => true]);
        $f['component'] = DB::table('blood_components')->where('is_active', true)->value('id');
        $f['component_name'] = DB::table('blood_components')->where('id', $f['component'])->value('name_ar');
        $f['service_name'] = DB::table('services')->where('id', $f['service'])->value('name_ar');
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared isolated synthetic card/blood data-entry fixture.\n";
} elseif (($argv[1] ?? '') === 'assert-no-benefit') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $dossier = DB::table('patient_dossiers')->where('id', (int) ($argv[2] ?? 0))->where('facility_id', $f['facility'])->first();
    if (! $dossier || DB::table('blood_bank_people')->where('patient_id', $dossier->patient_id)->where('facility_id', $f['facility'])->exists() || DB::table('blood_transfusions')->where('patient_id', $dossier->patient_id)->where('facility_id', $f['facility'])->exists()) {
        throw new RuntimeException('Expected a synthetic card without a blood identity or benefit.');
    }
    echo "Verified that switching the synthetic service created no blood facts.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ([$f['clerk_id'], $f['user_id'], $f['viewer_id']] as $id) {
        User::findOrFail($id)->tokens()->where('name', 'card-blood-live')->delete();
    }
    unlink($path);
    echo "Revoked only owned test tokens; test clinical records retained.\n";
} else {
    throw new RuntimeException('Unknown fixture command.');
}
