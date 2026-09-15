<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\DossierAuditPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-closure-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup the prior closure fixture first.');
    }
    $f = DB::transaction(function () {
        $f = DossierCompletionFixture::make();
        (new DossierAuditPermissionsSeeder)->run();
        DB::table('role_permissions')->insert(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', 'dossiers.audit')->value('id')]);
        $viewer = User::factory()->create(['name' => 'قارئ اصطناعي دون سجل التغييرات']);
        $role = DB::table('roles')->insertGetId(['code' => 'AUDIT-VIEW-'.$f['tag'], 'name_ar' => 'قارئ اختبار فقط']);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'dossiers.view')->value('id')]);
        DB::table('facility_user_roles')->insert(['user_id' => $viewer->id, 'facility_id' => $f['facility'], 'role_id' => $role]);
        $f['user_id'] = $f['user']->id;
        $f['view_only_id'] = $viewer->id;
        $f['token'] = $f['user']->createToken('dossier-closure-live', ['api'])->plainTextToken;
        $f['view_token'] = $viewer->createToken('dossier-closure-live', ['api'])->plainTextToken;
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo 'Prepared synthetic closure fixture: '.DB::getDriverName().' / '.DB::selectOne('SELECT VERSION() AS version')->version."\n";
} elseif ($mode === 'cleanup') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ([$f['user_id'], $f['view_only_id']] as $id) {
        User::findOrFail($id)->tokens()->where('name', 'dossier-closure-live')->delete();
    }
    unlink($path);
    echo "Revoked owned closure fixture tokens.\n";
} else {
    throw new RuntimeException('Unknown command');
}
