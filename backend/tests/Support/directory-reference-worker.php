<?php

// Test-only separate connection: commit a reference while the HTTP delete waits
// for its parent FK lock. Never reads development or production configuration.
use App\Support\TestDatabaseSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
TestDatabaseSafety::assertSafe($app);
DB::transaction(function () use ($argv) {
    DB::table('clinic_staff')->insert(['staff_id' => (int) $argv[1], 'clinic_id' => (int) $argv[2], 'starts_on' => '2020-01-01']);
    echo "REFERENCE_LOCKED\n";
    flush();
    // Give the parent process time to issue DELETE before this FK lock releases.
    usleep(800000);
});
