<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Database\Seeders\PermissionMatrixPhaseTwoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReceptionReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $facility;

    private User $clerk;

    private User $admin;

    private string $token;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionMatrixPhaseOneSeeder::class, PermissionMatrixPhaseTwoSeeder::class]);
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'REVIEW-'.Str::random(10), 'name_ar' => 'اختبار المراجعة', 'timezone' => 'Asia/Damascus']);
        $this->clerk = $this->user('data_entry');
        $this->admin = $this->user('hospital_admin');
        $this->token = $this->clerk->createToken('review', ['api'])->plainTextToken;
        $this->adminToken = $this->admin->createToken('review', ['api'])->plainTextToken;
    }

    private function user(string $code): User
    {
        $user = User::factory()->create();
        $role = DB::table('roles')->where('code', $code)->value('id');
        DB::table('facility_user_roles')->insert(['facility_id' => $this->facility, 'user_id' => $user->id, 'role_id' => $role]);
        if ($code === 'data_entry') {
            DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        }

        return $user;
    }

    private function api(string $method, string $path, array $data = [], bool $admin = false)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/reception/'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($admin ? $this->adminToken : $this->token)]);
    }

    private function card(): array
    {
        return $this->api('POST', 'registrations', ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'أحمد', 'family_name' => 'تجريبي', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2020-01-01', 'visit_date' => '2020-01-01'])->assertCreated()->json('data');
    }

    private function correctionInput(array $card): array
    {
        return ['request_id' => (string) Str::uuid(), 'patient_version' => DB::table('patients')->where('id', $card['patient_id'])->value('lock_version'), 'dossier_version' => $card['lock_version'], 'reason' => 'تصحيح خطأ إدخال', 'changes' => ['first_name' => 'محمد']];
    }

    private function globals(): void
    {
        $role = DB::table('roles')->insertGetId(['code' => 'review-'.Str::random(12), 'name_ar' => 'مراجع عالمي صريح']);
        foreach (['patients.identity.review', 'patients.duplicates.merge'] as $code) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        DB::table('global_user_roles')->insert(['user_id' => $this->admin->id, 'role_id' => $role]);
    }

    public function test_direct_correction_deadline_owner_versions_replay_and_audit(): void
    {
        $this->freezeTime();
        $card = $this->card();
        $path = 'cards/'.$card['id'];
        $this->api('GET', $path.'/identity')->assertOk()->assertJsonPath('data.correction.can_correct', true);
        $input = $this->correctionInput($card);
        $this->travel(899)->seconds();
        $this->api('POST', $path.'/correct', $input)->assertOk();
        $this->assertDatabaseHas('patients', ['id' => $card['patient_id'], 'first_name' => 'محمد', 'lock_version' => 2]);
        $audit = DB::table('audit_logs')->where('entity_type', 'patient')->where('entity_id', $card['patient_id'])->where('event', 'corrected')->first();
        $this->assertSame('أحمد', json_decode($audit->old_values, true)['first_name']);
        $this->assertSame($this->clerk->id, $audit->actor_id);
        $this->travel(1)->seconds();
        $this->api('GET', $path.'/identity')->assertJsonPath('data.correction.can_correct', false)->assertJsonPath('data.correction.remaining_seconds', 0);
        $this->api('POST', $path.'/correct', $this->correctionInput($card))->assertConflict()->assertJsonPath('error.code', 'IDENTITY_REVIEW_REQUIRED');
        $this->api('POST', $path.'/correct', $input)->assertOk();
        $this->assertSame(2, DB::table('patients')->where('id', $card['patient_id'])->value('lock_version'));
        $this->api('POST', $path.'/correct', array_replace($input, ['reason' => 'different replay']))->assertConflict();
        $this->api('POST', $path.'/correct', array_replace($input, ['request_id' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_direct_edit_requires_ownership_entered_fields_and_no_independent_context(): void
    {
        $card = $this->card();
        $path = 'cards/'.$card['id'].'/correct';
        $this->api('POST', $path, $this->correctionInput($card), true)->assertConflict();
        $this->api('POST', $path, array_replace($this->correctionInput($card), ['changes' => ['phone' => '123']]))->assertConflict();
        $this->api('POST', $path, array_replace($this->correctionInput($card), ['changes' => ['clinical_history' => 'forbidden']]))->assertUnprocessable();
        DB::table('patient_dossiers')->where('id', $card['id'])->update(['clinical_history' => 'independent medical section']);
        $this->api('POST', $path, $this->correctionInput($card))->assertConflict();
        $this->assertDatabaseHas('patients', ['id' => $card['patient_id'], 'first_name' => 'أحمد']);
    }

    public function test_review_requires_global_authority_and_is_atomic_audited_and_idempotent(): void
    {
        $card = $this->card();
        $this->travel(16)->minutes();
        $input = $this->correctionInput($card);
        $id = $this->api('POST', 'cards/'.$card['id'].'/corrections', $input)->assertCreated()->json('data.id');
        $this->api('POST', 'cards/'.$card['id'].'/corrections', $input)->assertJsonPath('data.id', $id);
        $this->assertDatabaseHas('patients', ['id' => $card['patient_id'], 'first_name' => 'أحمد']);
        $this->api('GET', 'reviews/corrections/'.$id)->assertForbidden();
        $this->api('GET', 'reviews/corrections/'.$id, [], true)->assertOk()->assertJsonPath('data.can_approve', false);
        $decision = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'decision' => 'approved', 'reason' => 'تمت مراجعة الهوية'];
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', $decision, true)->assertForbidden();
        $this->globals();
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', $decision, true)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', $decision, true)->assertOk();
        $this->assertDatabaseHas('patients', ['id' => $card['patient_id'], 'first_name' => 'محمد', 'lock_version' => 2]);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', 'patient_identity_correction')->where('entity_id', $id)->where('event', 'approved')->count());
        $this->api('GET', 'cards/'.$card['id'].'/identity')->assertJsonPath('data.requests.0.status', 'approved');
    }

    public function test_changed_identity_requires_new_review_and_rejection_changes_no_identity(): void
    {
        $this->globals();
        $card = $this->card();
        $id = $this->api('POST', 'cards/'.$card['id'].'/corrections', $this->correctionInput($card))->assertCreated()->json('data.id');
        DB::table('patients')->where('id', $card['patient_id'])->increment('lock_version');
        $input = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'decision' => 'approved', 'reason' => 'مراجعة'];
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', $input, true)->assertConflict();
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', array_replace($input, ['decision' => 'rejected']), true)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('patients', ['id' => $card['patient_id'], 'first_name' => 'أحمد']);
    }

    public function test_account_freeze_revokes_tokens_and_local_permissions_cannot_escalate(): void
    {
        $input = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'is_active' => false, 'permissions' => ['reception.view'], 'reason' => 'تجميد مدقق'];
        $path = 'reviews/accounts/'.$this->clerk->id;
        $this->api('PUT', $path, $input)->assertForbidden();
        $this->api('PUT', $path, $input, true)->assertOk();
        $this->api('PUT', $path, $input, true)->assertOk();
        $this->assertSame(0, $this->clerk->tokens()->count());
        $this->assertFalse($this->clerk->fresh()->is_active);
        $this->api('GET', 'options')->assertUnauthorized();
        $reactivate = array_replace($input, ['request_id' => (string) Str::uuid(), 'lock_version' => 2, 'is_active' => true]);
        $this->api('PUT', $path, $reactivate, true)->assertOk();
        $this->token = $this->clerk->fresh()->createToken('fresh', ['api'])->plainTextToken;
        $this->api('GET', 'options')->assertOk();
        $this->api('POST', 'registrations', ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'أحمد', 'family_name' => 'تجريبي', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2020-01-01', 'visit_date' => '2020-01-01'])->assertForbidden();
        $this->api('PUT', $path, array_replace($reactivate, ['request_id' => (string) Str::uuid(), 'permissions' => ['dossiers.view']]), true)->assertUnprocessable();
        $this->api('PUT', 'reviews/accounts/'.$this->admin->id, array_replace($input, ['request_id' => (string) Str::uuid()]), true)->assertForbidden();
        DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'hospital_admin')->value('id'))->where('permission_id', DB::table('permissions')->where('code', 'reception.correct')->value('id'))->delete();
        $this->api('PUT', $path, array_replace($reactivate, ['request_id' => (string) Str::uuid(), 'lock_version' => 3, 'permissions' => ['reception.correct']]), true)->assertForbidden();
    }

    public function test_duplicate_with_visit_is_blocked_without_deleting_or_relinking(): void
    {
        $this->globals();
        $first = $this->card();
        $second = $this->card();
        $pair = ['canonical_dossier_id' => $first['id'], 'duplicate_dossier_id' => $second['id']];
        $preview = $this->api('GET', 'reviews/duplicates/preview', $pair, true)->assertOk()->assertJsonPath('data.can_merge', false)->json('data');
        $id = $this->api('POST', 'reviews/duplicates', $pair + ['request_id' => (string) Str::uuid(), 'preview_hash' => $preview['preview_hash'], 'reason' => 'مراجعة تشابه'], true)->assertCreated()->json('data.id');
        $this->api('POST', 'reviews/duplicates/'.$id.'/decision', ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'decision' => 'approved', 'reason' => 'فحص'], true)->assertConflict();
        $this->assertDatabaseHas('patients', ['id' => $second['patient_id'], 'status' => 'active']);
        $this->assertDatabaseHas('visits', ['id' => $second['registration_visit_id'], 'patient_id' => $second['patient_id']]);
    }

    public function test_empty_duplicate_merge_preserves_identity_code_and_replay(): void
    {
        $this->globals();
        $first = $this->card();
        // Model a legacy empty draft, never a real visit stripped by the workflow.
        $patient = (array) DB::table('patients')->where('id', $first['patient_id'])->first();
        unset($patient['id']);
        $patient['patient_code'] = 'LEGACY-'.Str::random(15);
        $patientId = DB::table('patients')->insertGetId($patient);
        $dossier = (array) DB::table('patient_dossiers')->where('id', $first['id'])->first();
        unset($dossier['id']);
        $dossier['patient_id'] = $patientId;
        $dossier['registration_visit_id'] = null;
        $duplicate = DB::table('patient_dossiers')->insertGetId($dossier);
        $pair = ['canonical_dossier_id' => $first['id'], 'duplicate_dossier_id' => $duplicate];
        $preview = $this->api('GET', 'reviews/duplicates/preview', $pair, true)->assertOk()->assertJsonPath('data.can_merge', true)->json('data');
        $id = $this->api('POST', 'reviews/duplicates', $pair + ['request_id' => (string) Str::uuid(), 'preview_hash' => $preview['preview_hash'], 'reason' => 'هوية فارغة مكررة'], true)->assertCreated()->json('data.id');
        $decision = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'decision' => 'approved', 'reason' => 'لا توجد وقائع مستقلة'];
        $this->api('POST', 'reviews/duplicates/'.$id.'/decision', $decision, true)->assertOk()->assertJsonPath('data.status', 'approved');
        $this->api('POST', 'reviews/duplicates/'.$id.'/decision', $decision, true)->assertOk();
        $this->assertDatabaseHas('patients', ['id' => $patientId, 'status' => 'merged', 'merged_into_id' => $first['patient_id'], 'patient_code' => $patient['patient_code']]);
        $this->assertDatabaseHas('patient_dossiers', ['id' => $duplicate, 'patient_id' => $patientId]);
        $audit = DB::table('audit_logs')->where('entity_type', 'patient')->where('entity_id', $patientId)->where('event', 'merged')->first();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/audit/'.$audit->id.'?facility_id='.$this->facility, ['Authorization' => 'Bearer '.$this->adminToken])->assertOk()->assertJsonFragment(['field' => 'merged_into_id', 'label' => 'الهوية المعتمدة', 'before' => null, 'after' => (string) $first['patient_id'], 'before_recorded' => false]);
        $this->api('GET', 'patients', ['search' => $patient['patient_code']])->assertOk()->assertJsonFragment(['code' => $first['code']]);
        $this->assertSame(0, DB::table('visits')->where('patient_id', $patientId)->count());
    }

    public function test_cross_facility_identity_blocks_direct_edit_and_invalidates_pending_review(): void
    {
        $this->globals();
        $card = $this->card();
        $input = $this->correctionInput($card);
        $id = $this->api('POST', 'cards/'.$card['id'].'/corrections', $input)->assertCreated()->json('data.id');
        $other = DB::table('facilities')->insertGetId(['code' => 'OTHER-'.Str::random(10), 'name_ar' => 'منشأة مستقلة', 'timezone' => 'Asia/Damascus']);
        $d = (array) DB::table('patient_dossiers')->where('id', $card['id'])->first();
        unset($d['id']);
        $d['facility_id'] = $other;
        $d['registration_visit_id'] = null;
        $otherId = DB::table('patient_dossiers')->insertGetId($d);
        $this->api('POST', 'cards/'.$card['id'].'/correct', $input)->assertConflict();
        $this->api('GET', 'cards/'.$otherId.'/identity')->assertNotFound();
        $this->api('GET', 'cards/'.$otherId.'/identity', ['facility_id' => $other])->assertForbidden();
        $this->api('POST', 'reviews/corrections/'.$id.'/decision', ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'decision' => 'approved', 'reason' => 'مراجعة'], true)->assertConflict();
        $second = $this->card();
        $this->api('GET', 'reviews/duplicates/preview', ['canonical_dossier_id' => $card['id'], 'duplicate_dossier_id' => $second['id']], true)->assertJsonPath('data.can_merge', false);
        $role = DB::table('roles')->where('code', 'data_entry')->value('id');
        DB::table('facility_user_roles')->insert(['facility_id' => $other, 'user_id' => $this->clerk->id, 'role_id' => $role]);
        $this->api('PUT', 'reviews/accounts/'.$this->clerk->id, ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'is_active' => false, 'permissions' => [], 'reason' => 'تجميد'], true)->assertForbidden();
    }

    public function test_protected_account_and_revoked_permissions_fail_closed_and_rollback_preserves_history(): void
    {
        $card = $this->card();
        $migration = require database_path('migrations/2026_09_28_000001_add_identity_review_workflows.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must refuse before DDL');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback will not delete', $e->getMessage());
        }
        $this->assertDatabaseHas('reception_identity_windows', ['dossier_id' => $card['id']]);
        $protected = User::find(1) ?? User::factory()->create(['id' => 1]);
        DB::table('facility_user_roles')->insert(['facility_id' => $this->facility, 'user_id' => $protected->id, 'role_id' => DB::table('roles')->where('code', 'data_entry')->value('id')]);
        $this->api('PUT', 'reviews/accounts/1', ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'is_active' => false, 'permissions' => [], 'reason' => 'غير مسموح'], true)->assertForbidden();
        $role = DB::table('roles')->where('code', 'data_entry')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', DB::table('permissions')->where('code', 'reception.correct')->value('id'))->delete();
        $this->api('POST', 'cards/'.$card['id'].'/correct', $this->correctionInput($card))->assertForbidden();
        $this->clerk->forceFill(['is_active' => false])->save();
        $this->api('GET', 'cards/'.$card['id'].'/identity')->assertForbidden();
        $this->assertSame(0, $this->clerk->tokens()->count());
        $this->seed(PermissionMatrixPhaseTwoSeeder::class);
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->assertSame(0, DB::table('role_permissions')->where('role_id', DB::table('roles')->where('code', 'statistics')->value('id'))->count());
    }
}
