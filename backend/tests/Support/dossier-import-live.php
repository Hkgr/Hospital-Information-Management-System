<?php

use App\Http\Controllers\Api\DossierImportController;
use App\Models\User;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\Imports\ImportBatches;
use App\Services\Dossiers\Imports\ImportWorkbook;
use App\Support\TestDatabaseSafety;
use Database\Seeders\DossierImportPermissionsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Process\Process;
use Tests\Support\DossierCompletionFixture;
use Tests\Support\OncologyProcess;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
set_exception_handler(function (Throwable $e) {
    fwrite(STDERR, 'Import test fixture failed ('.get_class($e).'); no source values are logged.'.PHP_EOL);
    exit(1);
});
$path = storage_path('framework/testing/dossier-import-live.json');
$mode = $argv[1] ?? '';

function importSample(array $f, int $count, string $suffix, bool $duplicate = false): string
{
    $book = app(ImportWorkbook::class)->template(['id' => $f['facility']], 'legacy_migration', '2026-09-01', []);
    $positions = array_fill_keys(array_keys(ImportWorkbook::SHEETS), 3);
    $append = function (string $sheet, array $row) use ($book, &$positions) {
        foreach (ImportWorkbook::SHEETS[$sheet] as $i => $key) {
            if (isset($row[$key])) {
                $book->getSheetByName($sheet)->setCellValueExplicit([$i + 1, $positions[$sheet]], $row[$key], is_int($row[$key]) ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
            }
        }
        $positions[$sheet]++;
    };
    for ($i = 1; $i <= $count; $i++) {
        $existing = $i % 20 === 0;
        $p = 'P-'.$suffix.'-'.$i;
        $ambiguous = isset($f['ambiguous_alias']) && $i % 997 === 0;
        $append('Patients', ['source_record_id' => $f['tag'].'-'.($duplicate && $i === 2 ? 'P-'.$suffix.'-1' : $p), 'local_patient_ref' => $p, 'opening_date' => $existing ? $f['existing_opening'] : '1998-01-01',
            ...$ambiguous ? ['legacy_code' => $f['ambiguous_alias']] : ($existing ? ['patient_code' => $f['existing_code']] : ['first_name' => 'مريض اصطناعي', 'family_name' => 'تجربة '.$suffix.' '.$i, 'phone' => '001234567', 'birth_date_accuracy' => $i % 3 === 0 ? 'year_only' : 'unknown', 'birth_date' => $i % 3 === 0 ? '1980' : null, 'gender' => 'unknown', 'displacement_status' => 'unknown', 'import_note' => '=ملاحظة مصدر اصطناعية؛ ليست واقعة علاجية'])]);
        // Include genuine pre-/post-cutover and same-day visits, plus invalid refs.
        if ($i % 3 !== 0) {
            foreach ($i % 5 === 0 ? [1, 2] : [1] as $n) {
                $v = 'V-'.$suffix.'-'.$i.'-'.$n;
                $append('Visits', ['source_record_id' => $f['tag'].'-'.$v, 'local_patient_ref' => $p, 'local_visit_ref' => $v, 'visit_date' => $i % 2 === 0 ? '2026-09-02' : '2015-02-03', 'visit_type_id' => $i % 17 === 0 ? 999999999 : $f['visit_type'], 'is_referred' => 0]);
                if ($i % 10 === 0) {
                    $append('Services', ['source_record_id' => $f['tag'].'-S-'.$v, 'local_visit_ref' => $v, 'catalog_id' => $f['service'], 'clinic_id' => $f['clinics'][0], 'doctor_id' => $f['workflow_doctors'][0], 'note' => '=قيمة نصية اصطناعية']);
                }
            }
        }
    }
    $file = storage_path('framework/testing/import-'.$suffix.'.xlsx');
    (new Xlsx($book))->save($file);
    $book->disconnectWorksheets();

    return $file;
}

if ($mode === 'prepare') {
    if (is_file($path)) {
        throw new RuntimeException('Revoke the owned import fixture before preparing another.');
    }
    $f = DB::transaction(fn () => DossierCompletionFixture::make());
    app(DossierImportPermissionsSeeder::class)->run();
    foreach (array_keys(DossierImportPermissionsSeeder::CODES) as $code) {
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
    }
    $f['user_id'] = $f['user']->id;
    $f['viewer_id'] = $f['viewer']->id;
    $f['token'] = $f['user']->createToken('import-live', ['api'])->plainTextToken;
    $f['denied_token'] = $f['viewer']->createToken('import-live', ['api'])->plainTextToken;
    $f['existing_code'] = DB::table('patients')->where('id', $f['patients'][1])->value('patient_code');
    $f['existing_opening'] = DB::table('patient_dossiers')->where('id', $f['dossiers'][0])->value('opening_date');
    unset($f['user'], $f['viewer']);
    foreach ([390, 768, 1440] as $width) {
        $f['samples'][$width] = importSample($f, 21, 'live'.$width);
        $book = IOFactory::load($f['samples'][$width]);
        $sheet = $book->getSheetByName('Visits');
        $column = array_search('visit_type_id', ImportWorkbook::SHEETS['Visits'], true) + 1;
        for ($row = 3; $row <= $sheet->getHighestDataRow(); $row++) {
            $sheet->setCellValueExplicit([$column, $row], $f['visit_type'], DataType::TYPE_NUMERIC);
        }
        // Previously valid sources stay byte-for-byte equivalent after normalization.
        $f['corrected'][$width] = storage_path('framework/testing/import-corrected-'.$width.'.xlsx');
        (new Xlsx($book))->save($f['corrected'][$width]);
        $book->disconnectWorksheets();
    }
    file_put_contents($path, json_encode($f, JSON_THROW_ON_ERROR));
    echo "Prepared retained synthetic import fixture and three workbooks on guarded MariaDB.\n";
    exit;
}
$f = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
config(['clinics.doctor_staff_types' => ['DWF-DOCTOR']]);
if ($mode === 'cleanup') {
    foreach ([$f['user_id'], $f['viewer_id']] as $id) {
        User::findOrFail($id)->tokens()->where('name', 'import-live')->delete();
    }
    unlink($path);
    echo "Revoked only owned tokens; retained every synthetic clinical record and import batch.\n";
} elseif ($mode === 'verify') {
    $batches = DB::table('dossier_import_batches')->where('facility_id', $f['facility'])->get();
    if ($batches->count() < 3) {
        throw new RuntimeException('Expected all browser uploads.');
    }
    foreach ($batches as $batch) {
        if (! in_array($batch->status, ['completed_with_errors', 'completed'])) {
            throw new RuntimeException('Incomplete browser batch.');
        }
    }
    $rows = DB::table('dossier_import_rows')->where('facility_id', $f['facility'])->where('status', 'committed')->where('sheet', 'Patients')->get();
    foreach ($rows as $row) {
        if (! DB::table('patient_dossiers')->where('id', $row->dossier_id)->where('facility_id', $f['facility'])->exists()) {
            throw new RuntimeException('Missing committed context');
        }
    }
    echo "Verified browser commits and retained row provenance in MariaDB.\n";
} elseif ($mode === 'performance') {
    $f['ambiguous_alias'] = 'PERF-AMB-'.bin2hex(random_bytes(5));
    foreach ([$f['facility'], $f['other']] as $n => $facilityId) {
        $person = (array) DB::table('patients')->find($f['patients'][1]);
        unset($person['id']);
        $person['patient_code'] = $f['ambiguous_alias'].'-'.$n;
        $person['paper_file_number'] = null;
        $patientId = DB::table('patients')->insertGetId($person);
        DB::table('patient_dossiers')->insert(['facility_id' => $facilityId, 'patient_id' => $patientId, 'code' => $f['ambiguous_alias'], 'opening_date' => '1998-01-01', 'entered_by' => $f['user_id']]);
    }
    $metrics = ['patients' => 5000, 'driver' => DB::connection()->getDriverName(), 'database' => DB::connection()->getDatabaseName(), 'version' => DB::selectOne('SELECT VERSION() AS version')->version];
    $duplicate = importSample($f, 5000, 'duplicate-'.$f['tag'], true);
    try {
        app(ImportWorkbook::class)->read($duplicate, $f['facility'], 'legacy_migration', '2026-09-01');
        throw new RuntimeException('Duplicate source unexpectedly accepted.');
    } catch (ValidationException) {
        $metrics['duplicate_source_5000_variant'] = 'rejected before upload/storage';
    }
    $start = microtime(true);
    $sample = importSample($f, 5000, 'performance-'.$f['tag']);
    $metrics['generation_seconds'] = microtime(true) - $start;
    $metrics['bytes'] = filesize($sample);
    $user = User::findOrFail($f['user_id']);
    $r = Request::create('/api/dossiers/imports', 'POST');
    $r->setUserResolver(fn () => $user);
    $facility = app(DossierAccess::class)->facility($user, $f['facility'], 'import.commit');
    $service = app(ImportBatches::class);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    $start = microtime(true);
    $id = $service->upload($r, $facility, new UploadedFile($sample, 'performance.xlsx', test: true), 'legacy_migration', '2026-09-01');
    $metrics['upload_parse_seconds'] = microtime(true) - $start;
    $metrics['upload_queries'] = $queries;
    foreach (['validate', 'commit'] as $op) {
        $times = [];
        $queries = 0;
        do {
            $b = $service->batch($facility, $id);
            if ($op === 'validate' && ! in_array($b->status, ['uploaded', 'validating']) || $op === 'commit' && ! in_array($b->status, ['validated', 'committing'])) {
                break;
            }
            $start = microtime(true);
            $service->step($r, $facility, $id, $b->lock_version, $op);
            $times[] = microtime(true) - $start;
            if (count($times) % 50 === 0) {
                echo $op.' chunks: '.count($times)."\n";
            }
        } while (true);
        $metrics[$op] = ['chunks' => count($times), 'seconds' => array_sum($times), 'max_chunk_seconds' => $times ? max($times) : 0, 'queries' => $queries];
    }
    $queries = 0;
    $start = microtime(true);
    $result = $service->present($facility, $id);
    $metrics['preview'] = ['seconds' => microtime(true) - $start, 'queries' => $queries];
    $metrics['counts'] = $result['counts'];
    $metrics['status'] = $result['status'];
    $metrics['peak_memory_bytes'] = memory_get_peak_usage(true);
    file_put_contents(storage_path('framework/testing/import-performance.json'), json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} elseif ($mode === 'metrics') {
    $metricsFile = storage_path('framework/testing/import-performance.json');
    $metrics = json_decode(file_get_contents($metricsFile), true, flags: JSON_THROW_ON_ERROR);
    $sample = storage_path('framework/testing/import-performance-'.$f['tag'].'.xlsx');
    $start = microtime(true);
    $rows = app(ImportWorkbook::class)->read($sample, $f['facility'], 'legacy_migration', '2026-09-01');
    $metrics['final_parser'] = ['seconds' => microtime(true) - $start, 'rows' => count($rows)];
    unset($rows);
    $user = User::findOrFail($f['user_id']);
    $r = Request::create('/api/dossiers/imports', 'GET', ['facility_id' => $f['facility']]);
    $r->setUserResolver(fn () => $user);
    $facility = app(DossierAccess::class)->facility($user, $f['facility'], 'import.view');
    $id = DB::table('dossier_import_batches')->where('facility_id', $f['facility'])->where('file_hash', hash_file('sha256', $sample))->value('id');
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    foreach (['detail', 'list'] as $kind) {
        $queries = 0;
        $start = microtime(true);
        if ($kind === 'detail') {
            app(ImportBatches::class)->present($facility, $id);
        } else {
            app(DossierImportController::class)->index($r);
        }
        $metrics['final_'.$kind] = ['seconds' => microtime(true) - $start, 'queries' => $queries];
    }
    file_put_contents($metricsFile, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} elseif ($mode === 'concurrency') {
    $user = User::findOrFail($f['user_id']);
    $r = Request::create('/api/dossiers/imports', 'POST');
    $r->setUserResolver(fn () => $user);
    $facility = app(DossierAccess::class)->facility($user, $f['facility'], 'import.commit');
    $service = app(ImportBatches::class);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $sample = importSample($f, 3, 'race-'.bin2hex(random_bytes(4)));
        $id = $service->upload($r, $facility, new UploadedFile($sample, 'race.xlsx', test: true), 'legacy_migration', '2026-09-01');
        $b = $service->batch($facility, $id);
        $service->step($r, $facility, $id, $b->lock_version, 'validate');
        $b = $service->batch($facility, $id);
        $workers = [];
        DB::beginTransaction();
        try {
            DB::table('dossier_import_batches')->where('id', $id)->lockForUpdate()->first();
            foreach ([1, 2] as $n) {
                $worker = new Process([PHP_BINARY, 'tests/Support/import-concurrency-worker.php'], base_path(), ['APP_ENV' => 'testing'], json_encode(['batch' => $id, 'facility' => $f['facility'], 'version' => $b->lock_version, 'token' => $f['token']]), 30);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                OncologyProcess::waitUntilReady($worker);
            }
            DB::commit();
            $statuses = [];
            foreach ($workers as $worker) {
                $worker->wait();
                if ($worker->getExitCode() !== 0 || ! preg_match('/STATUS:(\d+)/', $worker->getOutput(), $match)) {
                    throw new RuntimeException('Import concurrency worker failed without exposing its payload.');
                }
                $statuses[] = (int) $match[1];
            }
            sort($statuses);
            if ($statuses !== [200, 409]) {
                throw new RuntimeException('Expected one commit and one stale-version conflict: '.json_encode($statuses));
            }
            if (DB::table('dossier_import_rows')->where('batch_id', $id)->where('status', 'committed')->count() !== 5) {
                throw new RuntimeException('Concurrency committed an unexpected number of source facts.');
            }
            echo "Concurrent import attempt $attempt: 200 / 409; five source rows committed once.\n";
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }
}
