<?php

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DossierCompletionFixture;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$path = storage_path('framework/testing/dossier-pathology-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Clean up owned pathology fixture first.');
    }
    $f = DB::transaction(fn () => DossierCompletionFixture::make());
    app(DossierPathologyPermissionsSeeder::class)->run();
    $readRole = DB::table('roles')->insertGetId(['code' => 'PATH-READ-'.$f['tag'], 'name_ar' => 'قارئ تشريح اختباري']);
    DB::table('role_permissions')->insert(['role_id' => $readRole, 'permission_id' => DB::table('permissions')->where('code', 'dossiers.view')->value('id')]);
    DB::table('facility_user_roles')->insert(['user_id' => $f['viewer']->id, 'facility_id' => $f['facility'], 'role_id' => $readRole]);
    foreach (array_keys(DossierPathologyPermissionsSeeder::CODES) as $code) {
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
    }
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('pathology-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('pathology-live', ['api'])->plainTextToken;
    unset($f['user'], $f['viewer']);
    $f['before'] = [];
    foreach (['visit_services', 'visit_procedures', 'visit_medications', 'blood_bank_events', 'blood_donations', 'blood_transfusions', 'reporting_periods'] as $table) {
        $f['before'][$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared synthetic pathology fixture on guarded isolated MariaDB.\n";
} else {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($mode === 'verify') {
        foreach ($f['before'] as $table => $snapshot) {
            if ($snapshot !== DB::table($table)->orderBy('id')->get()->toJson()) {
                throw new RuntimeException('Unexpected domain write: '.$table);
            }
        }
        if (DB::table('visit_pathologies')->where('facility_id', $f['facility'])->count() < 3) {
            throw new RuntimeException('Missing saved pathology');
        }
        echo "Verified stored pathology and unchanged service/procedure/blood/dispensing/period facts.\n";
    } elseif ($mode === 'concurrency') {
        $v = DB::table('visits')->where('facility_id', $f['facility'])->whereNotNull('dossier_id')->orderByDesc('id')->first();
        if (! $v) {
            throw new RuntimeException('Save a synthetic visit before concurrency checks.');
        }
        $data = ['source' => 'internal', 'status' => 'requested', 'requested_on' => $v->visit_date, 'request_id' => (string) Str::uuid(), 'clinic_id' => $f['clinics'][0], 'doctor_id' => $f['workflow_doctors'][0]];
        $payload = ['user' => $f['user_id'], 'facility' => $f['facility'], 'dossier' => $v->dossier_id, 'visit' => $v->id, 'id' => null, 'data' => $data];
        $id = null;
        foreach (['replay', 'correction'] as $operation) {
            $workers = [];
            DB::beginTransaction();
            try {
                DB::table('patient_dossiers')->where('id', $v->dossier_id)->lockForUpdate()->first();
                foreach ([1, 2] as $number) {
                    $input = $payload;
                    if ($operation === 'correction') {
                        $input['id'] = $id;
                        $input['data'] += ['lock_version' => 1];
                        $input['data']['request_id'] = (string) Str::uuid();
                        $input['data']['note'] = 'concurrent correction '.$number;
                    }
                    $worker = new Process([PHP_BINARY, 'tests/Support/pathology-concurrency-worker.php'], base_path(), ['APP_ENV' => 'testing']);
                    $worker->setInput(json_encode($input, JSON_THROW_ON_ERROR));
                    $worker->setTimeout(20);
                    $worker->start();
                    $workers[] = $worker;
                    if (! $worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY'))) {
                        throw new RuntimeException('Worker not ready: '.$worker->getErrorOutput());
                    }
                }
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        throw new RuntimeException('Worker failed before lock release.');
                    }
                }
                DB::commit();
                $results = [];
                foreach ($workers as $worker) {
                    if ($worker->wait() !== 0) {
                        throw new RuntimeException('Worker failed: '.$worker->getErrorOutput());
                    }
                    $lines = explode("\n", trim($worker->getOutput()));
                    $results[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
                }
                $statuses = array_column($results, 'status');
                sort($statuses);
                if ($statuses !== ($operation === 'replay' ? [200, 200] : [200, 409])) {
                    throw new RuntimeException('Concurrency results differ: '.json_encode($results));
                }
                if ($operation === 'replay') {
                    if ($results[0]['id'] !== $results[1]['id']) {
                        throw new RuntimeException('UUID replay created duplicate pathology');
                    }
                    $id = $results[0]['id'];
                }
            } finally {
                if (DB::transactionLevel()) {
                    DB::rollBack();
                }
                foreach ($workers as $worker) {
                    $worker->stop();
                }
            }
        }
        echo "Verified two real competing database connections: UUID replay one ID; corrections 200/409.\n";
    } elseif ($mode === 'cleanup') {
        foreach ([$f['user_id'], $f['viewer_id']] as $id) {
            User::findOrFail($id)->tokens()->where('name', 'pathology-live')->delete();
        }
        unlink($path);
        echo "Revoked only owned fixture tokens; synthetic clinical data preserved.\n";
    } else {
        throw new RuntimeException('Unknown fixture operation');
    }
}
