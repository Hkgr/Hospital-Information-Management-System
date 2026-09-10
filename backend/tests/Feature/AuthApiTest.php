<?php

namespace Tests\Feature;

use App\Http\Responses\AuthError;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'scramble.enabled' => true]);
        Cache::store('array')->flush();
    }

    private function login(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/login', array_replace([
            'username' => 'admin', 'password' => 'password',
        ], $overrides));
    }

    private function bearer(string $method, string $path, string $token): TestResponse
    {
        // Laravel's test application survives requests; forget the previous guard user.
        $this->app['auth']->forgetGuards();

        return $this->json($method, $path, [], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_login_issues_hashed_token_and_current_user_matches_documented_contract(): void
    {
        $this->freezeSecond();
        $user = User::factory()->create(['username' => 'admin', 'email' => null]);
        $login = $this->login(['username' => '  admin  '])->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonPath('data.user.must_change_password', true)
            ->assertJsonPath('data.user.staff_id', null)
            ->assertJsonPath('data.user.email', null)
            ->assertJsonPath('data.access', []);
        $plain = $login->json('data.token');
        $token = PersonalAccessToken::findToken($plain);
        $this->assertNotNull($token);
        $this->assertSame($user->id, $token->tokenable_id);
        $this->assertSame(['api'], $token->abilities);
        $this->assertSame('hospital-web', $token->name);
        $this->assertSame(hash('sha256', explode('|', $plain, 2)[1]), $token->token);
        $this->assertTrue($user->fresh()->last_login_at->equalTo(now()));
        $login->assertJsonPath('data.user.last_login_at', now()->toIso8601ZuluString());

        $current = $this->bearer('GET', '/api/user', $plain)->assertOk();
        $this->assertSame($login->json('data.user'), $current->json('data.user'));
        $current->assertJsonMissingPath('data.token');
        foreach ([$login, $current] as $response) {
            $response->assertJsonMissingPath('data.user.password')
                ->assertJsonMissingPath('data.user.remember_token')
                ->assertJsonMissingPath('data.user.is_active');
            $this->assertStringNotContainsString($token->token, $response->getContent());
            $this->assertStringNotContainsString($user->password, $response->getContent());
        }
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach ([['/api/login', 'post', $login], ['/api/user', 'get', $current]] as [$path, $method, $response]) {
            $schema = $doc['paths'][$path][$method]['responses'][200]['content']['application/json']['schema'];
            $this->assertMatchesSchema($doc, $schema, $response->json());
        }
    }

    public function test_invalid_credentials_are_identical_and_have_no_side_effects(): void
    {
        $previous = now()->subDay()->startOfSecond();
        $user = User::factory()->create(['username' => 'admin', 'last_login_at' => $previous]);
        $this->login(['password' => 'wrong'])->assertUnauthorized()->assertExactJson(AuthError::InvalidCredentials->body());
        $this->login(['username' => 'unknown'])->assertUnauthorized()->assertExactJson(AuthError::InvalidCredentials->body());
        $this->login(['username' => $user->email])->assertUnauthorized()->assertExactJson(AuthError::InvalidCredentials->body());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertTrue($user->fresh()->last_login_at->equalTo($previous));
    }

    public function test_inactive_account_is_rejected_only_after_verifying_password(): void
    {
        $user = User::factory()->create(['username' => 'admin', 'is_active' => false]);
        $this->login()->assertForbidden()->assertExactJson(AuthError::InactiveAccount->body());
        $this->login(['password' => 'wrong'])->assertUnauthorized()->assertExactJson(AuthError::InvalidCredentials->body());
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_password_whitespace_is_preserved_and_hash_is_upgraded_on_success(): void
    {
        $user = User::factory()->create(['username' => 'admin', 'password' => Hash::make(' secret ', ['rounds' => 4])]);
        config(['hashing.bcrypt.rounds' => 5]);
        app('hash')->forgetDrivers();
        $this->login(['password' => 'secret'])->assertUnauthorized();
        $old = $user->fresh()->password;
        $this->login(['password' => ' secret ', 'device_name' => 'ward-tablet'])->assertOk();
        $this->assertNotSame($old, $user->fresh()->password);
        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
        $this->assertSame('ward-tablet', $user->tokens()->sole()->name);
    }

    public static function invalidInputs(): array
    {
        return [
            'missing username' => [['password' => 'password'], 'username'],
            'missing password' => [['username' => 'admin'], 'password'],
            'blank username' => [['username' => '   ', 'password' => 'password'], 'username'],
            'long username' => [['username' => str_repeat('ع', 61), 'password' => 'password'], 'username'],
            'long device' => [['username' => 'admin', 'password' => 'password', 'device_name' => str_repeat('a', 101)], 'device_name'],
            'array username' => [['username' => ['admin'], 'password' => 'password'], 'username'],
            'array password' => [['username' => 'admin', 'password' => ['password']], 'password'],
            'null device' => [['username' => 'admin', 'password' => 'password', 'device_name' => null], 'device_name'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_validation(array $input, string $field): void
    {
        // Even without Accept: application/json validation must not redirect.
        $this->post('/api/login', $input)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_rate_limit_uses_trimmed_unicode_lowercase_username_and_ip(): void
    {
        foreach (['ÄDMIN', ' ädmin ', 'Ädmin', 'ädmin', '  ÄDMIN  '] as $name) {
            $this->login(['username' => $name])->assertUnauthorized();
        }
        $this->login(['username' => 'ädmin'])->assertStatus(429)
            ->assertExactJson(AuthError::TooManyRequests->body())->assertHeader('Retry-After');
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->login(['username' => 'ädmin'])->assertUnauthorized();
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->travel(61)->seconds();
        $this->login(['username' => 'ädmin'])->assertUnauthorized();
    }

    public function test_logout_revokes_only_current_device_and_keeps_other_device_valid(): void
    {
        User::factory()->create(['username' => 'admin', 'must_change_password' => false]);
        $first = $this->login()->assertOk()->json('data.token');
        $second = $this->login(['device_name' => 'second-device'])->assertOk()->json('data.token');
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->bearer('POST', '/api/logout', $first)->assertNoContent();
        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->bearer('GET', '/api/user', $first)->assertUnauthorized();
        $this->bearer('POST', '/api/logout', $first)->assertUnauthorized();
        $this->bearer('GET', '/api/user', $second)->assertOk()->assertJsonPath('data.user.must_change_password', false);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_protected_endpoints_require_bearer_even_without_accept_header_or_with_web_guard(): void
    {
        $this->get('/api/user')->assertUnauthorized()->assertExactJson(AuthError::Unauthenticated->body());
        $this->post('/api/logout')->assertUnauthorized()->assertExactJson(AuthError::Unauthenticated->body());
        $this->actingAs(User::factory()->create(), 'web');
        $this->get('/api/user')->assertUnauthorized();
        $this->post('/api/logout')->assertUnauthorized();
    }

    public function test_failure_to_update_user_rolls_back_token_creation(): void
    {
        $user = User::factory()->create(['username' => 'admin']);
        $this->app['events']->listen('eloquent.updating: '.User::class, function () {
            throw new RuntimeException('Simulated write failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->login();
            $this->fail('Expected the update to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated write failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull($user->fresh()->last_login_at);
    }

    public function test_access_filters_deduplicates_sorts_and_stays_dynamic_in_one_query(): void
    {
        $user = User::factory()->create(['username' => 'admin']);
        $facility = fn (string $code, bool $active = true) => DB::table('facilities')->insertGetId([
            'code' => $code, 'name_ar' => 'مشفى '.$code, 'timezone' => 'Asia/Damascus', 'is_active' => $active,
        ]);
        $role = fn (string $code, bool $active = true) => DB::table('roles')->insertGetId([
            'code' => $code, 'name_ar' => 'دور '.$code, 'name_en' => null, 'is_active' => $active,
        ]);
        $permission = fn (string $code, bool $active = true) => DB::table('permissions')->insertGetId([
            'code' => $code, 'name_ar' => $code, 'is_active' => $active,
        ]);
        $z = $facility('Z');
        $a = $facility('A');
        $inactive = $facility('INACTIVE', false);
        $inactiveRoleOnly = $facility('INACTIVE-ROLE-ONLY');
        $admin = $role('ADMIN');
        $reader = $role('READER');
        $empty = $role('EMPTY');
        $disabled = $role('DISABLED', false);
        $view = $permission('patients.view');
        $create = $permission('patients.create');
        $secret = $permission('patients.disabled', false);
        foreach ([[$z, $empty], [$a, $reader], [$a, $admin], [$inactive, $admin], [$a, $disabled], [$inactiveRoleOnly, $disabled]] as [$f, $r]) {
            DB::table('facility_user_roles')->insert(['facility_id' => $f, 'user_id' => $user->id, 'role_id' => $r]);
        }
        foreach ([[$admin, $view], [$admin, $create], [$reader, $view], [$reader, $secret], [$disabled, $create]] as [$r, $p]) {
            DB::table('role_permissions')->insert(['role_id' => $r, 'permission_id' => $p]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $access = app(UserAccessContext::class)->forUser($user);
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(['A', 'Z'], array_column(array_column($access, 'facility'), 'code'));
        $this->assertSame(['ADMIN', 'READER'], array_column($access[0]['roles'], 'code'));
        $this->assertSame(['patients.create', 'patients.view'], $access[0]['permissions']);
        $this->assertSame(['EMPTY'], array_column($access[1]['roles'], 'code'));
        $this->assertSame([], $access[1]['permissions']);
        $login = $this->login()->assertOk()->assertJsonPath('data.access', $access);
        $plain = $login->json('data.token');
        $this->bearer('GET', '/api/user', $plain)->assertOk()->assertJsonPath('data.access', $access);
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        $this->assertMatchesSchema($doc, $doc['paths']['/api/login']['post']['responses'][200]['content']['application/json']['schema'], $login->json());
        DB::table('permissions')->where('id', $view)->update(['is_active' => false]);
        $this->bearer('GET', '/api/user', $plain)->assertOk()->assertJsonPath('data.access.0.permissions', ['patients.create']);
    }
}
