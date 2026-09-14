<?php

namespace Tests\Feature;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\Directory\DirectoryReferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsOpenApi;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankTest extends TestCase
{
    use AssertsOpenApi;
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = BloodBankFixture::make();
        $this->token = $this->f['user']->createToken('bb-test', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path = '', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/blood-bank'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function profile(string $kind = 'donor'): array
    {
        return BloodBankFixture::profile($this->f, $kind);
    }

    public function test_profiles_preserve_unknowns_have_distinct_codes_and_create_no_clinical_events(): void
    {
        $before = [];
        foreach (['patients', 'visits', 'blood_donations', 'blood_transfusions', 'blood_donation_screenings', 'blood_recipient_procedures'] as $table) {
            $before[$table] = DB::table($table)->count();
        }
        foreach (['donor' => 'BD-', 'recipient' => 'BR-'] as $kind => $prefix) {
            $input = $this->profile($kind);
            $row = $this->api('POST', '', $input)->assertCreated()->assertJsonPath('data.blood_group', null)->assertJsonPath('data.rh', null)->assertJsonPath('data.screenings.0.result', null)->json('data');
            $this->assertStringStartsWith($prefix, $row['code']);
            $this->api('POST', '', $input)->assertCreated()->assertJsonPath('data.id', $row['id']);
            $this->api('POST', '', ['family_name' => 'تغيير'] + $input)->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_REQUEST_CONFLICT');
            $this->api('POST', '', ['request_id' => (string) Str::uuid()] + $input)->assertCreated(); // Same name is not identity.
            $this->api('GET', '', ['kind' => $kind, 'search' => '  أحمد   محمد  ', 'per_page' => 10])->assertOk()->assertJsonPath('meta.total', 2);
            $this->api('GET', '', ['search' => $row['code']])->assertJsonPath('meta.total', 1);
            $this->api('GET', '', ['search' => '%'])->assertJsonPath('meta.total', 0);
            $this->api('GET', '', ['search' => '_'])->assertJsonPath('meta.total', 0);
        }
        foreach ($before as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        $this->assertTrue(app(DirectoryReferences::class)->summary(true, $this->f['staff'], $this->f['facility'])['has_other_references']);
    }

    public function test_patient_linking_reads_current_shared_patient_without_copy_or_facility_only_grant(): void
    {
        $input = $this->profile('recipient');
        foreach (SaveBloodProfile::PERSON as $field) {
            unset($input[$field]);
        }
        $input['person_mode'] = 'patient';
        $input['patient_id'] = $this->f['patients'][7]; // Has events only in another facility.
        $this->api('GET', '/patients', ['search' => $this->f['tag'].'-P7'])->assertOk()->assertJsonPath('meta.total', 1);
        $row = $this->api('POST', '', $input)->assertCreated()->json('data');
        $this->assertDatabaseHas('blood_recipients', ['id' => $row['id'], 'patient_id' => $input['patient_id'], 'first_name' => null, 'address_line' => null]);
        DB::table('patients')->where('id', $input['patient_id'])->update(['first_name' => 'اسم حديث', 'address_line' => 'عنوان جديد']);
        $this->api('GET', '/recipient/'.$row['id'])->assertJsonPath('data.person.first_name', 'اسم حديث')->assertJsonPath('data.person.address_line', 'عنوان جديد');
        $this->api('POST', '', ['request_id' => (string) Str::uuid()] + $input)->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_DUPLICATE');
        $this->api('POST', '', ['first_name' => 'نسخة'] + $input)->assertUnprocessable()->assertJsonValidationErrors('first_name');
        DB::table('global_user_roles')->where('user_id', $this->f['user']->id)->delete();
        $this->api('GET', '/patients', ['search' => 'مستفيد'])->assertForbidden()->assertJsonMissingPath('data');
        $this->api('GET', '/patients/'.$input['patient_id'])->assertForbidden();
        $this->api('POST', '', ['patient_id' => $this->f['patients'][8], 'request_id' => (string) Str::uuid()] + $input)->assertForbidden();
        $this->api('GET', '/recipient/'.$row['id'])->assertOk();
    }

    public function test_clinic_doctor_and_test_relationships_are_validated_atomically(): void
    {
        $input = $this->profile();
        $foreign = DB::table('clinics')->insertGetId(['facility_id' => $this->f['other'], 'code' => 'FOREIGN', 'name_ar' => 'محجوبة']);
        $this->api('POST', '', ['clinic_id' => $foreign] + $input)->assertUnprocessable()->assertJsonValidationErrors('responsible_staff_id');
        $this->assertDatabaseCount('blood_donors', 0);
        $input['screenings'][1] = ['analyte' => 'HCV', 'screening_test_id' => $this->f['test'], 'status' => 'complete', 'result' => null];
        $this->api('POST', '', $input)->assertUnprocessable()->assertJsonValidationErrors('screenings');
        $this->assertDatabaseCount('blood_donors', 0);
        $input['screenings'][1]['result'] = 'indeterminate';
        $row = $this->api('POST', '', $input)->assertCreated()->assertJsonPath('data.screenings.1.result', 'indeterminate')->json('data');
        $this->api('GET', '/doctors', ['clinic_id' => $foreign])->assertOk()->assertJsonPath('data', []);
        $this->api('GET', '/doctors', ['clinic_id' => $this->f['clinic']])->assertJsonPath('data.0.id', $this->f['staff']);
        unset($input['kind']);
        $input['lock_version'] = 1;
        $input['request_id'] = (string) Str::uuid();
        $input['screenings'][2]['screening_test_id'] = $this->f['test'];
        $this->api('PUT', '/donor/'.$row['id'], $input)->assertUnprocessable();
        $this->api('GET', '/donor/'.$row['id'])->assertJsonPath('data.lock_version', 1);
        $input['screenings'][2]['screening_test_id'] = null;
        $this->api('PUT', '/donor/'.$row['id'], $input)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->api('PUT', '/donor/'.$row['id'], $input)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->api('PUT', '/donor/'.$row['id'], ['request_id' => (string) Str::uuid()] + $input)->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_VERSION_CONFLICT');
    }

    public function test_dated_codes_corrections_aliases_and_retries_preserve_one_donation(): void
    {
        $donor = $this->api('POST', '', $this->profile())->json('data.id');
        $path = "/donor/$donor/donations";
        $input = ['request_id' => (string) Str::uuid(), 'donated_on' => $this->f['today'], 'blood_group' => 'AB', 'rh' => 'negative', 'units' => '1.2500'];
        $row = $this->api('POST', $path, $input)->assertCreated()->assertJsonPath('data.status', 'pending')->json('data');
        $this->assertStringStartsWith('DON-'.str_replace('-', '', $input['donated_on']).'-', $row['donation_code']);
        $this->api('POST', $path, $input)->assertCreated()->assertJsonPath('data.id', $row['id']);
        $other = $this->api('POST', $path, ['request_id' => (string) Str::uuid()] + $input)->assertCreated()->json('data');
        $this->assertNotSame($row['donation_code'], $other['donation_code']);
        $input['request_id'] = (string) Str::uuid();
        $input['lock_version'] = 1;
        $input['donated_on'] = now('Asia/Damascus')->subDay()->toDateString();
        $changed = $this->api('PUT', "$path/{$row['id']}", $input)->assertOk()->assertJsonPath('data.lock_version', 2)->json('data');
        $this->assertNotSame($row['donation_code'], $changed['donation_code']);
        $this->api('GET', $path, ['search' => $row['donation_code']])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.donation_code', $changed['donation_code']);
        $this->api('PUT', "$path/{$row['id']}", $input)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->api('PUT', "$path/{$row['id']}", ['request_id' => (string) Str::uuid()] + $input)->assertConflict();
        $this->assertDatabaseCount('blood_donations', 2);
        $this->assertDatabaseCount('blood_donation_codes', 3);
        $input['request_id'] = (string) Str::uuid();
        $input['lock_version'] = 2;
        $input['donated_on'] = now('Asia/Damascus')->addDay()->toDateString();
        $this->api('PUT', "$path/{$row['id']}", $input)->assertUnprocessable()->assertJsonValidationErrors('donated_on');
        $this->api('GET', "$path/{$row['id']}")->assertJsonPath('data.donation_code', $changed['donation_code']);
        DB::table('reporting_periods')->where('id', $changed['reporting_period_id'])->update(['status' => 'locked']);
        $input['donated_on'] = $this->f['today'];
        $this->api('PUT', "$path/{$row['id']}", $input)->assertUnprocessable();
    }

    public function test_every_route_enforces_authentication_facility_and_capabilities(): void
    {
        $row = $this->api('POST', '', $this->profile())->json('data');
        $viewer = $this->f['viewer']->createToken('bb-viewer', ['api'])->plainTextToken;
        foreach (['', '/options', '/cities', '/clinics', '/doctors', '/patients', '/patients/'.$this->f['patients'][1], '/donor/'.$row['id'], '/donor/'.$row['id'].'/donations'] as $path) {
            $this->api('GET', $path, ['facility_id' => $this->f['other']])->assertForbidden()->assertJsonMissingPath('data')->assertHeader('Cache-Control', 'no-store, private');
            $this->api('GET', $path, [], 'invalid')->assertUnauthorized();
        }
        $this->api('POST', '', $this->profile(), $viewer)->assertForbidden();
        $this->api('GET', '/options', [], $viewer)->assertJsonPath('data.capabilities.create', false)->assertJsonPath('data.capabilities.patients_search', false);
        DB::table('facilities')->where('id', $this->f['facility'])->update(['is_active' => false]);
        $this->api('GET')->assertForbidden()->assertJsonMissingPath('data');
    }

    public function test_donation_permissions_period_gaps_and_clinical_counts_are_independent(): void
    {
        $input = $this->profile();
        $input['screenings'][1]['status'] = 'complete';
        $input['screenings'][1]['result'] = 'negative';
        $donor = $this->api('POST', '', $input)->assertCreated()->json('data.id');
        $counts = [];
        foreach (['visits', 'blood_transfusions', 'visit_services', 'visit_procedures', 'blood_recipient_procedures', 'blood_donation_screenings'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $path = "/donor/$donor/donations";
        $data = ['request_id' => (string) Str::uuid(), 'donated_on' => $this->f['today'], 'blood_group' => 'A', 'rh' => 'positive', 'units' => '1'];
        $permissions = DB::table('permissions')->whereIn('code', ['blood_bank.donations.create', 'blood_bank.donations.update'])->pluck('id');
        $role = DB::table('facility_user_roles')->where('user_id', $this->f['user']->id)->value('role_id');
        DB::table('role_permissions')->where('role_id', $role)->whereIn('permission_id', $permissions)->delete();
        $this->api('POST', $path, $data)->assertForbidden();
        $this->api('GET', '/donor/'.$donor)->assertOk();
        foreach ($permissions as $p) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $p]);
        }
        foreach (['0', '-1', '1.12345'] as $units) {
            $this->api('POST', $path, ['units' => $units] + $data)->assertUnprocessable();
        }
        $this->api('POST', $path, ['donated_on' => '2000-01-01'] + $data)->assertUnprocessable();
        $row = $this->api('POST', $path, $data)->assertCreated()->json('data');
        $this->assertDatabaseMissing('blood_donation_screenings', ['blood_donation_id' => $row['id']]);
        $this->api('PUT', "$path/{$row['id']}", ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'donated_on' => now('Asia/Damascus')->subDay()->toDateString()] + $data)->assertOk();
        foreach ($counts as $table => $count) {
            $this->assertDatabaseCount($table, $count);
        }
        DB::table('blood_donations')->where('id', $row['id'])->update(['status' => 'accepted']);
        $this->api('PUT', "$path/{$row['id']}", ['request_id' => (string) Str::uuid(), 'lock_version' => 2] + $data)->assertConflict();
    }

