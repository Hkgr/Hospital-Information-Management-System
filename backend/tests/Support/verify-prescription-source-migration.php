<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$migration = require database_path('migrations/2026_10_15_000001_unify_prescription_medication_sources.php');
$snapshot = fn () => hash('sha256', json_encode(array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->all(), ['funding_sources', 'patient_dossiers', 'visit_prescriptions', 'oncology_session_doses', 'dose_session_items', 'visit_medications']), JSON_THROW_ON_ERROR));
$before = $snapshot();
$tables = ['funding_sources', 'patient_dossiers', 'visit_prescriptions', 'oncology_session_doses', 'dose_session_items', 'visit_medications'];
$schema = fn () => hash('sha256', json_encode(array_map(fn ($table) => preg_replace('/AUTO_INCREMENT=\d+/', '', (array) DB::selectOne("SHOW CREATE TABLE `$table`")), $tables), JSON_THROW_ON_ERROR));
$originalSchema = $schema();
try {
    $migration->down();
    $migration->up();
    echo 'PASS populated downgrade/upgrade executed.'.PHP_EOL;
} catch (RuntimeException $e) {
    if (! str_starts_with($e->getMessage(), 'Rollback refused:') || $schema() !== $originalSchema) {
        throw $e;
    }
    // Never modify post-feature data to manufacture a successful downgrade.
    echo 'PASS incompatible populated rollback refused; schema unchanged. Downgrade/upgrade cycle not attempted on this data.'.PHP_EOL;
}
if ($snapshot() !== $before) {
    throw new RuntimeException('Populated upgrade/rollback changed historical rows or source IDs.');
}
echo "PASS historical row contents and source IDs preserved.\n";
DB::beginTransaction();
try {
    $row = DB::table('visit_prescriptions')->where('kind', 'unlinked')->first();
    if (! $row) {
        throw new RuntimeException('A populated historical prescription is required.');
    }
    DB::table('visit_prescriptions')->where('id', $row->id)->update(['funding_source_id' => DB::table('funding_sources')->value('id')]);
    $used = $snapshot();
    try {
        $migration->down();
        throw new LogicException('Rollback should refuse a source on the formerly unfunded kind.');
    } catch (RuntimeException $e) {
        if (! str_starts_with($e->getMessage(), 'Rollback refused:')) {
            throw $e;
        }
    }
    if ($snapshot() !== $used || $schema() !== $originalSchema) {
        throw new RuntimeException('Refused rollback modified data.');
    }
    echo "PASS used rollback refused before DDL; no relationship or record removed.\n";
} finally {
    DB::rollBack();
}
DB::beginTransaction();
try {
    $approved = json_decode(file_get_contents(database_path('data/medication-sources.json')), true, flags: JSON_THROW_ON_ERROR);
    DB::table('funding_sources')->insert(['code' => 'AMB-'.Str::random(8), 'name_ar' => $approved[0]['name_ar'], 'is_active' => true]);
    $ambiguous = $snapshot();
    try {
        $migration->up();
        throw new LogicException('Ambiguous reference must fail before any DDL or definitions are written.');
    } catch (RuntimeException $e) {
        if (! str_starts_with($e->getMessage(), 'Ambiguous funding source')) {
            throw $e;
        }
    }
    if ($snapshot() !== $ambiguous || $schema() !== $originalSchema) {
        throw new RuntimeException('Ambiguous upgrade changed data.');
    }
    echo "PASS ambiguous upgrade blocked without changing data or schema.\n";
} finally {
    DB::rollBack();
}
if ($snapshot() !== $before) {
    throw new RuntimeException('Verification did not restore its temporary fixtures.');
}
