<?php

namespace App\Console\Commands;

use App\Support\TestDatabaseSafety;
use Illuminate\Console\Command;

class CheckTestDatabase extends Command
{
    protected $signature = 'test-db:check {--connect : Verify the selected database with a read-only query after the safety check}';

    protected $description = 'Print non-secret test database settings and refuse unsafe configurations';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'), []);
        $this->table(['APP_ENV', 'Driver', 'Database', 'Host'], [[
            app()->environment(), $db['driver'] ?? '', $db['database'] ?? '', $db['host'] ?? '',
        ]]);
        TestDatabaseSafety::assertSafe(app());
        if ($this->option('connect')) {
            TestDatabaseSafety::assertAvailable(app());
        }
        $this->info('Safety check passed. This does not create or modify a database.');

        return self::SUCCESS;
    }
}
