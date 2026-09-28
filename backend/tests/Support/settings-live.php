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
$path = storage_path('framework/testing/settings-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    $fixture = DB::transaction(function () {
        $tag = Str::lower(Str::random(10));
        $f = ['users' => [], 'facilities' => [], 'tag' => $tag];
        foreach (['أ', 'ب'] as $i => $label) {
            $f['facilities'][] = DB::table('facilities')->insertGetId(['code' => 'SETLIVE-'.$tag.'-'.$i, 'name_ar' => 'منشأة اختبار الإعدادات '.$label, 'timezone' => 'Asia/Damascus']);
        }
        foreach (['settings.view' => 'عرض إعدادات المنشأة', 'settings.update' => 'تعديل إعدادات المنشأة'] as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
        $admin = DB::table('role_permissions as rp')->join('roles as r', 'r.id', '=', 'rp.role_id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.code', 'hospital_admin')->where('p.is_active', true)->pluck('p.code')->all();
        $admin = array_unique([...$admin, 'settings.view', 'settings.update', 'reception.patients.search', 'reception.patients.create', 'statistics.view']);
        foreach (['admin' => $admin, 'clerk' => ['reception.view'], 'stats' => ['statistics.view'], 'reader' => ['settings.view'], 'none' => [], 'system' => []] as $kind => $codes) {
            $u = User::factory()->create(['name' => 'حساب اختبار الإعدادات '.$kind]);
            $role = DB::table('roles')->insertGetId(['code' => 'settings-live-'.$tag.'-'.$kind, 'name_ar' => 'دور اختبار '.$kind, 'is_system_super_admin' => $kind === 'system']);
            $ids = DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id');
            if ($ids->count() !== count($codes)) {
                throw new RuntimeException('Required existing definitions are missing; do not enable unrelated permissions.');
            }
            foreach ($ids as $id) {
                DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
            }
            foreach ($kind === 'admin' ? $f['facilities'] : [$f['facilities'][0]] as $id) {
                DB::table('facility_user_roles')->insert(['user_id' => $u->id, 'facility_id' => $id, 'role_id' => $role]);
            }
            if ($kind === 'system') {
                DB::table('global_user_roles')->insert(['user_id' => $u->id, 'role_id' => $role]);
            }
            $f['users'][$kind] = ['id' => $u->id, 'username' => $u->username, 'role' => $role];
        }
        $f['clerk_role'] = DB::table('roles')->where('code', 'data_entry')->value('id');
        // Explicit fixture-only global grant for the actor who visually checks
        // reception forms. User creation still never grants this to its target.
        $global = DB::table('roles')->insertGetId(['code' => 'settings-live-'.$tag.'-global', 'name_ar' => 'تفويض استقبال اختباري صريح']);
        foreach (DB::table('permissions')->whereIn('code', ['reception.patients.search', 'reception.patients.create'])->where('is_active', true)->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $global, 'permission_id' => $id]);
        }
        DB::table('global_user_roles')->insert(['user_id' => $f['users']['admin']['id'], 'role_id' => $global]);

        return $f;
    });
    file_put_contents($path, json_encode($fixture));
    echo "Isolated synthetic fixture prepared.\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true);
    $ids = array_column($f['users'], 'id');
    $ids = array_merge($ids, DB::table('users')->where('username', 'like', 'settingslive-'.$f['tag'].'%')->pluck('id')->all());
    foreach ($ids as $id) {
        $u = User::findOrFail($id);
        $u->tokens()->delete();
        $u->forceFill(['is_active' => false])->save();
    }
    unlink($path);
    echo "Synthetic accounts disabled, device tokens revoked, audit retained.\n";
} else {
    throw new RuntimeException('Unknown fixture action.');
}
