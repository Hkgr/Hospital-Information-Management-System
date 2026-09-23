<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SurfacePermissionsSeeder;
use Database\Seeders\UserPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RoleApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private int $facility;

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UserPermissionsSeeder::class);
        $this->seed(SurfacePermissionsSeeder::class);
        $this->user = User::factory()->create(['username' => 'role-admin', 'name' => 'مدير الأدوار']);
        $this->token = $this->user->createToken('role-test', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'ROL-A', 'name_ar' => 'منشأة الأدوار', 'timezone' => 'Asia/Damascus']);
        $this->role = DB::table('roles')->insertGetId(['code' => 'ROL-ADMIN', 'name_ar' => 'مدير أدوار اختباري']);
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'facility_id' => $this->facility, 'role_id' => $this->role]);
        $this->grant(['users.view', 'users.create', 'roles.view', 'roles.create', 'roles.update', 'dashboards.view']);
    }

    private function grant(array $codes): void
    {
        foreach ($codes as $code) {
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->role, 'permission_id' => $permission]);
        }
    }

    private function permission(string $code): int
    {
        return (int) DB::table('permissions')->where('code', $code)->value('id');
    }

    private function api(string $method, string $path = '/roles', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/users'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_create_update_role_from_owned_permissions_only(): void
    {
        $created = $this->api('POST', '/roles', [
            'name_ar' => 'ممرض أجنحة',
            'permission_ids' => [$this->permission('users.view'), $this->permission('dashboards.view')],
        ])->assertCreated()->assertJsonPath('data.name_ar', 'ممرض أجنحة')->json('data');
        $this->assertNotSame('super_admin', $created['code']);
        $this->assertTrue($created['manageable']);
        $this->assertEqualsCanonicalizing(['users.view', 'dashboards.view'], array_column($created['permissions'], 'code'));
        $this->api('GET', '/roles')->assertOk();
        $this->api('PUT', '/roles/'.$created['id'], [
            'name_ar' => 'ممرض محدّث', 'permission_ids' => [$this->permission('users.view')],
        ])->assertOk()->assertJsonPath('data.name_ar', 'ممرض محدّث')->assertJsonCount(1, 'data.permissions');
        $this->api('POST', '/roles', [
            'name_ar' => 'متجاوز', 'permission_ids' => [$this->permission('audit.view')],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'ROLE_PERMISSIONS_INVALID');
        $user = $this->api('POST', '', [
            'username' => 'ward-nurse', 'name' => 'ممرض', 'password' => 'secret-pass', 'role_id' => $created['id'],
        ])->assertCreated()->json('data');
        $this->assertSame($created['id'], $user['roles'][0]['id']);
    }

    public function test_role_writes_require_role_permissions_and_user_one_is_not_exempt(): void
    {
        $viewer = User::factory()->create(['username' => 'role-viewer']);
        $viewRole = DB::table('roles')->insertGetId(['code' => 'ROL-VIEW', 'name_ar' => 'عارض أدوار']);
        DB::table('facility_user_roles')->insert(['user_id' => $viewer->id, 'facility_id' => $this->facility, 'role_id' => $viewRole]);
        DB::table('role_permissions')->insert(['role_id' => $viewRole, 'permission_id' => $this->permission('roles.view')]);
        $token = $viewer->createToken('view', ['api'])->plainTextToken;
        $this->api('GET', '/roles', [], $token)->assertOk();
        $this->api('POST', '/roles', ['name_ar' => 'محظور', 'permission_ids' => [$this->permission('roles.view')]], $token)
            ->assertForbidden()->assertJsonPath('error.code', 'ROLES_ACCESS_DENIED');
        $this->api('GET', '/options')->assertOk()
            ->assertJsonPath('data.capabilities.roles_create', true)
            ->assertJsonPath('data.capabilities.roles_view', true);
        $first = User::factory()->create(['username' => 'user-one']);
        $this->api('POST', '/roles', ['name_ar' => 'بدون عضوية', 'permission_ids' => [$this->permission('roles.view')]], $first->createToken('x', ['api'])->plainTextToken)
            ->assertForbidden();
    }
}
