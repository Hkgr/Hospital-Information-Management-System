<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$tables = ['oncology_sessions', 'dose_sessions', 'dose_session_items', 'visit_medications', 'oncology_plan_revisions'];
$snapshot = [];
$columns = [];
foreach ($tables as $table) {
    $columns[$table] = array_values(array_diff(DB::getSchemaBuilder()->getColumnListing($table), ['oncology_plan_id', 'active_oncology_session_id', 'active_plan_revision_id']));
    $snapshot[$table] = hash('sha256', DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson());
}
$migration = require database_path('migrations/2026_09_21_000001_preserve_voided_oncology_administrations.php');
$activeRevision = require database_path('migrations/2026_09_21_000002_constrain_active_oncology_revision.php');
$activeRevision->down();
echo "Active-revision constraint rollback passed without changing medical values.\n";
try {
    $migration->down();
    echo "Safe populated rollback passed.\n";
    $migration->up();
    echo "Populated re-upgrade passed.\n";
} catch (RuntimeException $e) {
    if (! str_contains($e->getMessage(), 'rollback refused:')) {
        throw $e;
    }
    echo "Unsafe rollback refused before DDL; historical attempts/revisions retained.\n";
} finally {
    $activeRevision->up();
    echo "Active-revision constraint re-upgrade passed.\n";
}
foreach ($snapshot as $table => $digest) {
    if ($digest !== hash('sha256', DB::table($table)->select($columns[$table])->orderBy('id')->get()->toJson())) {
        throw new RuntimeException('Retained medical values changed: '.$table);
    }
}
echo "SHA-256 ordered snapshots unchanged in all five affected historical tables.\n";
