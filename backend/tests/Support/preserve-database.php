<?php

use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

// Explicit opt-in bootstrap for running transaction-based regressions against
// an existing isolated database. No schema setup, reseed, fresh or rollback.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertAvailable($app);
$migrator = $app->make('migrator');
$pending = array_diff(array_keys($migrator->getMigrationFiles(database_path('migrations'))), $migrator->getRepository()->getRan());
if ($pending) {
    throw new RuntimeException('Apply reviewed additive migrations to the isolated test database first. This runner never migrates.');
}
RefreshDatabaseState::$migrated = true;
// PHPUnit captures its own handler stack after bootstrap. The temporary console
// application must not leave Laravel handlers under every test application.
restore_error_handler();
restore_exception_handler();
