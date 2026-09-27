<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Database\Seeders\PermissionMatrixPhaseTwoSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/reception-review-live.json');
if (($argv[1] ?? '') === 'prepare') {
    (new PermissionMatrixPhaseOneSeeder)->run();
    (new PermissionMatrixPhaseTwoSeeder)->run();
    $facility = DB::table('facilities')->insertGetId(['code' => 'REVIEW-'.Str::random(12), 'name_ar' => 'اختبار مراجعات الاستقبال', 'timezone' => 'Asia/Damascus']);
    $fixture = ['facility' => $facility, 'users' => []];
    foreach (['clerk' => 'data_entry', 'admin' => 'hospital_admin'] as $key => $code) {
        $user = User::factory()->create();
        $role = DB::table('roles')->where('code', $code)->value('id');
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role]);
        if ($key === 'clerk') {
            DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        } else {
            $global = DB::table('roles')->insertGetId(['code' => 'review-'.Str::random(12), 'name_ar' => 'تفويض مراجعة اختباري']);
            foreach (['patients.identity.review', 'patients.duplicates.merge'] as $permission) {
                DB::table('role_permissions')->insert(['role_id' => $global, 'permission_id' => DB::table('permissions')->where('code', $permission)->value('id')]);
            }
            DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $global]);
        }
        $fixture['users'][] = $user->id;
        $fixture[$key] = ['id' => $user->id, 'name' => $user->name, 'token' => $user->createToken('review-live', ['api'])->plainTextToken];
    }
    file_put_contents($path, json_encode($fixture));
    echo "Synthetic review fixture prepared; credentials remain local.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    $fixture = json_decode(file_get_contents($path), true);
    foreach ($fixture['users'] as $id) {
        $user = User::findOrFail($id);
        $user->tokens()->delete();
        $user->forceFill(['is_active' => false])->save();
    }
    unlink($path);
    echo "Synthetic tokens revoked; historical records retained.\n";
} else {
    throw new RuntimeException('Expected prepare or cleanup');
}
