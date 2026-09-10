<?php

namespace Tests\Feature;

use App\Http\Responses\AuthError;
use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private function getDashboard(string $path, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($path, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    private function token(User $user): string
    {
        return $user->createToken('test', ['api'])->plainTextToken;
    }

    private function assignment(User $user, string $code, array $permissions): array
    {
        $facility = DB::table('facilities')->insertGetId(['code' => $code, 'name_ar' => 'مشفى '.$code, 'timezone' => 'Asia/Damascus', 'is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code, 'is_active' => true]);
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'role_id' => $role, 'user_id' => $user->id]);
        $ids = [];
        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('code', $permission)->value('id')
                ?? DB::table('permissions')->insertGetId(['code' => $permission, 'name_ar' => $permission, 'is_active' => true]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
            $ids[] = $id;
        }

        return [$facility, $role, $ids];
    }

    private function restricted(string $key, int $priority = 1, array $permissions = ['patients.view', 'patients.create']): void
    {
        config(['dashboards.'.$key => ['title' => 'Test '.$key, 'priority' => $priority, 'access' => 'facility_permissions', 'permissions' => $permissions]]);
    }

    public function test_bearer_authentication_ability_expiry_revocation_and_deactivation(): void
    {
        $user = User::factory()->create();
        foreach (['/api/dashboards', '/api/dashboards/general'] as $path) {
            foreach ([null, 'invalid', $user->createToken('expired', ['api'], now()->subMinute())->plainTextToken] as $token) {
                $this->getDashboard($path, $token)->assertUnauthorized()->assertExactJson(AuthError::Unauthenticated->body())
                    ->assertHeader('Cache-Control', 'no-store, private');
            }
            $token = $this->token($user);
            PersonalAccessToken::findToken($token)->delete();
            $this->getDashboard($path, $token)->assertUnauthorized();
            $this->getDashboard($path, $user->createToken('no-api', [])->plainTextToken)->assertForbidden()->assertExactJson(AuthError::MissingApiAbility->body());
        }
        $token = $this->token($user);
        $other = $this->token($user);
        $user->forceFill(['is_active' => false])->save();
        $this->getDashboard('/api/dashboards/general', $token)->assertForbidden()->assertExactJson(AuthError::InactiveAccount->body());
        $this->assertNull(PersonalAccessToken::findToken($other));
        $this->getDashboard('/api/dashboards', $token)->assertUnauthorized();
    }

    public function test_general_is_self_only_private_and_available_without_roles(): void
    {
        $user = User::factory()->create(['name' => 'Current user']);
        $other = User::factory()->create(['name' => 'Other secret user']);
        $token = $this->token($user);
        $this->getDashboard('/api/dashboards', $token)->assertOk()->assertJsonPath('data.default_dashboard_key', 'general')->assertJsonCount(1, 'data.dashboards');
        $response = $this->getDashboard('/api/dashboards/general', $token)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Authorization')
            ->assertJsonPath('data.user.id', $user->id)->assertJsonPath('data.facilities', [])->assertJsonPath('data.links', []);
        foreach ([$other->name, '"password"', '"remember_token"', '"token"', '"trace"', $token, $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->getDashboard('/api/dashboards/general', $this->token($other))->assertOk()->assertJsonPath('data.user.id', $other->id);
    }

    public function test_facility_permissions_are_never_combined_and_access_is_dynamic(): void
    {
        $this->restricted('clinical');
        $user = User::factory()->create();
        [$a, $roleA, $ids] = $this->assignment($user, 'A', ['patients.view']);
        [$b, $roleB, $createIds] = $this->assignment($user, 'B', ['patients.create']);
        $token = $this->token($user);
        $this->getDashboard('/api/dashboards', $token)->assertJsonCount(1, 'data.dashboards');
        foreach (['', '?facility_id='.$a, '?facility_id='.$b] as $suffix) {
            $this->getDashboard('/api/dashboards/clinical'.$suffix, $token)->assertForbidden()->assertJsonPath('error.code', 'DASHBOARD_ACCESS_DENIED');
        }
        DB::table('role_permissions')->insert(['role_id' => $roleA, 'permission_id' => $createIds[0]]);
        $this->getDashboard('/api/dashboards', $token)->assertOk()->assertJsonPath('data.default_dashboard_key', 'clinical')
            ->assertJsonPath('data.dashboards.0.default_facility_id', $a)->assertJsonCount(1, 'data.dashboards.0.facilities');
        $this->getDashboard('/api/dashboards/clinical', $token)->assertUnprocessable()->assertJsonValidationErrors('facility_id');
        $this->getDashboard('/api/dashboards/clinical?facility_id='.$a, $token)->assertOk()->assertJsonCount(1, 'data.facilities')->assertJsonPath('data.facilities.0.id', $a);
        $this->getDashboard('/api/dashboards/clinical?facility_id='.$b, $token)->assertForbidden();
        foreach ([['permissions', $ids[0]], ['roles', $roleA], ['facilities', $a]] as [$table, $id]) {
            DB::table($table)->where('id', $id)->update(['is_active' => false]);
            $this->getDashboard('/api/dashboards/clinical?facility_id='.$a, $token)->assertForbidden();
            DB::table($table)->where('id', $id)->update(['is_active' => true]);
            $this->getDashboard('/api/dashboards/clinical?facility_id='.$a, $token)->assertOk();
        }
        DB::table('role_permissions')->where('role_id', $roleA)->where('permission_id', $createIds[0])->delete();
        $this->getDashboard('/api/dashboards/clinical?facility_id='.$a, $token)->assertForbidden();
        DB::table('facility_user_roles')->where('user_id', $user->id)->where('facility_id', $a)->delete();
        $this->getDashboard('/api/dashboards/general?facility_id='.$a, $token)->assertForbidden()->assertJsonPath('error.code', 'FACILITY_ACCESS_DENIED');
    }

    public function test_unknown_keys_invalid_input_and_other_facilities_do_not_leak_data(): void
    {
        $user = User::factory()->create();
        [$otherFacility] = $this->assignment(User::factory()->create(), 'OTHER', []);
        $token = $this->token($user);
        foreach (['missing', 'general.title', '%3Cscript%3E', 'GENERAL'] as $key) {
            $this->getDashboard('/api/dashboards/'.$key, $token)->assertNotFound()->assertJsonPath('error.code', 'DASHBOARD_NOT_FOUND');
        }
        foreach (['0', '-1', 'abc', '1.5', '', '999999999999999999999', '%5B1%5D'] as $value) {
            $this->getDashboard('/api/dashboards/general?facility_id='.$value, $token)->assertUnprocessable()->assertJsonValidationErrors('facility_id');
        }
        $this->getDashboard('/api/dashboards?user_id=2', $token)->assertUnprocessable();
        $this->getDashboard('/api/dashboards/general?facility_id[]=1', $token)->assertUnprocessable();
        foreach ([$otherFacility, 2147483647] as $id) {
            $this->getDashboard('/api/dashboards/general?facility_id='.$id, $token)->assertForbidden()->assertJsonPath('error.code', 'FACILITY_ACCESS_DENIED');
        }
    }

    public function test_default_is_deterministic_allowed_only_and_can_be_empty(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $this->restricted('denied', -10);
        foreach (['zeta', 'alpha'] as $key) {
            config(['dashboards.'.$key => ['title' => $key, 'priority' => 1, 'access' => 'authenticated']]);
        }
        $this->getDashboard('/api/dashboards', $token)->assertJsonPath('data.default_dashboard_key', 'alpha')->assertJsonPath('data.dashboards.1.key', 'zeta');
        config(['dashboards' => ['closed' => ['title' => 'closed', 'access' => 'unknown']]]);
        $this->getDashboard('/api/dashboards', $token)->assertExactJson(['data' => ['dashboards' => [], 'default_dashboard_key' => null]]);
    }

    public function test_documentation_matches_actual_dashboard_responses(): void
    {
        config(['scramble.enabled' => true]);
        $token = $this->token(User::factory()->create());
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['/api/dashboards' => '/api/dashboards', '/api/dashboards/{key}' => '/api/dashboards/general'] as $path => $url) {
            $operation = $doc['paths'][$path]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $this->assertStringContainsString('api ability', $operation['description']);
            $this->assertContains('facility_id', array_column($operation['parameters'], 'name'));
            foreach ([200, 401, 403, 422, 500] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
            $this->assertMatchesSchema($doc, $operation['responses'][200]['content']['application/json']['schema'], $this->getDashboard($url, $token)->json());
            foreach ([
                [401, $this->getDashboard($url)->assertUnauthorized()],
                [403, $this->getDashboard($url.'?facility_id=2147483647', $token)->assertForbidden()],
                [422, $this->getDashboard($url.'?facility_id=invalid', $token)->assertUnprocessable()],
            ] as [$status, $response]) {
                $documentedResponse = $this->resolveSchema($doc, $operation['responses'][$status]);
                $this->assertMatchesSchema($doc, $documentedResponse['content']['application/json']['schema'], $response->json());
            }
        }
        $response = $this->getDashboard('/api/dashboards/unknown', $token)->assertNotFound();
        $this->assertMatchesSchema($doc, $doc['paths']['/api/dashboards/{key}']['get']['responses'][404]['content']['application/json']['schema'], $response->json());
    }

    public function test_unexpected_errors_are_redacted_and_private_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (\Throwable $exception) use (&$reported) {
            $reported[] = $exception;

            return false;
        });
        $exception = new \RuntimeException('internal database detail');
        $token = $this->token(User::factory()->create());
        $this->mock(UserAccessContext::class)->shouldReceive('forUser')->andThrow($exception);
        $this->getDashboard('/api/dashboards', $token)->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['error' => ['code' => 'DASHBOARD_UNAVAILABLE', 'message' => 'تعذّر تحميل لوحة التحكم. حاول مجددًا.']]);
        $this->assertSame([$exception], $reported);
    }
}