    public function test_openapi_describes_all_real_shapes_and_separate_identity_paths(): void
    {
        $row = $this->api('POST', '', $this->profile())->assertCreated()->json('data');
        $donation = $this->api('POST', "/donor/{$row['id']}/donations", ['request_id' => (string) Str::uuid(), 'donated_on' => $this->f['today'], 'blood_group' => 'B', 'rh' => 'negative', 'units' => '1'])->assertCreated()->json('data');
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['' => '', '/options' => '/options', '/cities' => '/cities', '/clinics' => '/clinics', '/doctors' => '/doctors', '/patients' => '/patients', '/patients/'.$this->f['patients'][1] => '/patients/{patient}', '/donor/'.$row['id'] => '/{kind}/{item}', "/donor/{$row['id']}/donations" => '/donor/{donor}/donations', "/donor/{$row['id']}/donations/{$donation['id']}" => '/donor/{donor}/donations/{donation}'] as $actual => $template) {
            $operation = $doc['paths']['/api/blood-bank'.$template]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $body = $this->api('GET', $actual, ['clinic_id' => $this->f['clinic'], 'search' => str_contains($actual, 'patients') ? 'مستفيد' : ''])->assertOk()->json();
            $this->assertMatchesSchema($doc, $operation['responses']['200']['content']['application/json']['schema'], $body);
        }
        $create = $doc['paths']['/api/blood-bank']['post']['requestBody']['content']['application/json']['schema'];
        $this->assertCount(2, $create['anyOf']);
        $this->assertMatchesSchema($doc, $create, $this->profile());
        $linked = ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'kind' => 'recipient', 'person_mode' => 'patient', 'patient_id' => $this->f['patients'][1], 'clinic_id' => $this->f['clinic'], 'responsible_staff_id' => $this->f['staff'], 'screenings' => $this->profile()['screenings']];
        $this->assertMatchesSchema($doc, $create, $linked);
    }
}
