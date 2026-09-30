<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SurfacePermissionsSeeder;
use Database\Seeders\TaskPermissionsSeeder;
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

    public function test_task_prerequisites_require_explicit_selection_and_templates_do_not_grant(): void
    {
        $codes = ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.services.update'];
        $this->grant($codes);
        $before = DB::table('role_permissions')->count();
        $this->api('GET', '/options')->assertOk()->assertJsonCount(5, 'data.task_templates');
        $this->assertSame($before, DB::table('role_permissions')->count());
        $this->api('POST', '/roles', ['name_ar' => 'Service task', 'permission_ids' => [$this->permission('dossiers.services.update')]])->assertUnprocessable();
        $role = $this->api('POST', '/roles', ['name_ar' => 'Service task', 'permission_ids' => array_map($this->permission(...), $codes)])->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing($codes, array_column($role['permissions'], 'code'));
        $this->api('POST', '/roles', ['name_ar' => 'Escalated task', 'permission_ids' => [$this->permission('dossiers.procedures.update'), ...array_map($this->permission(...), $codes)]])->assertUnprocessable();
    }

    public function test_dose_void_role_requires_explicit_schedule_correction_on_create_and_update(): void
    {
        $base = ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.treatment.view', 'dossiers.treatment.administration.void'];
        $complete = [...$base, 'dossiers.treatment.schedule.update'];
        $this->grant($complete);
        $permissions = collect($this->api('GET', '/options')->assertOk()->json('data.permission_groups'))->pluck('permissions')->flatten(1);
        $void = $permissions->firstWhere('code', 'dossiers.treatment.administration.void');
        $this->assertContains('dossiers.treatment.schedule.update', $void['prerequisites']);
        $this->assertStringContainsString('الإلغاء يتضمن معالجة حالة الجلسة المرتبطة', $void['description']);
        $before = DB::table('role_permissions')->count();
        $this->api('POST', '/roles', ['name_ar' => 'إلغاء ناقص', 'permission_ids' => array_map($this->permission(...), $base)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ROLE_PREREQUISITES_REQUIRED')
            ->assertJsonPath('error.missing_permissions', ['dossiers.treatment.schedule.update']);
        $this->assertSame($before, DB::table('role_permissions')->count());
        $created = $this->api('POST', '/roles', ['name_ar' => 'إلغاء ومعالجة الجلسة', 'permission_ids' => array_map($this->permission(...), $complete)])
            ->assertCreated()->json('data');
        $this->assertEqualsCanonicalizing($complete, array_column($created['permissions'], 'code'));
        $this->api('PUT', '/roles/'.$created['id'], ['name_ar' => 'إلغاء ناقص', 'permission_ids' => array_map($this->permission(...), $base)])
            ->assertUnprocessable()->assertJsonPath('error.code', 'ROLE_PREREQUISITES_REQUIRED');
        $this->assertSame(count($complete), DB::table('role_permissions')->where('role_id', $created['id'])->count());

        // Updating definitions must not silently repair an existing incomplete role.
        DB::table('role_permissions')->where('role_id', $created['id'])->where('permission_id', $this->permission('dossiers.treatment.schedule.update'))->delete();
        $existing = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $this->seed(TaskPermissionsSeeder::class);
        $this->assertSame($existing, DB::table('role_permissions')->orderBy('id')->get()->toJson());
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
