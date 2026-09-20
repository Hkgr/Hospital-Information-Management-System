<?php

use App\Models\User;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\OncologyQueries;
use App\Support\TestDatabaseSafety;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Database\Seeders\OncologyPermissionsSeeder;
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
$path = storage_path('framework/testing/oncology-live.json');
$mode = $argv[1] ?? '';
if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Revoke prior owned oncology fixture tokens before another run.');
    }
    $f = DB::transaction(fn () => DossierCompletionFixture::make());
    foreach ([DossierPathologyPermissionsSeeder::class, OncologyPermissionsSeeder::class] as $seeder) {
        app($seeder)->run();
        foreach (array_keys($seeder::CODES) as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
    }
    $f['funding'] = DB::table('funding_sources')->insertGetId(['code' => 'ONC-LIVE-'.$f['tag'], 'name_ar' => 'تمويل اصطناعي للتحقق']);
    $f['period'] = DB::table('reporting_periods')->insertGetId(['facility_id' => $f['facility'], 'starts_on' => '2001-01-01', 'ends_on' => '2001-12-31', 'status' => 'open']);
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('oncology-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('oncology-live', ['api'])->plainTextToken;
    unset($f['user'], $f['viewer']);
    $f['before'] = [];
    foreach (['visit_prescriptions', 'visit_services', 'visit_procedures', 'blood_bank_events', 'blood_transfusions', 'reporting_periods'] as $table) {
        $f['before'][$table] = DB::table($table)->orderBy('id')->get()->toJson();
    }
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared synthetic oncology fixture on isolated MariaDB; no production data.\n";
} else {
    $f = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($mode === 'verify') {
        foreach ($f['before'] as $table => $before) {
            if ($before !== DB::table($table)->orderBy('id')->get()->toJson()) {
                throw new RuntimeException('Unexpected write: '.$table);
            }
        }
        if (! DB::table('oncology_plans')->where('facility_id', $f['facility'])->exists() || ! DB::table('dose_sessions')->where('facility_id', $f['facility'])->whereNotNull('oncology_session_id')->exists()) {
            throw new RuntimeException('Missing actual oncology facts.');
        }
        echo "Verified actual stored oncology facts and unchanged other clinical domains/periods.\n";
    } elseif ($mode === 'concurrency') {
        $access = app(DossierAccess::class)->facility(User::findOrFail($f['user_id']), $f['facility'], 'treatment.administer');
        $s = app(OncologyQueries::class)->scheduled($access)->orderBy('s.id')->first(['s.*']);
        if (! $s) {
            throw new RuntimeException('Expected an active synthetic scheduled session.');
        }
        $v = DB::table('visits')->where('dossier_id', $s->dossier_id)->where('facility_id', $f['facility'])->whereNull('voided_at')->first();
        $payload = ['user' => $f['user_id'], 'facility' => $f['facility'], 'dossier' => $s->dossier_id, 'visit' => $v->id, 'id' => null,
            'data' => ['request_id' => (string) Str::uuid(), 'session_id' => $s->id, 'session_lock_version' => $s->lock_version, 'plan_lock_version' => DB::table('oncology_plans')->where('id', $s->plan_id)->value('lock_version'), 'visit_lock_version' => $v->lock_version, 'administered_on' => $v->visit_date, 'supervising_staff_id' => $s->doctor_id, 'administered_by' => $s->doctor_id, 'reporting_period_id' => $f['period'], 'items' => [['medication_id' => $f['medication'], 'dose_value' => '1', 'dose_unit' => 'mg', 'quantity' => '1', 'quantity_unit' => 'vial', 'route' => 'IV', 'funding_source_id' => $f['funding']]]]];
        $id = null;
        foreach (['replay', 'correction'] as $op) {
            $workers = [];
            DB::beginTransaction();
            try {
                DB::table('patient_dossiers')->where('id', $s->dossier_id)->lockForUpdate()->first();
                foreach ([1, 2] as $n) {
                    $data = $payload;
                    if ($op === 'correction') {
                        $data['id'] = $id;
                        $data['data'] = array_replace($data['data'], ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'items' => [], 'reason' => 'تصحيح اصطناعي متزامن', 'note' => 'author '.$n]);
                    }
                    $worker = new Process([PHP_BINARY, 'tests/Support/oncology-concurrency-worker.php'], base_path(), ['APP_ENV' => 'testing']);
                    $worker->setInput(json_encode($data, JSON_THROW_ON_ERROR));
                    $worker->setTimeout(30);
                    $worker->start();
                    $workers[] = $worker;
                    if (! $worker->waitUntil(fn ($type, $output) => str_contains($output, 'READY'))) {
                        throw new RuntimeException('Worker did not become ready: '.$worker->getErrorOutput());
                    }
                }
                DB::commit();
                $rows = [];
                foreach ($workers as $worker) {
                    if ($worker->wait() !== 0) {
                        throw new RuntimeException('Worker failed: '.$worker->getErrorOutput().' '.$worker->getOutput());
                    }
                    $lines = explode("\n", trim($worker->getOutput()));
                    $rows[] = json_decode(end($lines), true, 512, JSON_THROW_ON_ERROR);
                }
                $statuses = array_column($rows, 'status');
                sort($statuses);
                if ($statuses !== ($op === 'replay' ? [200, 200] : [200, 409])) {
                    throw new RuntimeException('Unexpected concurrent results: '.json_encode($rows));
                }
                if ($op === 'replay') {
                    if ($rows[0]['id'] !== $rows[1]['id']) {
                        throw new RuntimeException('Duplicate actual dose');
                    } $id = $rows[0]['id'];
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
        echo "Two competing real connections: replay one dose ID (200/200); correction 200/409.\n";
    } elseif ($mode === 'cleanup') {
        foreach ([$f['user_id'], $f['viewer_id']] as $id) {
            User::findOrFail($id)->tokens()->where('name', 'oncology-live')->delete();
        }
        unlink($path);
        echo "Revoked owned fixture tokens; synthetic clinical history retained.\n";
    } else {
        throw new RuntimeException('Unknown operation.');
    }
}
