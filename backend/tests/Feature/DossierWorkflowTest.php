<?php

namespace Tests\Feature;

use Database\Seeders\DossierDiagnosisReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierWorkflowFixture;
use Tests\TestCase;

class DossierWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierWorkflowFixture::make();
        $this->token = $this->f['user']->createToken('wizard-test', ['api'])->plainTextToken;
    }

    private function callApi(string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/dossiers'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function personal(array $overrides = []): array
    {
        return $overrides + ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'code' => ' HIST-2000 ', 'opening_date' => '2000-02-03', 'first_name' => 'أحمد', 'family_name' => 'محمد %_', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'];
    }

    private function create(): array
    {
        return $this->callApi('POST', '', $this->personal())->assertCreated()->json('data');
    }

    private function visit(array $overrides = []): array
    {
        return $overrides + ['request_id' => (string) Str::uuid(), 'visit_date' => '2001-03-02', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => false, 'diagnoses' => []];
    }

    private function diagnosis(array $overrides = []): array
    {
        return $overrides + ['diagnosis_id' => $this->f['diagnosis'], 'diagnosed_on' => null, 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0]];
    }

    public function test_atomic_personal_save_idempotency_discoverability_and_no_orphans(): void
    {
        $before = DB::table('patients')->count();
        $input = $this->personal();
        $d = $this->callApi('POST', '', $input)->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.code', 'HIST-2000')->assertJsonPath('data.progress.0.state', 'saved')->json('data');
        $this->callApi('POST', '', $input)->assertCreated()->assertJsonPath('data.id', $d['id']);
        $this->callApi('POST', '', array_replace($input, ['code' => 'different']))->assertConflict();
        $this->callApi('POST', '', $this->personal())->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertSame($before + 1, DB::table('patients')->count());
        $this->assertSame(0, DB::table('visits')->where('dossier_id', $d['id'])->count());
        $this->callApi('GET', '', ['search' => 'HIST-2000'])->assertOk()->assertJsonPath('data.0.id', $d['id']);
        $this->callApi('GET', '/'.$d['id'].'/progress')->assertOk()->assertJsonPath('data.patient.first_name', 'أحمد');
        $this->callApi('POST', '', $this->personal(['code' => 'SECOND']))->assertCreated();
        $this->assertSame($before + 2, DB::table('patients')->count(), 'Same name must not merge patients');
    }

    public function test_existing_patient_search_literal_wildcards_returns_existing_and_never_replaces_identity(): void
    {
        $d = $this->create();
        foreach (['أحمد محمد', '  أحمد   محمد  ', '%_', 'أحمد', 'محمد', $d['patient']['patient_code']] as $search) {
            $this->callApi('GET', '/options/patients', ['search' => $search])->assertOk()->assertJsonPath('data.0.id', $d['patient']['id']);
        }
        $this->callApi('GET', '/options/patients', ['search' => '%_'])->assertJsonCount(1, 'data');
        $this->callApi('GET', '/options/patients', ['search' => ''])->assertJsonCount(0, 'data');
        $this->callApi('POST', '', ['request_id' => (string) Str::uuid(), 'person_mode' => 'existing', 'patient_id' => $d['patient']['id'], 'code' => 'NOT-USED', 'opening_date' => '1990-01-01'])->assertCreated()->assertJsonPath('data.id', $d['id']);
        $this->callApi('PUT', '/'.$d['id'].'/personal', ['patient_id' => $this->f['patients'][2]])->assertUnprocessable()->assertJsonValidationErrors('patient_id');
    }

    public function test_medical_history_progress_audit_conflicts_and_prior_save_preserve_visit(): void
    {
        $d = $this->create();
        $id = $d['id'];
        $medical = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'is_oncology' => true, 'clinical_history' => 'قصة محفوظة', 'history' => ['medical', 'family'], 'treatment' => ['chemotherapy'], 'previous_examinations' => 'فحص سابق', 'medication_source' => 'other_organization', 'other_organization' => 'جهة'];
        $this->callApi('PUT', "/$id/medical", $medical)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->callApi('PUT', "/$id/medical", array_replace($medical, ['request_id' => (string) Str::uuid()]))->assertConflict();
        $this->callApi('PUT', "/$id/medical", ['request_id' => (string) Str::uuid(), 'lock_version' => 2, 'is_oncology' => false])->assertUnprocessable()->assertJsonValidationErrors('confirm_hide_oncology');
        $this->callApi('PUT', "/$id/medical", ['request_id' => (string) Str::uuid(), 'lock_version' => 2, 'is_oncology' => false, 'confirm_hide_oncology' => true, 'clinical_history' => 'قصة محفوظة'])->assertOk()->assertJsonPath('data.medical.previous_examinations', 'فحص سابق');
        $this->assertSame(3, DB::table('dossier_oncology_selections')->where('dossier_id', $id)->count());
        $this->callApi('GET', "/$id")->assertOk()->assertJsonPath('data.oncology', null);
        $this->callApi('POST', "/$id/visits", $this->visit())->assertCreated();
        $p = $d['patient'];
        unset($p['id'], $p['patient_code'], $p['lock_version']);
        $this->callApi('PUT', "/$id/personal", $p + ['request_id' => (string) Str::uuid(), 'lock_version' => 3, 'patient_lock_version' => 1, 'code' => 'HIST-2000', 'opening_date' => '1999-01-01'])->assertOk()->assertJsonPath('data.visit.status', 'draft')->assertJsonPath('data.progress.2.state', 'in_progress');
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $this->f['user']->id, 'entity_type' => 'dossier_medical', 'entity_id' => $id]);
    }

    public function test_existing_patient_creates_only_one_dossier_per_facility_and_preserves_blood_links(): void
    {
        $patient = $this->f['patients'][1];
        $facility = $this->f['other'];
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'user_id' => $this->f['user']->id, 'role_id' => $this->f['dossier_role']]);
        $beforePatients = DB::table('patients')->orderBy('id')->get()->toJson();
        $beforeBlood = DB::table('blood_transfusions')->orderBy('id')->get()->toJson();
        $this->assertTrue(DB::table('blood_transfusions')->where('patient_id', $patient)->exists());
        $input = ['facility_id' => $facility, 'request_id' => (string) Str::uuid(), 'person_mode' => 'existing', 'patient_id' => $patient, 'code' => 'EXISTING-1990', 'opening_date' => '1990-01-02'];
        $d = $this->callApi('POST', '', $input)->assertCreated()->assertJsonPath('data.patient.id', $patient)->json('data');
        $this->callApi('POST', '', array_replace($input, ['request_id' => (string) Str::uuid()]))->assertCreated()->assertJsonPath('data.id', $d['id']);
        $this->assertSame(1, DB::table('patient_dossiers')->where('facility_id', $facility)->where('patient_id', $patient)->count());
        $this->assertSame(2, DB::table('patient_dossiers')->where('patient_id', $patient)->count());
        $this->assertSame($beforePatients, DB::table('patients')->orderBy('id')->get()->toJson());
        $this->assertSame($beforeBlood, DB::table('blood_transfusions')->orderBy('id')->get()->toJson());
    }

    public function test_medication_organization_is_required_only_for_the_other_source(): void
    {
        $d = $this->create();
        $medical = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'is_oncology' => true, 'medication_source' => 'other_organization'];
        $this->callApi('PUT', "/{$d['id']}/medical", $medical)->assertUnprocessable()->assertJsonValidationErrors('other_organization');
        foreach (['ministry_of_health', 'al_rowad', 'personal_expense', 'none', null] as $source) {
            $this->callApi('PUT', "/{$d['id']}/medical", array_replace($medical, ['medication_source' => $source, 'other_organization' => 'جهة لا تخص هذا المصدر']))->assertUnprocessable()->assertJsonValidationErrors('other_organization');
        }
        $this->assertDatabaseHas('patient_dossiers', ['id' => $d['id'], 'lock_version' => 1, 'medication_source' => null]);
        $this->callApi('PUT', "/{$d['id']}/medical", array_replace($medical, ['medication_source' => 'none', 'other_organization' => null]))->assertOk()->assertJsonPath('data.medical.other_organization', null);
    }

    public function test_draft_visit_has_no_period_no_diagnoses_and_validates_referral_future_and_duplicate_creation(): void
    {
        $d = $this->create();
        $id = $d['id'];
        $before = DB::table('reporting_periods')->orderBy('id')->get()->toJson();
        DB::table('reporting_periods')->update(['status' => 'locked']);
        DB::table('reporting_periods')->insert(['facility_id' => $this->f['facility'], 'starts_on' => '2000-01-01', 'ends_on' => '2099-01-01']);
        $periods = DB::table('reporting_periods')->orderBy('id')->get()->toJson();
        $this->callApi('POST', "/$id/visits", $this->visit(['is_referred' => true]))->assertUnprocessable();
        $this->callApi('POST', "/$id/visits", $this->visit(['visit_date' => '2099-01-01']))->assertUnprocessable();
        $input = $this->visit(['is_referred' => true, 'referral_date' => '2000-01-01', 'referral_reason' => 'سبب', 'referring_hospital' => 'مشفى اختبار']);
        $v = $this->callApi('POST', "/$id/visits", $input)->assertCreated()->assertJsonPath('data.visit.status', 'draft')->assertJsonPath('data.progress.2.state', 'in_progress')->json('data.visit');
        $this->assertDatabaseHas('visits', ['id' => $v['id'], 'reporting_period_id' => null]);
        $this->callApi('POST', "/$id/visits", $input)->assertCreated()->assertJsonPath('data.visit.id', $v['id']);
        $this->callApi('POST', "/$id/visits", $this->visit())->assertConflict();
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 1, 'visit_date' => '1991-01-01']))->assertOk()->assertJsonPath('data.visit.referral_date', null);
        $this->assertSame($periods, DB::table('reporting_periods')->orderBy('id')->get()->toJson());
        $this->callApi('GET', "/$id")->assertJsonPath('data.visit_count', 1);
        $this->assertNotSame($before, $periods); // Only explicit fixture setup changed periods.
    }

    public function test_multiple_diagnoses_historical_contexts_unknown_dates_omission_voiding_and_duplicate_prevention(): void
    {
        $d = $this->create();
        $id = $d['id'];
        $rows = [$this->diagnosis(), $this->diagnosis(['clinic_id' => $this->f['clinics'][1], 'diagnosing_staff_id' => $this->f['workflow_doctors'][1]])];
        $v = $this->callApi('POST', "/$id/visits", $this->visit(['diagnoses' => $rows]))->assertCreated()->assertJsonPath('data.progress.1.state', 'not_started')->assertJsonPath('data.progress.2.state', 'saved')->json('data.visit');
        $this->assertNull($v['diagnoses'][0]['diagnosed_on']);
        $this->assertDatabaseHas('visit_diagnoses', ['id' => $v['diagnoses'][0]['id'], 'is_primary' => 0, 'reporting_period_id' => null]);
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 1, 'diagnoses' => [$this->diagnosis()]]))->assertUnprocessable()->assertJsonValidationErrors('diagnoses');
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 1, 'diagnoses' => [$this->diagnosis(['clinic_id' => $this->f['clinics'][1]])]]))->assertUnprocessable();
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 1]))->assertOk()->assertJsonCount(2, 'data.visit.diagnoses');
        DB::table('staff')->where('id', $rows[0]['diagnosing_staff_id'])->update(['is_active' => false]);
        $saved = $rows[0] + ['id' => $v['diagnoses'][0]['id'], 'lock_version' => 1];
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 2, 'diagnoses' => [$saved]]))->assertOk();
        $saved['lock_version'] = 2;
        $saved['remove'] = true;
        $saved['void_reason'] = 'إدخال مكرر بعد المراجعة';
        $this->callApi('PUT', "/$id/visits/{$v['id']}", $this->visit(['lock_version' => 3, 'diagnoses' => [$saved]]))->assertOk()->assertJsonCount(1, 'data.visit.diagnoses');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'visit_diagnosis', 'entity_id' => $saved['id'], 'event' => 'voided']);
    }

    public function test_scopes_permissions_directory_creation_and_seed_idempotency(): void
    {
        $this->callApi('GET', '/options', ['facility_id' => $this->f['other']])->assertForbidden();
        $input = ['request_id' => (string) Str::uuid(), 'code' => 'NEW-DX', 'name_ar' => 'تشخيص   جديد'];
        $this->callApi('POST', '/diagnoses', $input)->assertCreated()->assertJsonPath('data.name_ar', 'تشخيص جديد');
        $this->callApi('POST', '/diagnoses', $input)->assertCreated();
        $this->callApi('POST', '/diagnoses', array_replace($input, ['request_id' => (string) Str::uuid(), 'name_ar' => 'اسم آخر للكود المكرر']))->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->callApi('POST', '/diagnoses', array_replace($input, ['request_id' => (string) Str::uuid(), 'code' => 'SECOND']))->assertUnprocessable()->assertJsonValidationErrors('name_ar');
        $count = DB::table('diagnoses')->count();
        app(DossierDiagnosisReferenceSeeder::class)->run();
        $this->assertSame($count, DB::table('diagnoses')->count());
        DB::table('global_user_roles')->where('user_id', $this->f['user']->id)->delete();
        $this->callApi('POST', '/diagnoses', $input)->assertForbidden();
        $this->callApi('GET', '/options/patients', ['search' => 'أحمد'])->assertForbidden();
        $this->callApi('POST', '', $this->personal())->assertForbidden();
        $this->callApi('GET', '/options')->assertOk()->assertJsonPath('data.capabilities.patients_search', false);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/dossiers/options?facility_id='.$this->f['facility'])->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_missing_periods_and_closed_historical_links_never_block_scoped_visits(): void
    {
        // A separate authorized facility with no periods at all.
        $f = $this->f['other'];
        DB::table('facility_user_roles')->insert(['facility_id' => $f, 'user_id' => $this->f['user']->id, 'role_id' => $this->f['dossier_role']]);
        // Use a third facility: CatalogFixture intentionally has references in both its facilities.
        $f = DB::table('facilities')->insertGetId(['code' => 'NO-PERIOD-'.$this->f['tag'], 'name_ar' => 'مشفى اختبار بلا فترات']);
        DB::table('facility_user_roles')->insert(['facility_id' => $f, 'user_id' => $this->f['user']->id, 'role_id' => $this->f['dossier_role']]);
        $d = $this->callApi('POST', '', $this->personal(['facility_id' => $f]))->assertCreated()->json('data');
        $this->callApi('POST', '/'.$d['id'].'/visits', $this->visit(['facility_id' => $f]))->assertCreated();
        $this->assertSame(0, DB::table('reporting_periods')->where('facility_id', $f)->count());
        $id = $this->f['dossiers'][1];
        $v = DB::table('visits')->where('facility_id', $this->f['facility'])->where('patient_id', $this->f['patients'][2])->first();
        DB::table('visits')->where('id', $v->id)->update(['dossier_id' => $id, 'status' => 'draft']);
        DB::table('reporting_periods')->where('id', $v->reporting_period_id)->update(['status' => 'locked']);
        $this->callApi('PUT', "/$id/visits/{$v->id}", $this->visit(['lock_version' => 1, 'visit_date' => '1991-01-01']))->assertOk();
        $this->assertDatabaseHas('visits', ['id' => $v->id, 'reporting_period_id' => $v->reporting_period_id, 'visit_date' => '1991-01-01']);
        $this->assertDatabaseHas('reporting_periods', ['id' => $v->reporting_period_id, 'status' => 'locked']);
    }
}
