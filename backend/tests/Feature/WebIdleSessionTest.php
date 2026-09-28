<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebIdleSessionTest extends TestCase
{
    use RefreshDatabase;

    private function callToken(string $token, string $path = 'user', string $method = 'GET')
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, [], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_login_always_creates_managed_web_session_and_background_reads_never_extend(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $login = $this->postJson('/api/login', ['username' => $user->username, 'password' => 'password', 'device_name' => 'integration-does-not-bypass'])->assertOk();
        $token = $login->json('data.token');
        $deadline = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('web_idle_deadline');
        $this->travel(119)->seconds();
        $this->callToken($token)->assertOk();
        $this->callToken($token, 'session')->assertOk()->assertJsonPath('data.remaining_seconds', 1);
        $this->assertSame($deadline, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->value('web_idle_deadline'));
        $this->travel(1)->seconds();
        $this->callToken($token)->assertUnauthorized()->assertJsonPath('error.code', 'SESSION_IDLE_EXPIRED');
        $this->callToken($token, 'session/activity', 'POST')->assertUnauthorized();
        $this->travel(20)->seconds();
        $this->callToken($token)->assertUnauthorized();
    }

    public function test_activity_renews_only_live_token_and_devices_are_independent(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $tokens = [];
        foreach (['one', 'two'] as $name) {
            $t = $user->createToken($name, ['api']);
            $t->accessToken->forceFill(['web_idle_deadline' => now()->addSeconds(120)])->save();
            $tokens[] = $t->plainTextToken;
        }
        $this->travel(100)->seconds();
        $this->callToken($tokens[0], 'session/activity', 'POST')->assertOk()->assertJsonPath('data.remaining_seconds', 120);
        $this->travel(20)->seconds();
        $this->callToken($tokens[1])->assertUnauthorized();
        $this->callToken($tokens[0])->assertOk();
        $this->travel(100)->seconds();
        $this->callToken($tokens[0], 'session/activity', 'POST')->assertUnauthorized();
        $this->assertSame(2, DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->whereNotNull('web_expired_at')->count());
    }

    public function test_expiration_is_audited_once_and_super_admin_has_no_idle_bypass(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId(['code' => 'IDLE-'.Str::random(12), 'name_ar' => 'اختبار الخمول', 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->insertGetId(['code' => 'IDLE-'.Str::random(12), 'name_ar' => 'مشرف اختبار', 'is_system_super_admin' => true]);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $facility, 'role_id' => $role]);
        $token = $user->createToken('web', ['api']);
        $token->accessToken->forceFill(['web_idle_deadline' => now()])->save();
        foreach (['user', 'session', 'session/activity'] as $path) {
            $this->callToken($token->plainTextToken, $path, str_ends_with($path, 'activity') ? 'POST' : 'GET')->assertUnauthorized();
        }
        $this->assertSame(1, DB::table('audit_logs')->where('actor_id', $user->id)->where('event', 'expired')->count());
        $user->forceFill(['is_active' => false])->save();
        $this->callToken($token->plainTextToken)->assertForbidden();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_operator_integration_token_is_not_silently_turned_into_interactive_login(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('operator-service', ['api'])->plainTextToken;
        $this->travel(1)->days();
        $this->callToken($token, 'session')->assertOk()->assertJsonPath('data.idle_timeout', null);
    }

    public function test_admitted_write_is_not_relabelled_failed_if_deadline_passes_during_work(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $token = $user->createToken('web', ['api']);
        $token->accessToken->forceFill(['web_idle_deadline' => now()->addSecond()])->save();
        Route::post('/api/testing-idle-write', function () use ($user) {
            $this->travel(2)->seconds();
            $user->forceFill(['name' => 'committed'])->save();

            return response()->json(['data' => ['saved' => true]], 201);
        })->middleware(['auth:sanctum', 'account.active', 'abilities:api', 'web.idle']);
        $this->callToken($token->plainTextToken, 'testing-idle-write', 'POST')->assertCreated()->assertJsonPath('data.saved', true);
        $this->assertSame('committed', $user->fresh()->name);
        $this->callToken($token->plainTextToken)->assertUnauthorized();
    }

    public function test_rollback_refuses_to_revive_web_tokens(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('web', ['api']);
        $token->accessToken->forceFill(['web_idle_deadline' => now()->subMinute(), 'web_expired_at' => now()])->save();
        $migration = require database_path('migrations/2026_09_28_000002_add_web_token_idle_deadline.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Revoke web tokens before rollback');
        $migration->down();
    }
}
