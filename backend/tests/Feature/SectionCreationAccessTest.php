<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SectionCreationAccessTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private int $facility;

    private int $role;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'CREATE-'.Str::random(14), 'name_ar' => 'مشفى اختبار', 'timezone' => 'Asia/Damascus']);
        $this->role = DB::table('roles')->insertGetId(['code' => 'create-'.Str::random(14), 'name_ar' => 'مدخل بيانات مخول']);
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $this->role, 'facility_id' => $this->facility]);
    }

    private function grants(array $codes): void
    {
        DB::table('role_permissions')->where('role_id', $this->role)->delete();
        $ids = DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id');
        $this->assertCount(count($codes), $ids);
        foreach ($ids as $id) {
            DB::table('role_permissions')->insert(['role_id' => $this->role, 'permission_id' => $id]);
        }
    }

    private function api(string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function catalog(string $kind): array
    {
        return ['kind' => $kind, 'name_ar' => 'عنصر '.Str::random(12), 'is_active' => true, 'request_id' => (string) Str::uuid()];
    }

    public function test_medication_creation_uses_its_own_local_permission_without_medical_access(): void
    {
        $this->grants(['catalog.view', 'medications.create']);
        $this->api('GET', 'service-catalog', ['kind' => 'medication'])->assertOk()->assertJsonPath('capabilities.create', true);
        $input = $this->catalog('medication');
        $row = $this->api('POST', 'service-catalog', $input)->assertCreated()->json('data');
        $this->api('POST', 'service-catalog', $input)->assertCreated()->assertJsonPath('data.id', $row['id']);
        $this->api('POST', 'service-catalog', $this->catalog('procedure'))->assertForbidden();
        $this->api('GET', 'dossiers')->assertForbidden();
        $this->api('GET', 'users/options')->assertForbidden();
        $this->api('PUT', 'service-catalog/medication/'.$row['id'], ['name_ar' => 'تعديل ممنوع', 'is_active' => true, 'lock_version' => $row['lock_version']])->assertForbidden();
        $this->assertSame([], app(GlobalAccess::class)->codes($this->user));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'medication', 'entity_id' => $row['id'], 'actor_id' => $this->user->id, 'facility_id' => $this->facility]);
    }

    public function test_catalog_create_does_not_enable_medication_creation(): void
    {
        $this->grants(['catalog.view', 'catalog.directory.create']);
        $this->api('GET', 'service-catalog', ['kind' => 'medication'])->assertOk()->assertJsonPath('capabilities.create', false);
        $this->api('POST', 'service-catalog', $this->catalog('procedure'))->assertCreated();
        $this->api('POST', 'service-catalog', $this->catalog('medication'))->assertForbidden();
    }

    public function test_existing_global_medication_grant_survives_and_inline_creation_returns_no_medical_data(): void
    {
        $this->grants(['catalog.view']);
        $role = DB::table('roles')->insertGetId(['code' => 'legacy-create-'.Str::random(12), 'name_ar' => 'تفويض تاريخي']);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'medications.create')->value('id')]);
        DB::table('global_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $role]);
        $input = ['name_ar' => 'دواء تاريخي '.Str::random(12), 'request_id' => (string) Str::uuid()];
        $this->api('GET', 'service-catalog', ['kind' => 'medication'])->assertOk()->assertJsonPath('capabilities.create', true);
        $row = $this->api('POST', 'dossiers/medications', $input)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('patient', $row);
        $this->api('GET', 'dossiers')->assertForbidden();
        DB::table('global_user_roles')->where('user_id', $this->user->id)->delete();
        $this->api('POST', 'dossiers/medications', $input)->assertForbidden();
    }

    public function test_local_doctor_creation_and_linking_remain_separate_from_edit_and_global_access(): void
    {
        $this->grants(['doctors.view', 'doctors.directory.create']);
        $type = 'CREATE-'.Str::random(10);
        config(['clinics.doctor_staff_types' => [$type]]);
        $typeId = DB::table('staff_types')->insertGetId(['code' => $type, 'name_ar' => 'طبيب']);
        $specialty = DB::table('specialties')->insertGetId(['code' => $type, 'name_ar' => 'تخصص']);
        $this->api('GET', 'doctors/options')->assertOk()->assertJsonPath('data.capabilities.create', true)->assertJsonPath('data.capabilities.update', false);
        $input = ['name' => 'طبيب تجريبي', 'staff_type_id' => $typeId, 'specialty_ids' => [$specialty], 'is_active' => true, 'request_id' => (string) Str::uuid()];
        $row = $this->api('POST', 'doctors', $input)->assertCreated()->json('data');
        $this->api('POST', 'doctors', $input)->assertCreated()->assertJsonPath('data.id', $row['id']);
        $clinic = DB::table('clinics')->insertGetId(['facility_id' => $this->facility, 'code' => $type, 'name_ar' => 'عيادة']);
        $input['request_id'] = (string) Str::uuid();
        $input['clinic_add_ids'] = [$clinic];
        $this->api('POST', 'doctors', $input)->assertForbidden();
        $this->grants(['doctors.view', 'doctors.directory.create', 'doctors.link', 'clinics.view']);
        $this->api('POST', 'doctors', $input)->assertCreated();
        $this->assertSame([], app(GlobalAccess::class)->codes($this->user));
    }

    public function test_clinic_create_already_works_for_local_roles_and_revocation_is_immediate(): void
    {
        $this->grants(['clinics.view', 'clinics.create']);
        $input = ['name_ar' => 'عيادة اختبار', 'is_active' => true, 'request_id' => (string) Str::uuid()];
        $this->api('POST', 'clinics', $input)->assertCreated();
        $this->grants(['clinics.view']);
        $input['request_id'] = (string) Str::uuid();
        $this->api('POST', 'clinics', $input)->assertForbidden();
    }

    public function test_creation_respects_facility_role_definition_and_account_activity(): void
    {
        $this->grants(['catalog.view', 'medications.create']);
        $other = DB::table('facilities')->insertGetId(['code' => 'OTHER-'.Str::random(12), 'name_ar' => 'مشفى آخر']);
        $this->api('POST', 'service-catalog', $this->catalog('medication') + ['facility_id' => $other])->assertForbidden();
        DB::table('facilities')->where('id', $this->facility)->update(['is_active' => false]);
        $this->api('POST', 'service-catalog', $this->catalog('medication'))->assertForbidden();
        DB::table('facilities')->where('id', $this->facility)->update(['is_active' => true]);
        DB::table('roles')->where('id', $this->role)->update(['is_active' => false]);
        $this->api('POST', 'service-catalog', $this->catalog('medication'))->assertForbidden();
        DB::table('roles')->where('id', $this->role)->update(['is_active' => true]);
        DB::table('permissions')->where('code', 'medications.create')->update(['is_active' => false]);
        $this->api('POST', 'service-catalog', $this->catalog('medication'))->assertForbidden();
        $this->user->forceFill(['is_active' => false])->save();
        $this->api('GET', 'service-catalog')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
    }
}
