<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\ClinicPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\BloodBankFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/blood-bank-live.json');
if (($argv[1] ?? '') === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Cleanup the previous synthetic run first.');
    }
    $f = DB::transaction(function () {
        $f = BloodBankFixture::make();
        app(ClinicPermissionsSeeder::class)->run();
        app(DoctorPermissionsSeeder::class)->run();
        $role = DB::table('global_user_roles')->where('user_id', $f['user']->id)->value('role_id');
        foreach (DB::table('permissions')->where(fn ($q) => $q->whereLike('code', 'clinics.%')->orWhereLike('code', 'doctors.%'))->pluck('id') as $p) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $p]);
        }
        $f['second'] = DB::table('facilities')->insertGetId(['code' => 'ZZZ-BB-SECOND-'.$f['tag'], 'name_ar' => 'منشأة بنك دم اختبارية ثانية', 'timezone' => 'Asia/Damascus']);
        DB::table('facility_user_roles')->insert(['facility_id' => $f['second'], 'user_id' => $f['user']->id, 'role_id' => $role]);
        $f['token'] = $f['user']->createToken('blood-bank-live', ['api'])->plainTextToken;
        $f['viewer_token'] = $f['viewer']->createToken('blood-bank-live', ['api'])->plainTextToken;
        $f['user_id'] = $f['user']->id;
        $f['viewer_id'] = $f['viewer']->id;
        $f['profile'] = BloodBankFixture::profile($f);
        $f['counts'] = [];
        foreach (['patients', 'visits', 'blood_donations', 'blood_transfusions', 'blood_donation_screenings', 'blood_recipient_procedures', 'visit_services', 'visit_procedures'] as $t) {
            $f['counts'][$t] = DB::table($t)->count();
        }
        unset($f['user'], $f['viewer']);

        return $f;
    });
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared isolated blood-bank records; credentials remain in ignored local storage.\n";
} elseif (($argv[1] ?? '') === 'verify') {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    foreach ($f['counts'] as $t => $count) {
        if ($t !== 'blood_donations' && DB::table($t)->count() !== $count) {
            throw new RuntimeException("Unexpected clinical changes: $t");
        }
    }
    if (DB::table('blood_recipients')->where('facility_id', $f['facility'])->whereNotNull('patient_id')->whereNotNull('first_name')->exists()) {
        throw new RuntimeException('Copied linked patient identity.');
    }
    $donations = DB::table('blood_donations')->where('facility_id', $f['facility'])->get();
    foreach ($donations as $d) {
        if (! DB::table('blood_donation_codes')->where('blood_donation_id', $d->id)->where('code', $d->donation_code)->exists() || $d->status !== 'pending') {
            throw new RuntimeException('Invalid persisted donation.');
        }
    }
    echo 'Verified saved profiles, patient non-copy, code registry and unchanged clinical counts; donations: '.$donations->count()."\n";
} elseif (($argv[1] ?? '') === 'cleanup') {
    if (is_file($path)) {
        $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        foreach (['user_id', 'viewer_id'] as $key) {
            $u = User::findOrFail($f[$key]);
            if (! str_starts_with($u->username, 'catalog-') || ! str_ends_with($u->username, $f['tag'])) {
                throw new RuntimeException('Not this synthetic user.');
            }
            $u->tokens()->where('name', 'blood-bank-live')->delete();
        }
        unlink($path);
    }
    echo "Revoked owned test tokens; synthetic history remains in the isolated database.\n";
} else {
    throw new RuntimeException('Use prepare, verify or cleanup.');
}
