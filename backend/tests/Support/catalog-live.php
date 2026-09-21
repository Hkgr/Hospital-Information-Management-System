<?php

use App\Models\User;
use App\Services\Catalog\CatalogQueries;
use App\Support\TestDatabaseSafety;
use Database\Seeders\ClinicPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CatalogFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/catalog-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Previous live fixture exists; revoke its test tokens with cleanup first.');
    }
    $fixture = DB::transaction(function () {
        $f = CatalogFixture::make();
        $f['facility_code'] = 'CAT-'.$f['tag'];
        // Readable synthetic comparison records; grants apply only to this test account.
        app(ClinicPermissionsSeeder::class)->run();
        app(DoctorPermissionsSeeder::class)->run();
        $role = DB::table('global_user_roles')->where('user_id', $f['user']->id)->value('role_id');
        foreach (DB::table('permissions')->where(fn ($q) => $q->whereLike('code', 'clinics.%')->orWhereLike('code', 'doctors.%'))->pluck('id') as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        $f['second'] = DB::table('facilities')->insertGetId(['code' => 'ZZZ-'.$f['tag'], 'name_ar' => 'منشأة ثانية للاختبار', 'timezone' => 'Asia/Damascus']);
        $f['inactive'] = DB::table('facilities')->insertGetId(['code' => 'INACTIVE-'.$f['tag'], 'name_ar' => 'منشأة اختبار غير فعالة', 'is_active' => false, 'timezone' => 'Asia/Damascus']);
        foreach ([$f['second'], $f['inactive']] as $facility) {
            DB::table('facility_user_roles')->insert(['user_id' => $f['user']->id, 'role_id' => $role, 'facility_id' => $facility]);
        }
        // This single-facility account has catalog.view only, with no directory or medical authority.
        $viewerRole = DB::table('facility_user_roles')->where('user_id', $f['viewer']->id)->value('role_id');
        DB::table('role_permissions')->where('role_id', $viewerRole)->where('permission_id', '!=', DB::table('permissions')->where('code', 'catalog.view')->value('id'))->delete();
        $f['doctor'] = DB::table('staff')->where('staff_code', $f['facility_code'])->value('id');
        $f['clinic'] = DB::table('clinics')->insertGetId(['facility_id' => $f['facility'], 'code' => 'CLINIC-'.$f['tag'], 'name_ar' => 'عيادة اختبار', 'description' => 'وصف اختباري']);
        foreach (['service', 'procedure'] as $kind) {
            $table = 'visit_'.$kind.'s';
            $row = (array) DB::table($table)->where($kind.'_id', $f['items'][$kind][1])->orderBy('id')->first();
            $yesterday = now('Asia/Damascus')->subDay()->toDateString();
            $update = ['performed_on' => $yesterday];
            if ($kind === 'service') {
                $update['requested_on'] = $yesterday;
            }
            DB::table($table)->where('id', $row['id'])->update($update);
            unset($row['id'], $row['open_request_key']);
            for ($n = 0; $n < 23; $n++) {
                $row['client_request_id'] = (string) Str::uuid();
                DB::table($table)->insert($row);
            }
        }
        $f['token'] = $f['user']->createToken('catalog-live', ['api'])->plainTextToken;
        $f['viewer_token'] = $f['viewer']->createToken('catalog-live', ['api'])->plainTextToken;
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($fixture, JSON_THROW_ON_ERROR));
    $query = app(CatalogQueries::class)->query(['id' => $fixture['facility'], 'today' => $fixture['today']], []);
    $plan = DB::select('EXPLAIN '.$query->toSql(), $query->getBindings());
    file_put_contents(storage_path('framework/testing/catalog-explain.json'), json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "Prepared synthetic live fixture and inspected EXPLAIN; tokens remain local.\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    if (is_file($path)) {
        $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (['user_id', 'viewer_id'] as $key) {
            $user = User::findOrFail($f[$key]);
            if (! str_starts_with($user->username, 'catalog-') || ! str_ends_with($user->username, $f['tag'])) {
                throw new RuntimeException('Not this synthetic account.');
            }
            $user->tokens()->where('name', 'catalog-live')->delete();
        }
        unlink($path);
    }
    echo "Revoked this run's tokens. Synthetic records and history retained in the isolated test database.\n";
} else {
    throw new RuntimeException('Use prepare or cleanup.');
}
