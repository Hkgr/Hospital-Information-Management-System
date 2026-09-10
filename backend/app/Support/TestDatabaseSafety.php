<?php

namespace App\Support;

use Dotenv\Dotenv;
use Illuminate\Contracts\Foundation\Application;
use RuntimeException;
use Throwable;

class TestDatabaseSafety
{
    public static function assertAvailable(Application $app): void
    {
        self::assertSafe($app);
        try {
            $connection = $app['db']->connection('mysql');
            $selected = $connection->selectOne('SELECT DATABASE() AS selected_database');
            if ($selected->selected_database !== $app['config']->get('testing_safety.database')) {
                throw new RuntimeException('Unexpected database.');
            }
        } catch (Throwable) {
            // Fail before Laravel's migrate command can try to create a missing database.
            throw new RuntimeException('Confirmed MySQL test database is unavailable. Provision it separately; no database was created.');
        }
    }

    /** Validate configuration before any database connection or destructive test setup. */
    public static function assertSafe(Application $app): void
    {
        $config = $app['config'];
        $db = $config->get('database.connections.'.$config->get('database.default'), []);
        $name = $db['database'] ?? '';
        $host = $db['host'] ?? '';
        $primary = is_file($app->basePath('.env'))
            ? Dotenv::parse(file_get_contents($app->basePath('.env'))) : [];

        if (! $app->environment('testing')
            || $config->get('database.default') !== 'mysql'
            || ($db['driver'] ?? null) !== 'mysql'
            || ! is_string($name) || ! preg_match('/\A(?:test_[a-z0-9_]+|[a-z0-9_]+_testing)\z/i', $name)
            || ! empty($db['url']) || ! empty($db['unix_socket'])
            || ! empty($db['read']) || ! empty($db['write'])
            || strcasecmp($name, (string) ($primary['DB_DATABASE'] ?? '')) === 0
            || $config->get('testing_safety.confirmed') !== true
            || $host !== $config->get('testing_safety.host')
            || $name !== $config->get('testing_safety.database')) {
            throw new RuntimeException('Unsafe test database: require APP_ENV=testing, mysql, a separate *_testing/test_* database, no URL/socket/read/write overrides, and explicit TEST_DATABASE_CONFIRMED/HOST/NAME.');
        }
    }
}
