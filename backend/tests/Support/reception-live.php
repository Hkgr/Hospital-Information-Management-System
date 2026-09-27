<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/reception-live.json');
if (($argv[1] ?? '') === 'prepare') {
    (new PermissionMatrixPhaseOneSeeder)->run();
    $facility = DB::table('facilities')->insertGetId(['code' => 'RECEPTION-'.Str::random(12), 'name_ar' => 'مشفى اختبار الاستقبال', 'timezone' => 'Asia/Damascus']);
    $user = User::factory()->create();
    $role = DB::table('roles')->where('code', 'data_entry')->value('id');
    DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role]);
    DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
    file_put_contents($path, json_encode(['facility' => $facility, 'user' => $user->id, 'token' => $user->createToken('reception-live', ['api'])->plainTextToken]));
    echo "Synthetic reception fixture prepared.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $fixture = json_decode(file_get_contents($path), true);
    $user = User::findOrFail($fixture['user']);
    $user->tokens()->delete();
    $user->forceFill(['is_active' => false])->save();
    unlink($path);
    echo "Synthetic token revoked; historical test records preserved.\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup.');
}
