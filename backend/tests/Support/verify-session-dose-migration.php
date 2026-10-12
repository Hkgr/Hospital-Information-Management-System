<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$migration = require database_path('migrations/2026_10_12_000002_extend_session_dose_identity.php');
$columns = array_values(array_diff(Schema::getColumnListing('oncology_session_doses'), ['medication_id', 'nurse_name']));
$snapshot = fn () => hash('sha256', DB::table('oncology_session_doses')->orderBy('id')->get($columns)->toJson());
$before = $snapshot();
$migration->down();
$migration->up();
if ($snapshot() !== $before) {
    throw new RuntimeException('Migration changed existing session-dose fields or IDs.');
}
echo 'PASS: populated MariaDB rollback/upgrade preserves all historical columns and IDs.'.PHP_EOL;
$old = DB::table('oncology_session_doses')->whereNotNull('nurse_id')->first();
if (! $old) {
    throw new RuntimeException('A historical session-dose fixture is required.');
}
DB::beginTransaction();
try {
    $new = (array) $old;
    unset($new['id']);
    $new['nurse_id'] = null;
    $new['nurse_name'] = 'New free nurse';
    $new['client_request_id'] = (string) Str::uuid();
    $id = DB::table('oncology_session_doses')->insertGetId($new);
    $withNew = $snapshot();
    try {
        $migration->down();
        throw new LogicException('Rollback must refuse free names before DDL.');
    } catch (RuntimeException $e) {
        if (! str_starts_with($e->getMessage(), 'Cannot roll back session-dose identity:')) {
            throw $e;
        }
    }
    if ($snapshot() !== $withNew || DB::table('oncology_session_doses')->where('id', $id)->value('nurse_name') !== 'New free nurse') {
        throw new RuntimeException('Blocked rollback changed a record.');
    }
    echo 'PASS: new free-name record is accepted; used rollback is blocked without deleting records.'.PHP_EOL;
    foreach (['nurse_id' => DB::table('staff')->max('id') + 1000000, 'medication_id' => DB::table('medications')->max('id') + 1000000] as $field => $invalid) {
        $bad = $new;
        $bad[$field] = $invalid;
        $bad['client_request_id'] = (string) Str::uuid();
        try {
            DB::table('oncology_session_doses')->insert($bad);
            throw new LogicException('The foreign key must reject an unknown '.$field);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1452) {
                throw $e;
            }
        }
    }
    echo 'PASS: historical nurse and new medication FKs reject unknown records.'.PHP_EOL;
} finally {
    DB::rollBack();
}
if ($snapshot() !== $before) {
    throw new RuntimeException('Verification fixture was not rolled back.');
}
