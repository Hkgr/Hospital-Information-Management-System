<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\UserPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserApiTest extends TestCase
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
        $this->user = User::factory()->create(['username' => 'admin-user', 'name' => 'مدير الاختبار']);
        $this->token = $this->user->createToken('user-test', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'USR-A', 'name_ar' => 'منشأة المستخدمين', 'timezone' => 'Asia/Damascus']);
        $this->role = DB::table('roles')->insertGetId(['code' => 'USR-ADMIN', 'name_ar' => 'مدير مستخدمين اختباري']);
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'facility_id' => $this->facility, 'role_id' => $this->role]);
        $this->grant($this->role, ['view', 'create', 'delete']);
    }

    private function grant(int $role, array $actions): void
    {
        foreach ($actions as $action) {
            $permission = DB::table('permissions')->where('code', 'users.'.$action)->value('id');
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    private function api(string $method, string $path = '', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/users'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_list_create_delete_and_hide_password(): void
    {
        $created = $this->api('POST', '', [
            'username' => 'nurse-one', 'name' => 'ممرض اختباري', 'password' => 'secret-pass', 'role_id' => $this->role,
        ])->assertCreated()->assertJsonPath('data.username', 'nurse-one')->json('data');
        $this->assertArrayNotHasKey('password', $created);
        $this->assertTrue(Hash::check('secret-pass', User::where('username', 'nurse-one')->value('password')));
        $this->api('GET')->assertOk()->assertJsonPath('meta.total', 2);
        $this->api('GET', '/options')->assertOk()->assertJsonPath('data.capabilities.create', true)->assertJsonPath('data.capabilities.delete', true);
        $this->api('DELETE', '/'.$created['id'])->assertNoContent();
        $this->assertFalse(DB::table('facility_user_roles')->where('user_id', $created['id'])->where('facility_id', $this->facility)->exists());
        $this->assertDatabaseMissing('users', ['id' => $created['id']]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'auth_session', 'entity_id' => $created['id'], 'event' => 'deleted']);
    }

    public function test_permissions_self_delete_and_uniqueness(): void
    {
        $created = $this->api('POST', '', ['username' => 'clerk', 'name' => 'كاتب', 'password' => 'secret-pass', 'role_id' => $this->role])->assertCreated()->json('data');
        $this->api('POST', '', ['username' => 'clerk', 'name' => 'مكرر', 'password' => 'secret-pass', 'role_id' => $this->role])->assertUnprocessable()->assertJsonPath('error.code', 'USER_USERNAME_TAKEN');
        $this->api('DELETE', '/'.$this->user->id)->assertConflict()->assertJsonPath('error.code', 'USER_SELF_DELETE');
        $viewer = User::factory()->create(['username' => 'viewer-only']);
        $viewRole = DB::table('roles')->insertGetId(['code' => 'USR-VIEW', 'name_ar' => 'عارض']);
        DB::table('facility_user_roles')->insert(['user_id' => $viewer->id, 'facility_id' => $this->facility, 'role_id' => $viewRole]);
        $this->grant($viewRole, ['view']);
        $token = $viewer->createToken('view', ['api'])->plainTextToken;
        $this->api('GET', '', [], $token)->assertOk();
        $this->api('POST', '', ['username' => 'blocked', 'name' => 'محظور', 'password' => 'secret-pass', 'role_id' => $this->role], $token)->assertForbidden()->assertJsonPath('error.code', 'USERS_ACCESS_DENIED');
        $this->api('DELETE', '/'.$created['id'], [], $token)->assertForbidden();
        $stranger = User::factory()->create(['username' => 'stranger']);
        $this->api('GET', '', ['facility_id' => $this->facility], $stranger->createToken('x', ['api'])->plainTextToken)->assertForbidden();
        $this->assertTrue(DB::table('facility_user_roles')->where('user_id', $created['id'])->exists());
    }

    public function test_last_membership_deactivates_when_audit_blocks_hard_delete(): void
    {
        $member = User::factory()->create(['username' => 'logged-in']);
        DB::table('facility_user_roles')->insert(['user_id' => $member->id, 'facility_id' => $this->facility, 'role_id' => $this->role]);
        DB::table('audit_logs')->insert([
            'facility_id' => $this->facility, 'actor_id' => $member->id, 'entity_type' => 'auth_session', 'entity_id' => $member->id,
            'event' => 'login', 'request_id' => '00000000-0000-0000-0000-000000000001', 'occurred_at' => now(),
        ]);
        $this->api('DELETE', '/'.$member->id)->assertNoContent();
        $this->assertFalse(DB::table('facility_user_roles')->where('user_id', $member->id)->exists());
        $this->assertDatabaseHas('users', ['id' => $member->id, 'is_active' => false]);
        $this->assertSame(0, $member->fresh()->tokens()->count());
    }
}
