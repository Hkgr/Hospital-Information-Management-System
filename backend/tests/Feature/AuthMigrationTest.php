<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AuthMigrationTest extends TestCase
{
    // DDL must not run inside RefreshDatabase's transaction: MySQL commits DDL.
    public function test_mysql_fresh_seed_full_rollback_and_remigrate(): void
    {
        TestDatabaseSafety::assertSafe($this->app);
        RefreshDatabaseState::$migrated = false;
        try {
            $this->artisan('migrate:fresh', ['--seed' => true, '--env' => 'testing'])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('personal_access_tokens'));
            $this->assertTrue(Schema::hasColumns('personal_access_tokens', [
                'tokenable_id', 'tokenable_type', 'name', 'token', 'abilities', 'last_used_at', 'expires_at',
            ]));
            $this->assertNotNull(User::where('username', 'demo')->first());
            $this->artisan('migrate:rollback', ['--env' => 'testing'])->assertSuccessful();
            $this->assertFalse(Schema::hasTable('personal_access_tokens'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->artisan('migrate', ['--seed' => true, '--env' => 'testing'])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('personal_access_tokens'));
            $this->assertDatabaseCount('users', 1);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
