<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
try {
    TestDatabaseSafety::assertAvailable($app);
    $tables = ['patients', 'patient_dossiers', 'visits', 'cancer_cases', 'cancer_case_diagnoses', 'cancer_treatments', 'dose_sessions', 'dose_session_items', 'visit_medications', 'visit_prescriptions', 'visit_prescription_items', 'visit_pathologies', 'visit_diagnostic_assessments'];
    $before = [];
    $columns = [];
    foreach ($tables as $table) {
        $columns[$table] = DB::getSchemaBuilder()->getColumnListing($table);
        $before[$table] = hash('sha256', DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson());
    }
    $path = 'database/migrations/2026_09_20_000002_add_oncology_plans_and_sessions.php';
    $exit = Artisan::call('migrate', ['--path' => $path, '--force' => true]);
    echo Artisan::output();
    if ($exit) {
        throw new RuntimeException('Additive migration failed.');
    }
    $migration = require base_path($path);
    if (DB::table('oncology_plans')->exists()) {
        try {
            $migration->down();
            throw new LogicException('Populated rollback unexpectedly succeeded');
        } catch (RuntimeException $e) {
            if (! str_contains($e->getMessage(), 'must be preserved')) {
                throw $e;
            }
        }
        echo "Populated rollback refused before DDL.\n";
    } else {
        $migration->down();
        echo "Empty new-structure rollback passed; retained legacy facts remain.\n";
        $migration->up();
        echo "Re-upgrade passed.\n";
    }
    foreach ($before as $table => $digest) {
        if ($digest !== hash('sha256', DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson())) {
            throw new RuntimeException("Retained values changed: $table");
        }
    }
    echo "All retained columns and rows unchanged.\n";
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
