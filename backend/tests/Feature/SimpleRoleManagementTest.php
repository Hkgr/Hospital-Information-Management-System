<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Users\ProtectedRolePolicy;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SimpleRoleManagementTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;

    private int $facility;

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'SIMPLE-'.Str::random(12), 'name_ar' => 'اختبار الصلاحيات', 'timezone' => 'Asia/Damascus']);
        $this->admin = User::factory()->create();
        $this->role = $this->role(['users.view', 'roles.view', 'roles.update'], true);
        DB::table('global_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $this->role]);
        DB::table('facility_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $this->role, 'facility_id' => $this->facility]);
    }

    private function ids(array $codes): array
    {
        $ids = DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id')->all();
        $this->assertCount(count($codes), $ids, 'Required definitions must already exist in the isolated database.');

        return $ids;
    }

    private function role(array $codes, bool $protected = false): int
    {
        $id = DB::table('roles')->insertGetId(['code' => 'simple-'.Str::random(12), 'name_ar' => 'دور اصطناعي', 'is_system_super_admin' => $protected]);
        foreach ($this->ids($codes) as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $id, 'permission_id' => $permission]);
        }

        return $id;
    }

    private function api(string $method, string $path, array $input = [], ?User $user = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, ['facility_id' => $this->facility] + $input, ['Authorization' => 'Bearer '.($user ?? $this->admin)->createToken('test', ['api'])->plainTextToken]);
    }

    public function test_protected_role_authority_can_delegate_operations_it_does_not_execute(): void
    {
        $this->assertFalse(app(GlobalAccess::class)->allows($this->admin, 'catalog.view'));
        $options = $this->api('GET', 'users/options')->assertOk()->assertJsonPath('data.capabilities.roles_create', true)->json('data');
        $this->assertContains('catalog.view', collect($options['permission_groups'])->pluck('permissions')->flatten(1)->pluck('code')->all());
        $role = $this->api('POST', 'users/roles', ['name_ar' => 'دور تشغيلي', 'permission_ids' => $this->ids(['catalog.view'])])->assertCreated()->json('data');
        $this->api('PUT', 'users/roles/'.$role['id'], ['name_ar' => 'دور تشغيلي محدث', 'permission_ids' => $this->ids(['catalog.view', 'catalog.export']), 'lock_version' => $role['lock_version'], 'reason' => 'اختيار صريح'])->assertOk();
        $this->assertFalse(app(GlobalAccess::class)->allows($this->admin->fresh(), 'catalog.view'));
    }

    public function test_protected_role_can_edit_its_policy_without_an_operator_consolidation_command(): void
    {
        $role = $this->api('GET', 'users/roles/'.$this->role)->assertOk()->json('data');
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', ['name_ar' => $role['name_ar'], 'permission_ids' => $this->ids(ProtectedRolePolicy::MINIMUM), 'lock_version' => $role['lock_version'], 'reason' => 'حفظ إدارة الوصول فقط'])->assertOk();
        $this->api('PUT', 'users/roles/'.$this->role.'/protected-permissions', ['name_ar' => $role['name_ar'], 'permission_ids' => $this->ids(['users.view']), 'lock_version' => $role['lock_version'] + 1, 'reason' => 'محاولة إغلاق الإدارة'])->assertUnprocessable()->assertJsonPath('error.code', 'PROTECTED_ACCESS_MINIMUM');
    }

    public function test_legacy_search_role_stays_historical_and_cannot_be_newly_assigned(): void
    {
        DB::table('roles')->insertOrIgnore(['code' => 'hospital_admin_patient_search', 'name_ar' => 'بحث قديم']);
        $legacy = (int) DB::table('roles')->where('code', 'hospital_admin_patient_search')->value('id');
        DB::table('roles')->where('id', $legacy)->update(['is_active' => true]);
        foreach ($this->ids(['patients.basic.view', 'patients.basic.search']) as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $legacy, 'permission_id' => $permission]);
        }
        $user = User::factory()->create();
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'role_id' => $legacy, 'facility_id' => $this->facility]);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $legacy]);
        $before = app(GlobalAccess::class)->codes($user);
        $roles = $this->api('GET', 'users/roles')->assertOk()->json('data');
        $this->assertNotContains($legacy, array_column($roles, 'id'));
        $options = $this->api('GET', 'users/options')->assertOk()->json('data');
        $this->assertNotContains($legacy, array_column($options['roles'], 'id'));
        $this->api('GET', 'users', ['search' => $user->username])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.roles', [])->assertJsonPath('data.0.legacy_search_access', true);
        $new = $this->role(['patients.basic.view']);
        // Fetch after creating the selectable role so the preview includes it.
        $view = $this->api('GET', 'users/'.$user->id.'/access')->json('data');
        $this->api('PUT', 'users/'.$user->id.'/access', ['lock_version' => $view['lock_version'], 'access_fingerprint' => $view['access_fingerprint'], 'local_role_ids' => [$legacy], 'reason' => 'إسناد قديم ممنوع'])->assertForbidden();
        $this->api('PUT', 'users/'.$user->id.'/access', ['lock_version' => $view['lock_version'], 'access_fingerprint' => $view['access_fingerprint'], 'local_role_ids' => [$new], 'reason' => 'إسناد جديد دون محو التاريخ'])->assertOk();
        $this->assertDatabaseHas('facility_user_roles', ['user_id' => $user->id, 'role_id' => $legacy]);
        $this->assertSame($before, app(GlobalAccess::class)->codes($user->fresh()));
        $this->api('GET', 'users/options', [], $user)->assertForbidden();
    }

    public function test_hospital_admin_can_select_local_basic_search_without_a_search_role_or_global_grant(): void
    {
        $hospitalRole = (int) DB::table('roles')->where('code', 'hospital_admin')->value('id');
        $this->assertGreaterThan(0, $hospitalRole);
        // Restrict this existing definition inside the rolled-back fixture only.
        DB::table('role_permissions')->where('role_id', $hospitalRole)->delete();
        foreach ($this->ids(['patients.basic.view', 'patient_cards.register']) as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $hospitalRole, 'permission_id' => $permission]);
        }
        $user = User::factory()->create();
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'role_id' => $hospitalRole, 'facility_id' => $this->facility]);
        $card = $this->api('POST', 'patient-cards/registrations', ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'مريض', 'family_name' => 'نطاق الاختبار', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2020-01-02', 'visit_date' => '2020-01-03'], $user)->assertCreated()->json('data');
        $view = $this->api('GET', 'users/roles/'.$hospitalRole)->assertOk()->json('data');
        $this->api('PUT', 'users/roles/'.$hospitalRole, ['name_ar' => $view['name_ar'], 'lock_version' => $view['lock_version'], 'permission_ids' => $this->ids(['patients.basic.view']), 'reason' => 'اختيار البحث المحلي فقط'])->assertOk();
        $row = $this->api('GET', 'patient-cards/patients', ['search' => 'مريض نطاق الاختبار'], $user)->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertSame(['id', 'code', 'first_name', 'family_name', 'birth_date', 'gender', 'dossier_id'], array_keys($row));
        $this->assertSame([], app(GlobalAccess::class)->codes($user->fresh()));
        DB::table('patients')->insert(['identity_document_type' => 'none', 'created_by' => $this->admin->id, 'patient_code' => 'EXTERNAL-'.Str::random(10), 'first_name' => 'مريض', 'family_name' => 'خارج النطاق', 'search_name' => 'مريض خارج النطاق', 'status' => 'active']);
        $this->api('GET', 'patient-cards/patients', ['search' => 'مريض خارج النطاق'], $user)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', 'dossiers/'.$card['id'], [], $user)->assertForbidden();
        $this->api('POST', 'dossiers/'.$card['id'].'/report/pdf', [], $user)->assertForbidden();
    }

    public function test_unprotected_or_inactive_role_never_confers_delegation_authority(): void
    {
        DB::table('roles')->where('id', $this->role)->update(['is_system_super_admin' => false]);
        $this->api('POST', 'users/roles', ['name_ar' => 'ممنوع', 'permission_ids' => $this->ids(['catalog.view'])])->assertForbidden();
        DB::table('roles')->where('id', $this->role)->update(['is_system_super_admin' => true, 'is_active' => false]);
        $this->api('GET', 'users/options')->assertForbidden();
    }

    public function test_local_creation_choices_are_not_duplicated_as_new_global_grants_but_history_remains_revocable(): void
    {
        $user = User::factory()->create();
        $local = $this->role(['catalog.view', 'medications.create']);
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'role_id' => $local, 'facility_id' => $this->facility]);
        $view = $this->api('GET', 'users/'.$user->id.'/access')->assertOk()->json('data');
        $this->assertNotContains('medications.create', array_column($view['global_permissions'], 'code'));
        $input = ['lock_version' => $view['lock_version'], 'access_fingerprint' => $view['access_fingerprint'], 'global_permission_ids' => $this->ids(['medications.create']), 'reason' => 'تفويض مكرر غير مطلوب'];
        $this->api('PUT', 'users/'.$user->id.'/access', $input)->assertUnprocessable()->assertJsonPath('error.code', 'GLOBAL_PERMISSION_INVALID');

        $legacy = $this->role(['medications.create']);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $legacy]);
        $view = $this->api('GET', 'users/'.$user->id.'/access')->assertOk()->json('data');
        $this->assertContains('medications.create', array_column($view['global_permissions'], 'code'));
        $this->api('PUT', 'users/'.$user->id.'/access', ['lock_version' => $view['lock_version'], 'access_fingerprint' => $view['access_fingerprint'], 'global_permission_ids' => [], 'reason' => 'سحب التفويض التاريخي فقط'])->assertOk()->assertJsonPath('data.global_effective_codes', []);
        $this->assertDatabaseHas('facility_user_roles', ['user_id' => $user->id, 'role_id' => $local, 'facility_id' => $this->facility]);
        $this->api('GET', 'service-catalog', ['kind' => 'medication'], $user)->assertOk()->assertJsonPath('capabilities.create', true);
    }
}
