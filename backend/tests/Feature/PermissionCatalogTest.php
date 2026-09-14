<?php

namespace Tests\Feature;

use App\Exceptions\AccessException;
use App\Models\User;
use App\Services\Auth\PermissionGate;
use App\Services\Auth\UserAccessContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_migration_twice_produces_no_duplicates_and_changes_no_ids(): void
    {
        $migration = require database_path('migrations/2026_09_13_000001_seed_permission_catalog.php');
        $permissionIds = DB::table('permissions')->orderBy('id')->pluck('id', 'code')->all();
        $roleIds = DB::table('roles')->orderBy('id')->pluck('id', 'code')->all();
        $linkCount = DB::table('role_permissions')->count();

        $migration->up();
        $migration->up();

        $this->assertSame($permissionIds, DB::table('permissions')->orderBy('id')->pluck('id', 'code')->all());
        $this->assertSame($roleIds, DB::table('roles')->orderBy('id')->pluck('id', 'code')->all());
        $this->assertSame($linkCount, DB::table('role_permissions')->count());
        $this->assertSame(
            DB::table('permissions')->count(),
            DB::table('permissions')->select('code')->distinct()->count()
        );
        $this->assertSame(
            DB::table('roles')->count(),
            DB::table('roles')->select('code')->distinct()->count()
        );
    }

    public function test_every_permission_code_matches_the_naming_rule(): void
    {
        $codes = DB::table('permissions')->pluck('code');
        $this->assertNotEmpty($codes);
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-z_]+(\.[a-z_]+)*$/', $code, $code);
        }
    }

    public function test_every_role_permission_set_resolves_to_codes_that_exist(): void
    {
        $existing = DB::table('permissions')->pluck('code')->all();
        $assigned = DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('r.code', ['super_admin', 'directory_admin', 'facility_admin', 'supervisor', 'data_entry', 'viewer'])
            ->get(['r.code as role_code', 'p.code as permission_code']);

        $this->assertNotEmpty($assigned);
        foreach ($assigned as $row) {
            $this->assertContains($row->permission_code, $existing, $row->role_code.' → '.$row->permission_code);
        }
    }

    public function test_data_entry_resolves_without_void_merge_close_or_review(): void
    {
        $codes = $this->roleCodes('data_entry');
        $this->assertNotEmpty($codes);
        foreach (['visits.void', 'patients.merge', 'periods.close', 'corrections.review'] as $denied) {
            $this->assertNotContains($denied, $codes);
        }
    }

    public function test_facility_gate_denies_update_without_matching_view(): void
    {
        [$user, $facility] = $this->assignFacility(['patients.update']);

        try {
            app(PermissionGate::class)->facility($user, $facility, 'patients', 'update');
            $this->fail('Expected ACCESS_DENIED when patients.view is missing.');
        } catch (AccessException $exception) {
            $this->assertSame('ACCESS_DENIED', $exception->errorCode);
            $this->assertSame(403, $exception->status);
            $this->assertSame('ليس لديك صلاحية لهذه العملية في المنشأة المحددة.', $exception->getMessage());
        }
    }

    public function test_global_gate_denies_permission_without_global_assignment(): void
    {
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId([
            'code' => 'GATE-G', 'name_ar' => 'منشأة بوابة عالمية', 'timezone' => 'Asia/Damascus', 'is_active' => true,
        ]);
        $role = DB::table('roles')->where('code', 'directory_admin')->value('id');
        $this->assertNotNull($role);
        DB::table('facility_user_roles')->insert([
            'facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role,
        ]);

        $facilityAccess = app(UserAccessContext::class)->forUser($user);
        $this->assertContains('catalogs.update', $facilityAccess[0]['permissions']);
        $this->assertSame([], app(UserAccessContext::class)->globalForUser($user)['permissions']);

        try {
            app(PermissionGate::class)->global($user, 'catalogs.update');
            $this->fail('Expected ACCESS_DENIED without global_user_roles.');
        } catch (AccessException $exception) {
            $this->assertSame('ACCESS_DENIED', $exception->errorCode);
            $this->assertSame(403, $exception->status);
            $this->assertSame('هذه العملية تحتاج تفويضًا عالميًا صريحًا.', $exception->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function roleCodes(string $code): array
    {
        return DB::table('role_permissions as rp')
            ->join('roles as r', 'r.id', '=', 'rp.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('r.code', $code)
            ->orderBy('p.code')
            ->pluck('p.code')
            ->all();
    }

    /**
     * @param  list<string>  $permissions
     * @return array{0: User, 1: int}
     */
    private function assignFacility(array $permissions): array
    {
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId([
            'code' => 'GATE-F', 'name_ar' => 'منشأة بوابة منشأة', 'timezone' => 'Asia/Damascus', 'is_active' => true,
        ]);
        $role = DB::table('roles')->insertGetId(['code' => 'pair_rule', 'name_ar' => 'دور اختبار الزوج', 'is_active' => true]);
        DB::table('facility_user_roles')->insert([
            'facility_id' => $facility, 'user_id' => $user->id, 'role_id' => $role,
        ]);
        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('code', $permission)->value('id')
                ?? DB::table('permissions')->insertGetId(['code' => $permission, 'name_ar' => $permission, 'is_active' => true]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }

        return [$user, $facility];
    }
}
