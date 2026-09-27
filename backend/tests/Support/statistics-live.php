<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\StatisticsFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/statistics-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $f = StatisticsFixture::make();
    $super = User::factory()->create();
    $superRole = DB::table('roles')->insertGetId(['code' => 'STATS-SUPER-'.$f['tag'], 'name_ar' => 'سوبر أدمن اختباري', 'is_system_super_admin' => true]);
    DB::table('global_user_roles')->insert(['user_id' => $super->id, 'role_id' => $superRole]);
    $f['users']['super_admin'] = $super;
    $clerk = $f['users']['data_entry'];
    DB::table('global_user_roles')->insert(['user_id' => $clerk->id, 'role_id' => DB::table('roles')->where('code', 'data_entry')->value('id')]);
    foreach ($f['users'] as $role => $user) {
        $f['users'][$role] = ['id' => $user->id, 'username' => $user->username];
    }
    file_put_contents($path, json_encode($f));
    echo "Synthetic statistics fixture prepared; no credentials printed.\n";
} else {
    $f = json_decode(file_get_contents($path), true);
    if ($mode === 'cleanup') {
        foreach ($f['users'] as $user) {
            $u = User::findOrFail($user['id']);
            $u->tokens()->delete();
            $u->forceFill(['is_active' => false])->save();
        }
        unlink($path);
        echo "Tokens revoked, synthetic history retained.\n";
    } elseif ($mode === 'audits') {
        echo DB::table('audit_logs')->where('actor_id', $f['users']['statistics']['id'])->where('event', 'expired')->count();
    } elseif (in_array($mode, ['expire', 'warn'], true)) {
        DB::table('personal_access_tokens')->where('tokenable_id', $f['users']['statistics']['id'])->update(['web_idle_deadline' => now()->addSeconds($mode === 'warn' ? 25 : -1)->format('Y-m-d H:i:s.u')]);
    } else {
        throw new RuntimeException('Unknown fixture command');
    }
}
