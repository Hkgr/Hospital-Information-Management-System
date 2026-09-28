<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dashboard-users-live.json');
$mode = $argv[1] ?? '';
$protectedSnapshot = fn () => hash('sha256', json_encode([
    DB::table('users')->where('id', 1)->first(),
    DB::table('facility_user_roles')->where('user_id', 1)->orderBy('id')->get(),
    DB::table('global_user_roles')->where('user_id', 1)->orderBy('id')->get(),
]));
if ($mode === 'prepare') {
    $f = DB::transaction(function () use ($protectedSnapshot) {
        $f = ['users' => [], 'facilities' => [], 'user_one_snapshot' => $protectedSnapshot()];
        $tag = Str::lower(Str::random(12));
        foreach (['A', 'B', 'C'] as $code) {
            $f['facilities'][] = DB::table('facilities')->insertGetId(['code' => 'ROUTING-'.$tag.'-'.$code, 'name_ar' => 'منشأة اختبار التوجيه '.$code, 'timezone' => 'Asia/Damascus']);
        }
        $codes = ['dashboards.view', 'users.view', 'users.create', 'users.delete', 'roles.view', 'roles.create', 'roles.update'];
        $ids = DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id', 'code')->all();
        if (count($ids) !== count($codes)) {
            throw new RuntimeException('Existing permission definitions required; this fixture does not seed or enable permissions.');
        }
        $f['permission'] = $ids['users.view'];
        // Separate actors keep repeated logout scenarios below the real login
        // limiter; never disable or flush the application's rate limiter.
        foreach (['single', 'single_errors', 'single_users', 'multi', 'reader', 'none', 'protected'] as $kind) {
            $u = User::factory()->create(['name' => 'اختبار التوجيه '.$kind]);
            $role = DB::table('roles')->insertGetId(['code' => 'routing-'.$tag.'-'.$kind, 'name_ar' => 'دور اختبار '.$kind, 'is_active' => true, 'is_system_super_admin' => $kind === 'protected']);
            foreach ($kind === 'none' || $kind === 'protected' ? [] : ($kind === 'reader' ? ['users.view'] : $codes) as $code) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $ids[$code]]);
            }
            foreach ($kind === 'multi' ? array_slice($f['facilities'], 0, 2) : [$f['facilities'][0]] as $facility) {
                DB::table('facility_user_roles')->insert(['user_id' => $u->id, 'facility_id' => $facility, 'role_id' => $role]);
            }
            $f['users'][$kind] = ['id' => $u->id, 'username' => $u->username, 'role' => $role];
        }

        return $f;
    });
    file_put_contents($path, json_encode($f));
    echo "Synthetic fixture prepared; no credentials printed.\n";
} else {
    $f = json_decode(file_get_contents($path), true);
    if ($mode === 'expire') {
        DB::table('personal_access_tokens')->where('tokenable_id', $f['users']['single']['id'])->update(['web_idle_deadline' => now()->subSecond()]);
    } elseif ($mode === 'cleanup') {
        if ($protectedSnapshot() !== $f['user_one_snapshot']) {
            throw new RuntimeException('Existing user 1 or assignments changed.');
        }
        foreach ($f['users'] as $u) {
            $user = User::findOrFail($u['id']);
            $user->tokens()->delete();
            $user->forceFill(['is_active' => false])->save();
        }
        unlink($path);
        echo "Existing user 1 unchanged; synthetic users disabled, tokens revoked, history retained.\n";
    } else {
        throw new RuntimeException('Unknown fixture mode.');
    }
}
