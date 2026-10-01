<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DirectPatientCardTest extends TestCase
{
    use RefreshDatabase;

    private User $clerk;

    private int $facility;

    private int $other;

    private int $role;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clerk = User::factory()->create();
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'CARD-'.Str::random(12), 'name_ar' => 'مشفى اختبار البطاقة', 'timezone' => 'Asia/Damascus']);
        $this->other = DB::table('facilities')->insertGetId(['code' => 'CARD-'.Str::random(12), 'name_ar' => 'مشفى آخر', 'timezone' => 'Asia/Damascus']);
        $this->role = DB::table('roles')->insertGetId(['code' => 'card-'.Str::random(12), 'name_ar' => 'إضافة بطاقة محلية']);
        foreach (['patients.basic.view', 'patient_cards.register'] as $code) {
            $id = DB::table('permissions')->where('code', $code)->value('id');
            $this->assertNotNull($id, 'Existing task definition required: '.$code);
            DB::table('role_permissions')->insert(['role_id' => $this->role, 'permission_id' => $id]);
        }
        DB::table('facility_user_roles')->insert(['facility_id' => $this->facility, 'user_id' => $this->clerk->id, 'role_id' => $this->role]);
        $this->token = $this->clerk->createToken('card-test', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $body + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function input(): array
    {
        return ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'مريض', 'family_name' => 'بطاقة مباشرة', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2020-01-02', 'visit_date' => '2020-01-03'];
    }

    public function test_local_card_role_can_save_without_global_patient_delegation(): void
    {
        $before = DB::table('global_user_roles')->get()->toJson();
        // Existing compatibility route, so the regression demonstrates the actual
        // global-permission barrier rather than just a missing new route.
        $body = $this->input();
        $card = $this->api('POST', 'reception/registrations', $body)->assertCreated()->json('data');
        $this->assertSame($before, DB::table('global_user_roles')->get()->toJson());
        $this->api('POST', 'patient-cards/registrations', $body)->assertCreated()->assertJsonPath('data.id', $card['id']);
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $card['id'])->count());
        $this->api('GET', 'patient-cards/cards/'.$card['id'])->assertOk()->assertJsonPath('data.workflow', null)->assertJsonMissingPath('data.medical');
        foreach (['dossiers/'.$card['id'], 'dossiers/'.$card['id'].'/progress', 'dossiers/options/patients'] as $path) {
            $this->api('GET', $path)->assertForbidden();
        }
        $this->api('DELETE', 'dossiers/'.$card['id'])->assertForbidden();
        $this->api('POST', 'dossiers/'.$card['id'].'/report/pdf')->assertForbidden();
        $this->api('POST', 'patient-cards/registrations', array_replace($body, ['first_name' => 'مختلف']))->assertConflict();
    }

    public function test_old_reception_account_writes_are_retired_without_changing_assignments(): void
    {
        $before = DB::table('facility_user_roles')->get()->toJson();
        $this->api('PUT', 'reception/reviews/accounts/'.$this->clerk->id, ['request_id' => (string) Str::uuid(), 'lock_version' => 0, 'is_active' => false, 'permissions' => [], 'reason' => 'old account path'])->assertStatus(410)->assertJsonPath('error.code', 'RECEPTION_ACCOUNTS_RETIRED');
        $this->assertTrue($this->clerk->fresh()->is_active);
        $this->assertSame($before, DB::table('facility_user_roles')->get()->toJson());
    }

    public function test_local_lookup_matches_names_and_codes_without_disclosing_foreign_identity_or_medical_data(): void
    {
        $card = $this->api('POST', 'patient-cards/registrations', $this->input())->assertCreated()->json('data');
        foreach ([$card['code'], 'مريض بطاقة مباشرة'] as $search) {
            $row = $this->api('GET', 'patient-cards/patients', ['search' => $search])->assertOk()->assertJsonCount(1, 'data')->json('data.0');
            $this->assertSame(['id', 'code', 'first_name', 'family_name', 'birth_date', 'gender', 'dossier_id'], array_keys($row));
        }
        $this->api('POST', 'patient-cards/registrations', ['request_id' => (string) Str::uuid(), 'person_mode' => 'existing', 'patient_id' => $card['patient_id'], 'opening_date' => '2020-01-02', 'visit_date' => '2020-01-03'])
            ->assertConflict()->assertJsonPath('error.existing_dossier_id', $card['id']);
        DB::table('patient_dossiers')->where('id', $card['id'])->update(['status' => 'draft']);
        $this->api('GET', 'patient-cards/cards/'.$card['id'])->assertOk()->assertJsonPath('data.status', 'draft');
        // An unrelated shared identity must not be enumerable by a local card role.
        $foreign = DB::table('patients')->insertGetId(['identity_document_type' => 'none', 'created_by' => User::factory()->create()->id, 'search_name' => 'foreign synthetic', 'patient_code' => 'FOREIGN-'.Str::random(10), 'first_name' => 'هوية', 'family_name' => 'خارج النطاق', 'status' => 'active']);
        $this->api('GET', 'patient-cards/patients', ['search' => 'هوية خارج النطاق'])->assertOk()->assertExactJson(['data' => []]);
        $this->api('POST', 'patient-cards/registrations', ['request_id' => (string) Str::uuid(), 'person_mode' => 'existing', 'patient_id' => $foreign, 'opening_date' => '2020-01-02', 'visit_date' => '2020-01-03'])->assertForbidden();
        $this->api('GET', 'patient-cards/cards/'.$card['id'], ['facility_id' => $this->other])->assertForbidden();
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $card['id'])->count());
    }

    public function test_dates_permissions_and_atomic_replay_remain_enforced(): void
    {
        $body = $this->input();
        $this->api('POST', 'patient-cards/registrations', array_replace($body, ['visit_date' => null]))->assertUnprocessable();
        $this->api('POST', 'patient-cards/registrations', array_replace($body, ['visit_date' => now()->addYears(2)->toDateString()]))->assertUnprocessable();
        $this->assertSame(0, DB::table('patients')->where('created_by', $this->clerk->id)->count());
        $card = $this->api('POST', 'patient-cards/registrations', $body)->assertCreated()->json('data');
        $this->api('GET', 'patient-cards/cards/'.$card['id'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'patient_dossier', 'entity_id' => $card['id'], 'actor_id' => $this->clerk->id]);
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', DB::table('permissions')->where('code', 'patient_cards.register')->value('id'))->delete();
        $this->api('GET', 'patient-cards/options')->assertOk()->assertJsonPath('data.can_register', false);
        $this->api('POST', 'patient-cards/registrations', $body)->assertForbidden();
        $this->api('GET', 'patient-cards/cards/'.$card['id'])->assertOk();
        $this->assertSame(1, DB::table('patients')->where('created_by', $this->clerk->id)->count());
    }
}
