<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        if (defined('PRESERVE_TEST_DATABASE') && ! RefreshDatabaseState::$migrated) {
            throw new \RuntimeException('Preserved test database lost its transaction boundary (possibly implicit DDL commit). Refusing any automatic refresh; isolate that test.');
        }
        $app = parent::createApplication();
        // This runs before RefreshDatabase or any other test traits.
        TestDatabaseSafety::assertSafe($app);

        return $app;
    }
}
