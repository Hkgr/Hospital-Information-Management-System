<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\UserAccessContext;
use Database\Seeders\DossierAuditPermissionsSeeder;
use Database\Seeders\DossierPermissionsSeeder;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Database\Seeders\UserPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    private int $facility;

    private int $other;

    private User $clerk;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([UserPermissionsSeeder::class, DossierPermissionsSeeder::class, DossierAuditPermissionsSeeder::class, PermissionMatrixPhaseOneSeeder::class]);
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'MATRIX-'.Str::random(10), 'name_ar' => 'مشفى اختبار', 'timezone' => 'Asia/Damascus']);
        $this->other = DB::table('facilities')->insertGetId(['code' => 'MATRIX-'.Str::random(10), 'name_ar' => 'مشفى آخر', 'timezone' => 'Asia/Damascus']);
        $this->clerk = $this->assigned('data_entry');
        $this->token = $this->clerk->createToken('matrix', ['api'])->plainTextToken;
    }

    private function assigned(string $role): User
    {
        $user = User::factory()->create();
        $id = DB::table('roles')->where('code', $role)->value('id');
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $this->facility, 'role_id' => $id]);
        if ($role === 'data_entry') {
            DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $id]);
        }

        return $user;
    }

    private function api(string $method, string $path, array $input = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $input + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function registration(): array
    {
        return ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'أحمد', 'family_name' => 'تجريبي', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2020-01-01', 'visit_date' => '2020-01-01'];
    }

    public function test_clerk_registration_reuses_atomic_writer_and_never_exposes_medical_history(): void
    {
        $input = $this->registration();
        $row = $this->api('POST', 'reception/registrations', $input)->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $this->assertMatchesRegularExpression('/^PC-\d+$/', $row['code']);
        $this->assertSame(['id', 'patient_id', 'code', 'first_name', 'family_name', 'birth_date', 'gender', 'opening_date', 'status', 'lock_version', 'registration_visit_id'], array_keys($row));
        $this->assertDatabaseHas('visits', ['id' => $row['registration_visit_id'], 'dossier_id' => $row['id'], 'status' => 'draft']);
        $this->api('POST', 'reception/registrations', $input)->assertCreated()->assertJsonPath('data.id', $row['id']);
        $this->api('POST', 'reception/registrations', array_replace($input, ['first_name' => 'تغيير']))->assertConflict();
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $row['id'])->count());
        $this->api('GET', 'reception/patients', ['search' => '  أحمد   تجريبي  '])->assertOk()->assertJsonFragment(['code' => $row['code']]);
        $this->api('GET', 'reception/cards/'.$row['id'])->assertOk();
        foreach (['dossiers/'.$row['id'], 'dossiers/'.$row['id'].'/progress', 'dossiers/'.$row['id'].'/visits/'.$row['registration_visit_id'], 'dossiers/'.$row['id'].'/visits/'.$row['registration_visit_id'].'/progress', 'dossiers/'.$row['id'].'/pathology', 'dossiers/'.$row['id'].'/audit', 'users', 'reports'] as $path) {
            $this->api('GET', $path, $path === 'reports' ? ['period' => 'day'] : [])->assertForbidden();
        }
        $this->api('DELETE', 'dossiers/'.$row['id'])->assertForbidden();
        $this->api('POST', 'dossiers/'.$row['id'].'/report/xlsx')->assertForbidden();
        $opens = DB::table('audit_logs')->where('entity_type', 'patient_dossier')->where('entity_id', $row['id'])->where('event', 'opened')->get();
        $this->assertCount(1, $opens);
        $this->assertSame(['surface' => 'reception', 'visit_id' => null], json_decode($opens[0]->new_values, true));
        $this->assertSame($this->clerk->id, $opens[0]->actor_id);
        $this->api('GET', 'reception/cards/'.$row['id'], ['facility_id' => $this->other])->assertForbidden();
    }

    public function test_roles_scopes_revocation_and_disabled_accounts_are_checked_per_request(): void
    {
        foreach (['statistics', 'hospital_admin'] as $role) {
            $user = $this->assigned($role);
            $token = $user->createToken('matrix', ['api'])->plainTextToken;
            $this->api('GET', 'dossiers', [], $token)->assertStatus($role === 'statistics' ? 403 : 200);
            $this->api('GET', 'users', [], $token)->assertStatus($role === 'statistics' ? 403 : 200);
        }
        $ordinary = User::factory()->create(['name' => 'super_admin']);
        $this->api('GET', 'reception/options', [], $ordinary->createToken('ordinary', ['api'])->plainTextToken)->assertForbidden();
        $this->api('GET', 'reception/options')->assertOk();
        DB::table('global_user_roles')->where('user_id', $this->clerk->id)->delete();
        $this->api('GET', 'reception/patients', ['search' => 'أحمد'])->assertForbidden();
        DB::table('facility_user_roles')->where('user_id', $this->clerk->id)->delete();
        $this->api('GET', 'reception/options')->assertForbidden();
        $this->clerk->is_active = false;
        $this->clerk->save();
        $this->api('GET', 'reception/options')->assertForbidden();
        $this->assertSame(0, $this->clerk->tokens()->count());
    }

    public function test_system_assignment_is_explicit_dynamic_protected_and_idempotent(): void
    {
        $user = User::find(1) ?? User::factory()->create(['id' => 1]);
        $before = $user->fresh()->getAttributes();
        // Isolated transaction: remove only fixture assignments of the reserved role
        // so the test does not promote any preexisting test role holder.
        $role = DB::table('roles')->where('code', 'super_admin')->value('id');
        DB::table('facility_user_roles')->where('role_id', $role)->delete();
        DB::table('global_user_roles')->where('role_id', $role)->delete();
        $this->assertNull(app(GlobalAccess::class)->systemRole($user));
        $this->artisan('access:super-admin', ['--apply' => true, '--execution-reference' => 'CHANGE-58/operator-test', '--reason' => 'synthetic matrix test'])->assertSuccessful();
        $audit = DB::table('audit_logs')->where('facility_id', $this->facility)->where('entity_type', 'role')->where('event', 'assigned')->first();
        $this->assertNull($audit->actor_id, 'A CLI assignment must not impersonate its beneficiary.');
        $facts = json_decode($audit->new_values, true);
        $this->assertSame(1, $facts['user_id']);
        $this->assertSame('CHANGE-58/operator-test', $facts['execution_reference']);
        $auditCount = DB::table('audit_logs')->where('event', 'assigned')->count();
        $this->artisan('access:super-admin', ['--apply' => true, '--execution-reference' => 'CHANGE-58/operator-test', '--reason' => 'idempotent retry'])->assertSuccessful();
        $this->assertEquals($before, $user->fresh()->getAttributes());
        $this->assertSame($auditCount, DB::table('audit_logs')->where('event', 'assigned')->count());
        $this->assertSame(1, DB::table('global_user_roles')->where('user_id', 1)->where('role_id', $role)->count());
        $token = $user->createToken('system-test', ['api'])->plainTextToken;
        foreach (['audit?category=accounts', 'audit/'.$audit->id] as $path) {
            $this->api('GET', $path, [], $token)->assertOk()
                ->assertJsonFragment(['id' => null, 'name' => 'أمر طرفية — ليس جلسة مستخدم'])
                ->assertJsonFragment(['field' => 'user_id', 'label' => 'المستخدم المستفيد من التعيين', 'before' => null, 'after' => '1', 'before_recorded' => false])
                ->assertJsonFragment(['after' => 'CHANGE-58/operator-test']);
        }
        $this->api('GET', 'users', ['facility_id' => $this->other], $token)->assertOk();
        $this->api('GET', 'doctors/options', ['facility_id' => $this->other], $token)->assertOk()->assertJsonPath('data.capabilities.create', true);
        $this->api('GET', 'service-catalog', ['facility_id' => $this->other], $token)->assertOk()->assertJsonPath('capabilities.create', true);
        $this->api('GET', 'dossiers/options/patients', ['facility_id' => $this->other, 'search' => 'nobody'], $token)->assertOk();
        $this->assertTrue(app(GlobalAccess::class)->allows($user, 'patients.create'));
        $new = DB::table('facilities')->insertGetId(['code' => 'MATRIX-'.Str::random(10), 'name_ar' => 'منشأة جديدة', 'timezone' => 'Asia/Damascus']);
        DB::table('permissions')->insert(['code' => 'matrix.new', 'name_ar' => 'صلاحية جديدة', 'is_active' => true]);
        $entries = collect(app(UserAccessContext::class)->forUser($user));
        $this->assertContains('matrix.new', $entries->firstWhere('facility.id', $new)['permissions']);
        $this->assertFalse(app(GlobalAccess::class)->allows($this->clerk, 'matrix.new'));
        $this->api('PUT', 'users/roles/'.$role, ['name_ar' => 'تغيير', 'permission_ids' => [DB::table('permissions')->where('code', 'users.view')->value('id')]], $token)->assertForbidden();
        $this->api('POST', 'users', ['username' => 'escalation-'.Str::random(8), 'name' => 'آخر', 'password' => 'secure-test-password', 'role_id' => $role], $token)->assertForbidden();
        $this->api('DELETE', 'users/1', [], $token)->assertForbidden();
        $user->is_active = false;
        $user->save();
        $this->assertSame([], app(UserAccessContext::class)->forUser($user));
    }

    public function test_definitions_do_not_grant_statistics_identified_reports_or_assign_users(): void
    {
        $before = DB::table('facility_user_roles')->count();
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->assertSame($before, DB::table('facility_user_roles')->count());
        $this->assertSame(0, DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', DB::table('roles')->where('code', 'statistics')->value('id'))->whereNotIn('p.code', ['statistics.view', 'statistics.export'])->count());
        $this->assertFalse(app(GlobalAccess::class)->allows($this->clerk, 'patients.search'));
        DB::table('facilities')->where('id', $this->facility)->update(['is_active' => false]);
        $this->api('GET', 'reception/options')->assertForbidden();
    }

    public function test_every_medical_detail_entry_is_audited_after_authorization_and_lookup(): void
    {
        $card = $this->api('POST', 'reception/registrations', $this->registration())->assertCreated()->json('data');
        $id = $card['id'];
        $visit = $card['registration_visit_id'];
        $reader = $this->assigned('hospital_admin');
        $token = $reader->createToken('reader', ['api'])->plainTextToken;
        DB::table('patient_dossiers')->where('id', $id)->update(['clinical_history' => 'MEDICAL-PRIVATE']);
        foreach (["dossiers/$id", "dossiers/$id/progress", "dossiers/$id/visits/$visit", "dossiers/$id/visits/$visit/progress"] as $path) {
            $this->api('GET', $path, [], $token)->assertOk();
        }
        $query = DB::table('audit_logs')->where('entity_type', 'patient_dossier')->where('entity_id', $id)->where('event', 'opened');
        $this->assertSame(4, $query->count());
        $this->assertStringNotContainsString('MEDICAL-PRIVATE', $query->get()->toJson());
        $this->api('GET', "dossiers/$id", ['facility_id' => $this->other], $token)->assertForbidden();
        $this->api('GET', 'dossiers/999999999', [], $token)->assertNotFound();
        $this->assertSame(4, $query->count());
        $this->api('GET', "dossiers/$id/audit", ['action' => 'opened'], $token)->assertOk()->assertJsonPath('meta.total', 4);
        $this->api('GET', 'audit', ['action' => 'opened'], $token)->assertOk()->assertJsonFragment(['action' => 'opened']);
        $this->api('GET', "reception/cards/$id")->assertOk()->assertDontSee('MEDICAL-PRIVATE');
    }

    public function test_migration_rollback_refuses_to_remove_an_enabled_security_marker(): void
    {
        DB::table('roles')->where('code', 'super_admin')->update(['is_system_super_admin' => true]);
        $migration = require database_path('migrations/2026_09_27_000001_protect_system_super_admin_role.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reviewed maintenance procedure');
        $migration->down();
    }

    public function test_operator_command_requires_reference_without_assigning_or_claiming_an_actor(): void
    {
        User::find(1) ?? User::factory()->create(['id' => 1]);
        $before = DB::table('global_user_roles')->get()->toJson();
        foreach (['', str_repeat('x', 256)] as $reference) {
            $this->artisan('access:super-admin', ['--apply' => true, '--reason' => 'reviewed change', '--execution-reference' => $reference])
                ->expectsOutput('--execution-reference must contain 1 to 255 characters.')->assertFailed();
        }
        $this->assertSame($before, DB::table('global_user_roles')->get()->toJson());
    }

    public function test_operator_audit_rollback_preserves_events_without_inventing_actors(): void
    {
        $id = DB::table('audit_logs')->insertGetId(['facility_id' => $this->facility, 'actor_id' => null, 'entity_type' => 'role', 'entity_id' => 1, 'event' => 'assigned', 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
        $migration = require database_path('migrations/2026_09_27_000002_allow_operator_audit_without_user.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve operator audit history.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot invent an actor', $exception->getMessage());
        }
        $this->assertDatabaseHas('audit_logs', ['id' => $id, 'actor_id' => null]);
    }

    public function test_missing_user_query_fails_assignment_without_creating_a_user_or_grant(): void
    {
        $before = DB::table('global_user_roles')->count();
        // Simulate the lookup returning no user, without deleting referenced test users.
        User::addGlobalScope('missing-target', fn ($q) => $q->where('id', '!=', 1));
        try {
            $this->artisan('access:super-admin', ['--apply' => true, '--execution-reference' => 'CHANGE-58/operator-test', '--reason' => 'missing target test'])
                ->expectsOutput('User 1 does not exist. No user or assignment was created.')->assertFailed();
            $this->assertSame($before, DB::table('global_user_roles')->count());
        } finally {
            User::clearBootedModels();
        }
    }
}
