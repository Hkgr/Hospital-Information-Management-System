<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\BloodBankPermissionsSeeder;
use Database\Seeders\ClinicPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Database\Seeders\DossierCompletionPermissionsSeeder;
use Database\Seeders\DossierPermissionsSeeder;
use Database\Seeders\DossierWorkflowPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\StockFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/automatic-codes-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Clean up the prior synthetic tokens first.');
    }
    $f = DB::transaction(function () {
        $f = StockFixture::make();
        app(BloodBankPermissionsSeeder::class)->run();
        foreach ([ClinicPermissionsSeeder::class, DoctorPermissionsSeeder::class, DossierPermissionsSeeder::class, DossierWorkflowPermissionsSeeder::class, DossierCompletionPermissionsSeeder::class] as $seeder) {
            app($seeder)->run();
        }
        $role = DB::table('global_user_roles')->where('user_id', $f['user']->id)->value('role_id');
        foreach (DB::table('permissions')->where('is_active', true)->pluck('id') as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('staff_types')->insertOrIgnore(['code' => 'DOCTOR', 'name_ar' => 'طبيب اختباري']);
        $f['staff_type_id'] = DB::table('staff_types')->where('code', 'DOCTOR')->value('id');
        $f['specialty_ids'] = DB::table('specialties')->where('is_active', true)->limit(1)->pluck('id')->all();
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared isolated synthetic automatic-code fixture; credentials remain local.\n";
} elseif (($argv[1] ?? '') === 'cleanup' && is_file($path)) {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach (['user_id', 'viewer_id'] as $key) {
        $user = User::findOrFail($f[$key]);
        if (! str_starts_with($user->username, 'catalog-') || ! str_ends_with($user->username, $f['tag'])) {
            throw new RuntimeException('Not this synthetic fixture.');
        }
        $user->tokens()->delete();
    }
    unlink($path);
    echo "Revoked synthetic fixture tokens; preserved test history.\n";
} else {
    throw new RuntimeException('Use prepare or cleanup with an existing fixture.');
}
