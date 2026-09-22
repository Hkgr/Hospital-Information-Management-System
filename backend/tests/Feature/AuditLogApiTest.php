<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Audit\SystemActivity;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class AuditLogApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test', ['api'])->plainTextToken;
    }

    private function assignment(User $user, string $code): int
    {
        $facility = DB::table('facilities')->insertGetId(['code' => $code, 'name_ar' => 'مشفى '.$code, 'timezone' => 'Asia/Damascus', 'is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code, 'is_active' => true]);
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'role_id' => $role, 'user_id' => $user->id]);

        return $facility;
    }

    private function getLog(string $query, string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/audit'.$query, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_facility_members_read_activity_with_actor_time_and_category(): void
    {
        $user = User::factory()->create(['name' => 'كاتب السجل', 'username' => 'logger']);
        $facility = $this->assignment($user, 'LOG-A');
        $otherUser = User::factory()->create();
        $other = $this->assignment($otherUser, 'LOG-B');
        $token = $this->token($user);
        $this->getLog('', $token)->assertUnprocessable();
        $this->getLog('?facility_id='.$other, $token)->assertForbidden()->assertJsonPath('error.code', 'FACILITY_ACCESS_DENIED');
        $this->getLog('?facility_id='.$facility, $this->token(User::factory()->create()))->assertForbidden();
        $before = $this->getLog('?facility_id='.$facility, $token)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('meta.total');
        DB::table('audit_logs')->insert([
            'facility_id' => $facility, 'actor_id' => $user->id, 'entity_type' => 'clinic', 'entity_id' => 1,
            'event' => 'created', 'new_values' => json_encode(['name_ar' => 'عيادة السجل']), 'request_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
        $response = $this->getLog('?facility_id='.$facility, $token)->assertOk();
        $this->assertSame($before + 1, $response->json('meta.total'));
        $row = collect($response->json('data'))->firstWhere('entity', 'clinic');
        $this->assertSame('الدليل', $row['category_label']);
        $this->assertSame('كاتب السجل', $row['actor']['name']);
        $this->assertNotNull($row['occurred_at']);
        $this->assertSame($before + 1, $this->getLog('?facility_id='.$facility, $token)->json('meta.total'), 'GET must not write an audit row');
        $filtered = $this->getLog('?facility_id='.$facility.'&category=technical', $token)->assertOk();
        $this->assertSame(0, $filtered->json('meta.total'));
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        $this->assertArrayHasKey('/api/audit', $doc['paths']);
        $this->assertMatchesSchema($doc, $doc['paths']['/api/audit']['get']['responses'][200]['content']['application/json']['schema'], $response->json());
    }

    public function test_login_logout_and_technical_errors_are_recorded_without_secrets(): void
    {
        $user = User::factory()->create(['username' => 'auditor', 'password' => 'secret-pass']);
        $facility = $this->assignment($user, 'LOG-C');
        $this->postJson('/api/login', ['username' => 'auditor', 'password' => 'secret-pass'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['facility_id' => $facility, 'actor_id' => $user->id, 'entity_type' => 'auth_session', 'event' => 'login']);
        $loginValues = json_decode(DB::table('audit_logs')->where('event', 'login')->value('new_values'), true);
        $this->assertSame('auditor', $loginValues['username']);
        $this->assertArrayNotHasKey('password', $loginValues);
        $token = $this->token($user);
        $this->postJson('/api/logout', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();
        $this->assertDatabaseHas('audit_logs', ['facility_id' => $facility, 'actor_id' => $user->id, 'entity_type' => 'auth_session', 'event' => 'logout']);
        $this->app['request']->setUserResolver(fn () => $user);
        $this->app['request']->query->set('facility_id', (string) $facility);
        app(ExceptionHandler::class)->report(new \RuntimeException('Bearer secret-token password=hunter2 exploded'));
        $error = DB::table('audit_logs')->where('entity_type', 'system_error')->where('actor_id', $user->id)->orderByDesc('id')->first();
        $this->assertNotNull($error);
        $values = json_decode($error->new_values, true);
        $this->assertSame('failed', $error->event);
        $this->assertStringNotContainsString('secret-token', $values['message']);
        $this->assertStringNotContainsString('hunter2', $values['message']);
        $listed = $this->getLog('?facility_id='.$facility.'&category=technical', $this->token($user))->assertOk();
        $this->assertGreaterThan(0, $listed->json('meta.total'));
        $this->assertSame('خطأ تقني', $listed->json('data.0.category_label'));
        $this->assertSame($user->name, $listed->json('data.0.actor.name'));
    }

    public function test_system_activity_skips_validation_noise_and_users_without_a_facility(): void
    {
        $user = User::factory()->create(['username' => 'nofac']);
        $this->postJson('/api/login', ['username' => 'nofac', 'password' => 'password'])->assertOk();
        $this->assertSame(0, DB::table('audit_logs')->where('actor_id', $user->id)->where('entity_type', 'auth_session')->count());
        $count = DB::table('audit_logs')->count();
        app(SystemActivity::class)->recordException(new \Illuminate\Validation\ValidationException(validator([], ['x' => 'required'])));
        $this->assertSame($count, DB::table('audit_logs')->count());
    }
}
