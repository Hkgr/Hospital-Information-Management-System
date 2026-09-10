<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
            $this->assertTrue(Schema::hasTable('cache'));
            $this->assertTrue(Schema::hasTable('cache_locks'));
            // Exercise the real named limiter with the production-default database store.
            config(['cache.default' => 'database']);
            Cache::forgetDriver('database');
            $this->assertInstanceOf(DatabaseStore::class, Cache::store()->getStore());
            $originalLimiter = $this->app->make(\Illuminate\Cache\RateLimiter::class);
            $limiter = new \Illuminate\Cache\RateLimiter(Cache::store('database'));
            $limiter->for('login', $originalLimiter->limiter('login'));
            $this->app->instance(\Illuminate\Cache\RateLimiter::class, $limiter);
            RateLimiter::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);
            try {
                $lock = Cache::lock('auth-migration-test', 10);
                $this->assertTrue($lock->get());
                $this->assertDatabaseCount('cache_locks', 1);
                $lock->release();
                $this->postJson('/api/login', ['username' => 'demo', 'password' => 'password'])
                    ->assertOk()->assertJsonPath('data.token_type', 'Bearer');
                $this->assertDatabaseCount('personal_access_tokens', 1);
                $this->assertGreaterThan(0, DB::table('cache')->count());
                for ($attempt = 0; $attempt < 4; $attempt++) {
                    $this->postJson('/api/login', ['username' => 'demo', 'password' => 'wrong'])->assertUnauthorized();
                }
                $this->postJson('/api/login', ['username' => ' DEMO ', 'password' => 'wrong'])->assertStatus(429);
            } finally {
                // Both stores are test-only; do not leak limiter state to later tests.
                Cache::store('database')->flush();
                $this->app->instance(\Illuminate\Cache\RateLimiter::class, $originalLimiter);
                RateLimiter::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);
                Cache::store('array')->flush();
                config(['cache.default' => 'array']);
            }
            $this->artisan('migrate:rollback', ['--env' => 'testing'])->assertSuccessful();
            $this->assertFalse(Schema::hasTable('personal_access_tokens'));
            $this->assertFalse(Schema::hasTable('users'));
            $this->assertFalse(Schema::hasTable('cache'));
            $this->assertFalse(Schema::hasTable('cache_locks'));
            $this->artisan('migrate', ['--seed' => true, '--env' => 'testing'])->assertSuccessful();
            $this->assertTrue(Schema::hasTable('personal_access_tokens'));
            $this->assertDatabaseCount('users', 1);
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }
}
