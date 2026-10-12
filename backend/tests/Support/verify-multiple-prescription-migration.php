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
$migration = require database_path('migrations/2026_10_12_000001_allow_multiple_visit_prescriptions_per_kind.php');
$snapshot = fn () => hash('sha256', DB::table('visit_prescriptions')->orderBy('id')->get()->toJson().DB::table('visit_prescription_items')->orderBy('id')->get()->toJson());
$before = $snapshot();
// Existing populated isolated database, no fresh/reset/reseed or fabricated history.
$migration->down();
$migration->up();
if ($before !== $snapshot()) {
    throw new RuntimeException('Upgrade/rollback changed existing headers or items.');
}
echo 'PASS: populated rollback/upgrade preserves every header/item field and ID.'.PHP_EOL;
$old = DB::table('visit_prescriptions')->whereNull('voided_at')->orderBy('id')->first();
if (! $old) {
    throw new RuntimeException('This verification requires an existing active test invoice.');
}
DB::beginTransaction();
try {
    $copy = (array) $old;
    unset($copy['id'], $copy['active_kind_key']);
    $copy['client_request_id'] = (string) Str::uuid();
    DB::table('visit_prescriptions')->insert($copy);
    $withDuplicate = $snapshot();
    try {
        $migration->down();
        throw new LogicException('Unsafe rollback unexpectedly succeeded.');
    } catch (RuntimeException $e) {
        if (! str_starts_with($e->getMessage(), 'Cannot restore one invoice per kind:')) {
            throw $e;
        }
    }
    if ($withDuplicate !== $snapshot()) {
        throw new RuntimeException('Blocked rollback changed data.');
    }
    echo 'PASS: rollback refuses duplicate active kinds before DDL or data writes.'.PHP_EOL;
} finally {
    DB::rollBack();
}
if ($before !== $snapshot()) {
    throw new RuntimeException('Verification fixture did not roll back cleanly.');
}
