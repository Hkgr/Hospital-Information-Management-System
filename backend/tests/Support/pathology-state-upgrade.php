<?php

// Guarded verification for an existing test DB that already ran this unmerged migration.
// Adds only its new CHECK clauses. Never deletes/normalizes retained clinical data.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$migration = require database_path('migrations/2026_09_20_000001_add_visit_pathology_workflow.php');
$before = [];
foreach (['patients', 'patient_dossiers', 'visits', 'visit_pathologies', 'visit_diagnostic_assessments'] as $table) {
    $before[$table] = hash('sha256', DB::table($table)->orderBy('id')->get()->toJson());
}
$links = DB::table('pathology_attachments')->orderBy('pathology_id')->orderBy('attachment_id')->get()->toJson();
foreach ($migration->stateConstraints() as $table => $constraints) {
    foreach ($constraints as $name => $expression) {
        if (DB::table($table)->whereRaw("NOT ($expression)")->exists()) {
            throw new RuntimeException("Existing data incompatible with $name; no repair or deletion allowed.");
        }
    }
}
foreach ($migration->stateConstraints() as $table => $constraints) {
    foreach ($constraints as $name => $expression) {
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->exists();
        if (! $exists) {
            DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($expression)");
        }
        echo "$name present\n";
    }
}
foreach ($before as $table => $digest) {
    if ($digest !== hash('sha256', DB::table($table)->orderBy('id')->get()->toJson())) {
        throw new RuntimeException("Rows changed: $table");
    }
}
if ($links !== DB::table('pathology_attachments')->orderBy('pathology_id')->orderBy('attachment_id')->get()->toJson()) {
    throw new RuntimeException('Attachment links changed');
}
if (DB::table('visit_pathologies')->exists() || DB::table('visit_diagnostic_assessments')->exists()) {
    try {
        $migration->down();
        throw new LogicException('Populated rollback unexpectedly allowed');
    } catch (RuntimeException $e) {
        if (! str_contains($e->getMessage(), 'must be preserved')) {
            throw $e;
        }
    }
    echo "Populated rollback refused before DDL.\n";
}
echo "All retained row digests and attachment links unchanged.\n";
