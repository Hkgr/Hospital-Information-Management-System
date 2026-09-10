<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        // This runs before RefreshDatabase or any other test traits.
        TestDatabaseSafety::assertSafe($app);

        return $app;
    }
}
