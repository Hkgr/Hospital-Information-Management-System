<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\FacilitySettingsPermissionsSeeder;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Database\Seeders\PermissionMatrixPhaseTwoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FacilitySettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $facility;

    private int $role;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionMatrixPhaseOneSeeder::class, PermissionMatrixPhaseTwoSeeder::class, FacilitySettingsPermissionsSeeder::class]);
        $this->admin = User::factory()->create();
        $this->facility = $this->facility();
        $this->role = DB::table('roles')->where('code', 'hospital_admin')->value('id');
        DB::table('facility_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $this->role, 'facility_id' => $this->facility]);
        $this->token = $this->admin->createToken('operator-test', ['api'])->plainTextToken;
    }

    private function facility(): int
    {
        return DB::table('facilities')->insertGetId(['code' => 'SET-'.Str::random(12), 'name_ar' => 'مشفى اختبار الإعدادات', 'timezone' => 'Asia/Damascus']);
    }

    private function api(string $method = 'GET', string $path = 'settings', array $input = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $input + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function save(int $minutes, int $version = 1, ?int $facility = null)
    {
        return $this->api('PUT', 'settings', ['facility_id' => $facility ?? $this->facility, 'lock_version' => $version, 'name_ar' => 'الاسم المعتمد', 'idle_minutes' => $minutes, 'reason' => 'اختبار سياسة الجلسات']);
    }

    private function web(): string
    {
        return $this->postJson('/api/login', ['username' => $this->admin->username, 'password' => 'password'])->assertOk()->json('data.token');
    }

    public function test_scope_validation_version_and_audit_are_enforced(): void
    {
        $this->api()->assertOk()->assertJsonPath('data.idle_minutes', 2)->assertJsonPath('data.system_policy', null);
        $this->save(5)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->save(6)->assertConflict()->assertJsonPath('error.code', 'SETTINGS_VERSION_CONFLICT');
        $this->api('GET', 'settings', ['facility_id' => $this->facility()])->assertForbidden();
        foreach ([0, 61, 1.5] as $minutes) {
            $this->api('PUT', 'settings', ['name_ar' => 'اختبار', 'lock_version' => 2, 'idle_minutes' => $minutes])->assertUnprocessable();
        }
        $valid = ['name_ar' => 'اختبار', 'lock_version' => 2, 'idle_minutes' => 3];
        $this->api('PUT', 'settings', $valid)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->api('PUT', 'settings', $valid + ['timezone' => 'UTC'])->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->api('PUT', 'settings/system-session', ['lock_version' => 1, 'idle_minutes' => 4, 'reason' => 'test'])->assertForbidden();
        $audit = DB::table('audit_logs')->where('entity_type', 'facility_settings')->where('facility_id', $this->facility)->sole();
        $this->assertEquals($this->admin->id, $audit->actor_id);
        $this->assertSame(2, json_decode($audit->old_values, true)['idle_minutes']);
        $this->assertSame(5, json_decode($audit->new_values, true)['idle_minutes']);
        $this->assertDatabaseHas('facilities', ['id' => $this->facility, 'name_ar' => 'الاسم المعتمد']);
        $permission = DB::table('permissions')->where('code', 'settings.update')->value('id');
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', $permission)->delete();
        $this->api()->assertOk()->assertJsonPath('data.can_update', false);
        $this->save(6, 2)->assertForbidden();
        $stranger = User::factory()->create()->createToken('test', ['api'])->plainTextToken;
        $this->api('GET', 'settings', [], $stranger)->assertForbidden();
        $this->api('GET', 'guide', [], $stranger)->assertForbidden();
    }

    public function test_increase_only_benefits_live_activity_and_never_revives_previous_deadline(): void
    {
        $this->freezeTime();
        $web = $this->web();
        $this->travel(60)->seconds();
        $this->save(5)->assertOk();
        $this->api('GET', 'session', [], $web)->assertOk()->assertJsonPath('data.remaining_seconds', 60)->assertJsonPath('data.applied_idle_timeout', 120);
        $this->api('POST', 'session/activity', [], $web)->assertOk()->assertJsonPath('data.remaining_seconds', 300)->assertJsonPath('data.warning_seconds', 60);
        $this->travel(300)->seconds();
        $this->save(60, 2)->assertOk();
        $this->api('POST', 'session/activity', [], $web)->assertUnauthorized();
    }

    public function test_lower_then_raise_without_poll_cannot_resurrect_expired_session(): void
    {
        $this->freezeTime();
        $web = $this->web();
        $this->travel(61)->seconds();
        $this->save(1)->assertOk();
        $this->travel(1)->seconds();
        $this->save(60, 2)->assertOk();
        $this->api('POST', 'session/activity', [], $web)->assertUnauthorized();
        $this->api('GET', 'session', [], $web)->assertUnauthorized();
        $this->assertSame(1, DB::table('audit_logs')->where('actor_id', $this->admin->id)->where('event', 'expired')->count());
    }

    public function test_shortest_active_facility_controls_token_independent_of_query_and_polling(): void
    {
        $this->freezeTime();
        $this->save(10)->assertOk();
        $second = $this->facility();
        DB::table('facility_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $this->role, 'facility_id' => $second]);
        $web = $this->web();
        $this->api('GET', 'session', [], $web)->assertOk()->assertJsonPath('data.idle_timeout', 120);
        $this->save(1, 1, $second)->assertOk();
        $this->travel(30)->seconds();
        $this->api('GET', 'session', [], $web)->assertOk()->assertJsonPath('data.remaining_seconds', 30);
        $this->api('GET', 'settings', [], $web)->assertOk();
        $this->travel(30)->seconds();
        $this->api('GET', 'settings', [], $web)->assertUnauthorized();
        // An integration token remains outside interactive policy.
        $this->api('GET', 'session')->assertOk()->assertJsonPath('data.idle_timeout', null);
    }

    public function test_global_super_policy_is_separate_and_invalid_values_fail_to_default(): void
    {
        $role = DB::table('roles')->insertGetId(['code' => 'settings-system-'.Str::random(12), 'name_ar' => 'تفويض نظامي اختباري', 'is_system_super_admin' => true]);
        foreach (DB::table('permissions')->whereIn('code', ['settings.view', 'settings.update'])->where('is_active', true)->pluck('id') as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('global_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $role]);
        $this->save(1)->assertOk();
        $this->api('PUT', 'settings/system-session', ['lock_version' => 1, 'idle_minutes' => 7, 'reason' => 'سياسة المسؤول'])->assertOk();
        $web = $this->web();
        $this->api('GET', 'session', [], $web)->assertOk()->assertJsonPath('data.idle_timeout', 420);
        DB::table('system_session_policy')->where('id', 1)->update(['idle_minutes' => 0]);
        $this->api('GET', 'session', [], $web)->assertOk()->assertJsonPath('data.idle_timeout', 120);
        $this->api('GET', 'guide')->assertOk()->assertJsonPath('data.system_admin', true);
    }

    public function test_seeder_preserves_disabled_definitions_and_does_not_grant_globals(): void
    {
        $before = DB::table('global_user_roles')->count();
        DB::table('permissions')->where('code', 'settings.update')->update(['is_active' => false]);
        $this->seed(FacilitySettingsPermissionsSeeder::class);
        $this->seed(FacilitySettingsPermissionsSeeder::class);
        $this->assertDatabaseHas('permissions', ['code' => 'settings.update', 'is_active' => false]);
        $this->assertSame($before, DB::table('global_user_roles')->count());
        $role = DB::table('roles')->where('code', 'data_entry')->value('id');
        $this->api('POST', 'users', ['username' => 'new-'.Str::random(10), 'name' => 'استقبال اختبار', 'password' => 'test-password', 'role_id' => $role])
            ->assertCreated()->assertJsonPath('data.pending_global_permissions', ['reception.patients.create', 'reception.patients.search']);
        $this->assertSame($before, DB::table('global_user_roles')->count());
    }

    public function test_local_admin_cannot_edit_a_role_that_would_change_global_grants(): void
    {
        $role = DB::table('roles')->where('code', 'data_entry')->value('id');
        DB::table('global_user_roles')->insert(['user_id' => User::factory()->create()->id, 'role_id' => $role]);
        $ids = DB::table('role_permissions')->where('role_id', $role)->pluck('permission_id')->all();
        $this->api('PUT', 'users/roles/'.$role, ['lock_version' => 0, 'reason' => 'reviewed test change', 'name_ar' => 'تغيير تفويض عالمي غير مسموح', 'permission_ids' => $ids])->assertForbidden()->assertJsonPath('error.code', 'GLOBAL_ROLE_PROTECTED');
    }

    public function test_settings_save_does_not_renew_own_token_and_rollback_retains_policy_history(): void
    {
        $this->freezeTime();
        $web = $this->web();
        $this->travel(90)->seconds();
        $this->api('PUT', 'settings', ['name_ar' => 'الاسم', 'idle_minutes' => 1, 'lock_version' => 1, 'reason' => 'خفض المدة'], $web)->assertOk();
        $this->api('GET', 'session', [], $web)->assertUnauthorized();
        $migration = require database_path('migrations/2026_09_28_000003_add_session_policy_settings.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Revoke web tokens before rollback');
        $migration->down();
    }

    public function test_role_choices_are_rechecked_and_internal_roles_are_never_reused(): void
    {
        $this->api('GET', 'users/options')->assertOk();
        $role = DB::table('roles')->where('code', 'data_entry')->value('id');
        DB::table('roles')->where('id', $role)->update(['is_active' => false]);
        $input = ['username' => 'reject-'.Str::random(12), 'name' => 'اختبار', 'password' => 'test-password', 'role_id' => $role];
        $this->api('POST', 'users', $input)->assertUnprocessable();
        $internal = DB::table('roles')->insertGetId(['code' => 'reception-'.Str::random(12), 'name_ar' => 'حساب في منشأة أخرى']);
        $input['role_id'] = $internal;
        $this->api('POST', 'users', $input)->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => $input['username']]);
        $this->api('GET', 'guide')->assertOk()->assertJsonPath('data.system_admin', false);
        DB::table('facilities')->where('id', $this->facility)->update(['is_active' => false]);
        $this->api()->assertForbidden();
        $this->api('GET', 'guide')->assertForbidden();
    }

    public function test_removing_a_facility_after_its_policy_expired_the_token_does_not_revive_it(): void
    {
        $this->freezeTime();
        $second = $this->facility();
        DB::table('facility_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $this->role, 'facility_id' => $second]);
        $web = $this->web();
        $this->travel(61)->seconds();
        $this->save(1, 1, $second)->assertOk();
        DB::table('facility_user_roles')->where('user_id', $this->admin->id)->where('facility_id', $second)->delete();
        $this->api('POST', 'session/activity', [], $web)->assertUnauthorized();
    }
}
