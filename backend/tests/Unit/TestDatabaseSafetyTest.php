<?php

namespace Tests\Unit;

use App\Support\TestDatabaseSafety;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseSafetyTest extends TestCase
{
    public static function unsafeSettings(): array
    {
        return [
            'production environment' => ['env', 'production'],
            'wrong driver' => ['database.connections.mysql.driver', 'pgsql'],
            'development database' => ['database.connections.mysql.database', 'hospital'],
            'unconfirmed' => ['testing_safety.confirmed', false],
            'unexpected host' => ['database.connections.mysql.host', 'unexpected.example'],
            'unexpected name' => ['database.connections.mysql.database', 'other_testing'],
            'url override' => ['database.connections.mysql.url', 'mysql://example/hospital_testing'],
            'socket override' => ['database.connections.mysql.unix_socket', '/tmp/mysql.sock'],
            'read override' => ['database.connections.mysql.read', ['host' => 'unexpected.example']],
            'write override' => ['database.connections.mysql.write', ['host' => 'unexpected.example']],
        ];
    }

    private function application(): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'testing');
        $app->instance('config', new Repository([
            'database' => ['default' => 'mysql', 'connections' => ['mysql' => [
                'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'hospital_testing',
            ]]],
            'testing_safety' => ['confirmed' => true, 'host' => '127.0.0.1', 'database' => 'hospital_testing'],
        ]));

        return $app;
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_settings_are_rejected_before_connecting(string $key, mixed $value): void
    {
        $app = $this->application();
        if ($key === 'env') {
            $app->instance('env', $value);
        } else {
            $app['config']->set($key, $value);
        }
        $this->expectException(RuntimeException::class);
        TestDatabaseSafety::assertSafe($app);
    }

    public function test_explicitly_confirmed_mysql_testing_configuration_passes_without_connecting(): void
    {
        TestDatabaseSafety::assertSafe($this->application());
        $this->addToAssertionCount(1);
    }
}
