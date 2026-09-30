<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Users\AccessConsolidation;
use Database\Seeders\AccessAdministrationPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplicitAccessAdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $facility;

    private int $role;

    private int $legacy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessAdministrationPermissionsSeeder::class);
        $this->admin = User::find(1) ?? User::factory()->create(['id' => 1]);
        $this->admin->forceFill(['is_active' => true])->save();
        foreach (['super_admin', 'full_access_user_1'] as $code) {
            DB::table('roles')->insertOrIgnore(['code' => $code, 'name_ar' => $code]);
            $id = (int) DB::table('roles')->where('code', $code)->value('id');
            DB::table('roles')->where('id', $id)->update(['is_active' => true, 'access_consolidated_at' => null]);
            DB::table('global_user_roles')->where('role_id', $id)->delete();
            DB::table('facility_user_roles')->where('role_id', $id)->delete();
        }
        $this->role = (int) DB::table('roles')->where('code', 'super_admin')->value('id');
        $this->legacy = (int) DB::table('roles')->where('code', 'full_access_user_1')->value('id');
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'EA-'.Str::random(10), 'name_ar' => 'اختبار الوصول', 'timezone' => 'Asia/Damascus']);
        DB::table('facility_user_roles')->insert(['user_id' => 1, 'role_id' => $this->legacy, 'facility_id' => $this->facility]);
    }

    private function consolidate(): array
    {
        $service = app(AccessConsolidation::class);

        return $service->apply($service->preview()['fingerprint'], 'test/operator-65', 'reviewed consolidation');
    }

    private function api(string $method, string $path, array $body = [], ?User $actor = null)
    {
        $this->app['auth']->forgetGuards();
        $token = ($actor ?? $this->admin)->createToken('test', ['api'])->plainTextToken;

        return $this->json($method, '/api/'.$path, $body + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.$token]);
    }

    private function ids(array $codes): array
    {
        return DB::table('permissions')->whereIn('code', $codes)->pluck('id')->all();
    }

    private function member(array $codes = ['patients.basic.view', 'patient_cards.register']): array
    {
        $user = User::factory()->create();
        $role = DB::table('roles')->insertGetId(['code' => 'EA-'.Str::random(15), 'name_ar' => 'موظف اختبار']);
        foreach ($this->ids($codes) as $id) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        DB::table('facility_user_roles')->insert(['facility_id' => $this->facility, 'user_id' => $user->id, 'role_id' => $role]);

        return [$user, $role];
    }

    public function test_reviewed_consolidation_preserves_history_union_and_is_idempotent(): void
    {
        $before = $this->admin->fresh()->getAttributes();
        $clinical = DB::table('patient_dossiers')->count();
        $preview = app(AccessConsolidation::class)->preview();
        $this->assertSame([], $preview['conflicts']);
        $this->assertDatabaseHas('roles', ['id' => $this->legacy, 'is_active' => true]);
        $this->consolidate();
        $this->assertSame($before, $this->admin->fresh()->getAttributes());
        $this->assertSame($clinical, DB::table('patient_dossiers')->count());
        $this->assertDatabaseHas('roles', ['id' => $this->legacy, 'is_active' => false]);
        $this->assertDatabaseHas('facility_user_roles', ['user_id' => 1, 'role_id' => $this->legacy]);
        $this->assertEqualsCanonicalizing(DB::table('permissions')->where('is_active', true)->pluck('code')->all(), app(GlobalAccess::class)->codes($this->admin));
        $audit = DB::table('audit_logs')->where('entity_type', 'role')->where('entity_id', $this->role)->orderByDesc('id')->first();
        $this->assertNull($audit->actor_id);
        $this->assertSame('test/operator-65', json_decode($audit->new_values, true)['execution_reference']);
        $count = DB::table('audit_logs')->count();
        $this->consolidate();
        $this->assertSame($count, DB::table('audit_logs')->count());
        DB::table('permissions')->insert(['code' => 'explicit.future', 'name_ar' => 'future']);
        $this->assertFalse(app(GlobalAccess::class)->allows($this->admin, 'explicit.future'));
        $this->consolidate();
        $this->assertFalse(app(GlobalAccess::class)->allows($this->admin, 'explicit.future'));
    }

    public function test_conflicting_assignee_and_stale_preview_make_no_writes(): void
    {
        $preview = app(AccessConsolidation::class)->preview();
        [$other] = $this->member();
        DB::table('global_user_roles')->insert(['user_id' => $other->id, 'role_id' => $this->legacy]);
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $audit = DB::table('audit_logs')->count();
        $this->assertNotEmpty(app(AccessConsolidation::class)->preview()['conflicts']);
        try {
            app(AccessConsolidation::class)->apply($preview['fingerprint'], 'operator', 'reason');
            $this->fail('Conflict was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nothing changed', $e->getMessage());
        }
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        $this->assertSame($audit, DB::table('audit_logs')->count());
    }

    public function test_protected_exact_choices_revoke_real_api_despite_legacy_roles_and_umbrellas(): void
    {
        $this->consolidate();
        $role = $this->api('GET', 'users/roles/'.$this->role)->assertOk()->json('data');
        $this->assertTrue($role['manageable']);
        $this->api('GET', 'clinics')->assertOk();
        $ids = array_values(array_diff(array_column($role['permissions'], 'id'), $this->ids(['clinics.view'])));
        // Remove dependants explicitly, rather than accepting an inconsistent policy.
        $ids = array_values(array_diff($ids, DB::table('permissions')->where('code', 'like', 'clinics.%')->pluck('id')->all()));
        $body = ['name_ar' => $role['name_ar'], 'permission_ids' => $ids, 'lock_version' => $role['lock_version'], 'reason' => 'remove clinical execution'];
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', $body)->assertOk();
        $this->api('GET', 'clinics')->assertForbidden();
        $this->api('GET', 'users/roles')->assertOk();
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', $body)->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => 1, 'entity_type' => 'role', 'entity_id' => $this->role, 'reason' => 'remove clinical execution']);
        $audit = DB::table('audit_logs')->where('entity_type', 'role')->where('entity_id', $this->role)->where('reason', 'remove clinical execution')->value('id');
        $this->api('GET', 'audit/'.$audit)->assertOk()->assertJsonFragment(['field' => 'permissions', 'label' => 'الصلاحيات']);
    }

    public function test_access_minimum_self_protection_and_view_only_role_details(): void
    {
        $this->consolidate();
        $role = $this->api('GET', 'users/roles/'.$this->role)->assertOk()->json('data');
        $body = ['name_ar' => $role['name_ar'], 'permission_ids' => $this->ids(['users.view']), 'lock_version' => $role['lock_version'], 'reason' => 'unsafe removal'];
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', $body)->assertUnprocessable()->assertJsonPath('error.code', 'PROTECTED_ACCESS_MINIMUM');
        [$viewer] = $this->member(['roles.view']);
        $this->api('GET', 'users/roles/options', [], $viewer)->assertOk();
        $this->api('GET', 'users/roles/'.$this->role, [], $viewer)->assertOk()->assertJsonPath('data.manageable', false);
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', $body, $viewer)->assertForbidden();
        $this->api('DELETE', 'users/1')->assertForbidden();
        $this->api('PUT', 'users/1/access', ['lock_version' => 0, 'reason' => 'unsafe assignment', 'global_permission_ids' => []])->assertForbidden();
    }

    public function test_granular_removal_cannot_be_regranted_by_an_umbrella_or_a_second_role(): void
    {
        $this->consolidate();
        [, $extra] = $this->member(['clinics.edit', 'clinics.update']);
        DB::table('facility_user_roles')->insert(['user_id' => 1, 'facility_id' => $this->facility, 'role_id' => $extra]);
        DB::table('global_user_roles')->insert(['user_id' => 1, 'role_id' => $extra]);
        $role = $this->api('GET', 'users/roles/'.$this->role)->assertOk()->json('data');
        $ids = array_values(array_diff(array_column($role['permissions'], 'id'), $this->ids(['clinics.edit'])));
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', ['name_ar' => $role['name_ar'], 'permission_ids' => $ids, 'lock_version' => $role['lock_version'], 'reason' => 'explicit granular exclusion'])->assertOk();
        $codes = app(GlobalAccess::class)->codes($this->admin);
        $this->assertContains('clinics.update', $codes);
        $this->assertNotContains('clinics.edit', $codes);
        $this->api('PUT', 'clinics/999999', ['name_ar' => 'unreachable', 'is_active' => true, 'lock_version' => 1])->assertForbidden();
        $this->artisan('doctors:grant-access', ['--user' => $this->admin->username, '--role' => 'super_admin', '--apply' => true])->assertFailed();
        $this->assertNotContains('clinics.edit', app(GlobalAccess::class)->codes($this->admin));
    }

    public function test_revoked_and_inactive_delegations_take_effect_without_a_new_login(): void
    {
        $this->consolidate();
        [$clerk] = $this->member();
        $view = $this->api('GET', 'users/'.$clerk->id.'/access')->assertOk()->json('data');
        $this->api('PUT', 'users/'.$clerk->id.'/access', ['lock_version' => $view['lock_version'], 'reason' => 'grant search', 'global_permission_ids' => $this->ids(['patients.basic.search'])])->assertOk();
        $token = $clerk->createToken('same-device', ['api'])->plainTextToken;
        $call = function () use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->getJson('/api/reception/patients?facility_id='.$this->facility.'&search=nobody', ['Authorization' => 'Bearer '.$token]);
        };
        $call()->assertOk();
        DB::table('roles')->where('code', 'delegation-'.$clerk->id)->update(['is_active' => false]);
        $call()->assertForbidden();
        DB::table('roles')->where('code', 'delegation-'.$clerk->id)->update(['is_active' => true]);
        DB::table('permissions')->where('code', 'patients.basic.search')->update(['is_active' => false]);
        $call()->assertForbidden();
        DB::table('permissions')->where('code', 'patients.basic.search')->update(['is_active' => true]);
        $call()->assertOk();
        $clerk->forceFill(['is_active' => false])->save();
        $call()->assertForbidden();
        $this->assertSame(0, $clerk->tokens()->count());
    }

    public function test_definition_seeding_cannot_restore_excluded_permissions_or_archived_roles(): void
    {
        $this->consolidate();
        $before = DB::table('role_permissions')->where('role_id', $this->role)->orderBy('id')->get()->toJson();
        DB::table('permissions')->where('code', 'users.global.view')->update(['is_active' => false]);
        $this->seed(AccessAdministrationPermissionsSeeder::class);
        $this->seed(AccessAdministrationPermissionsSeeder::class);
        $this->assertDatabaseHas('permissions', ['code' => 'users.global.view', 'is_active' => false]);
        $this->assertSame($before, DB::table('role_permissions')->where('role_id', $this->role)->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('roles', ['id' => $this->legacy, 'is_active' => false]);
        $this->expectExceptionMessage('Access decisions and audit history must be retained');
        (require database_path('migrations/2026_10_01_000001_add_explicit_access_administration.php'))->down();
    }

    public function test_global_grant_is_explicit_scoped_revocable_audited_and_does_not_expose_medical_history(): void
    {
        $this->consolidate();
        [$clerk] = $this->member();
        $this->api('GET', 'reception/patients', ['search' => 'nobody'], $clerk)->assertForbidden();
        $access = $this->api('GET', 'users/'.$clerk->id.'/access')->assertOk()->json('data');
        $body = ['lock_version' => $access['lock_version'], 'reason' => 'explicit limited registration', 'global_permission_ids' => $this->ids(['patients.basic.search', 'patients.basic.create'])];
        $saved = $this->api('PUT', 'users/'.$clerk->id.'/access', $body)->assertOk()->json('data');
        $this->api('GET', 'reception/patients', ['search' => 'nobody'], $clerk)->assertOk();
        $this->api('GET', 'dossiers', [], $clerk)->assertForbidden();
        $this->api('POST', 'dossiers/export/pdf', [], $clerk)->assertForbidden();
        $other = DB::table('facilities')->insertGetId(['code' => 'EA-'.Str::random(12), 'name_ar' => 'other', 'timezone' => 'Asia/Damascus']);
        $this->api('GET', 'reception/options', ['facility_id' => $other], $clerk)->assertForbidden();
        $this->api('PUT', 'users/'.$clerk->id.'/access', $body)->assertConflict();
        $this->api('PUT', 'users/'.$clerk->id.'/access', ['lock_version' => $saved['lock_version'], 'reason' => 'revoke limited search', 'global_permission_ids' => []])->assertOk();
        $this->api('GET', 'reception/patients', ['search' => 'nobody'], $clerk)->assertForbidden();
        $this->assertDatabaseHas('audit_logs', ['actor_id' => 1, 'entity_type' => 'auth_session', 'entity_id' => $clerk->id, 'reason' => 'revoke limited search']);
    }
}
