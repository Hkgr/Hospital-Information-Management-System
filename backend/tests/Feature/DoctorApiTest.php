<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Doctors\DoctorQueries;
use Database\Seeders\ClinicalStaffTypesSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class DoctorApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private User $user;

    private string $token;

    private int $facility;

    private int $other;

    private int $role;

    private int $type;

    private int $specialty;

    protected function setUp(): void
    {
        parent::setUp();
        $typeCode = 'TEST-DOCTOR-'.Str::random(8);
        config(['clinics.doctor_staff_types' => [$typeCode]]);
        $this->user = User::factory()->create(['username' => 'testadmin', 'name' => 'مُصدر تقارير اختباري']);
        $this->token = $this->user->createToken('doctor-test', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'TEST-A', 'name_ar' => 'منشأة اختبار أ', 'timezone' => 'Asia/Damascus']);
        $this->other = DB::table('facilities')->insertGetId(['code' => 'TEST-B', 'name_ar' => 'منشأة اختبار ب', 'timezone' => 'Asia/Damascus']);
        // Never mutate or acquire the protected system role from a populated database.
        $this->role = DB::table('roles')->insertGetId(['code' => 'doctor-api-'.Str::random(16), 'name_ar' => 'دور اختباري']);
        $this->seed(DoctorPermissionsSeeder::class);
        foreach (DB::table('permissions')->whereIn('code', array_keys(DoctorPermissionsSeeder::PERMISSIONS))->pluck('id') as $permission) {
            DB::table('role_permissions')->insert(['role_id' => $this->role, 'permission_id' => $permission]);
        }
        foreach (['clinics.view', 'clinics.update'] as $code) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $code]);
            $permission = DB::table('permissions')->where('code', $code)->value('id');
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->role, 'permission_id' => $permission]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $this->role, 'facility_id' => $this->facility]);
        DB::table('global_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $this->role]);
        $this->type = DB::table('staff_types')->insertGetId(['code' => $typeCode, 'name_ar' => 'طبيب اختباري']);
        DB::table('specialties')->insertOrIgnore(['code' => 'INTERNAL', 'name_ar' => 'الطب الداخلي']);
        $this->specialty = DB::table('specialties')->where('code', 'INTERNAL')->value('id');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        gc_collect_cycles();
    }

    private function callApi(string $method, string $path = '', array $data = [], ?string $token = null)
    {
        if ($method === 'POST' && $path === '') {
            $data += ['request_id' => (string) Str::uuid()];
        }
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/doctors'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function input(array $extra = []): array
    {
        unset($extra['code']);

        return $extra + ['name' => 'طبيب اختباري', 'description' => 'توصيف مهني', 'staff_type_id' => $this->type, 'specialty_ids' => [$this->specialty], 'is_active' => true, 'phone' => '0096300000', 'license_no' => '001'];
    }

    private function create(array $extra = []): array
    {
        return $this->callApi('POST', '', $this->input($extra))->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function clinic(string $code = 'CL-01', ?int $facility = null, bool $active = true): int
    {
        return DB::table('clinics')->insertGetId(['facility_id' => $facility ?? $this->facility, 'code' => $code, 'name_ar' => 'عيادة '.$code, 'is_active' => $active]);
    }

    public function test_practice_group_follows_the_explicit_type_without_replacing_identity(): void
    {
        app(ClinicalStaffTypesSeeder::class)->run();
        config(['clinics.doctor_staff_types' => ['RESIDENT', 'SPECIALIST']]);
        $resident = DB::table('staff_types')->where('code', 'RESIDENT')->value('id');
        $specialist = DB::table('staff_types')->where('code', 'SPECIALIST')->value('id');
        $doctor = $this->create(['staff_type_id' => $resident]);
        $this->assertSame('resident', $doctor['practice_group']);
        $this->callApi('PUT', '/'.$doctor['id'], $this->input(['staff_type_id' => $specialist, 'lock_version' => $doctor['lock_version']]))->assertOk()->assertJsonPath('data.practice_group', 'specialist')->assertJsonPath('data.code', $doctor['code'])->assertJsonPath('data.license_no', $doctor['license_no']);
    }

    public function test_crud_global_unique_code_specialties_and_unrelated_fields_survive(): void
    {
        $usersBefore = DB::table('users')->count();
        $doctor = $this->create();
        $this->assertSame(0, $doctor['clinic_count']);
        $this->assertSame($usersBefore, DB::table('users')->count());
        $this->assertDatabaseHas('staff_specialties', ['staff_id' => $doctor['id'], 'specialty_id' => $this->specialty]);
        $again = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        $this->assertNotSame($doctor['code'], $again['code']);
        $this->callApi('POST', '', $this->input() + ['code' => 'DUP'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $funding = DB::table('funding_sources')->insertGetId(['code' => 'FUND', 'name_ar' => 'تمويل اختباري']);
        DB::table('staff')->where('id', $doctor['id'])->update(['funding_source_id' => $funding]);
        $updated = $this->callApi('PUT', '/'.$doctor['id'], $this->input(['lock_version' => 1, 'name' => 'اسم معدل', 'funding_source_id' => null]))->assertOk()->json('data');
        $this->assertSame(2, $updated['lock_version']);
        $this->assertDatabaseHas('staff', ['id' => $doctor['id'], 'funding_source_id' => $funding]);
        foreach (['funding_source_id', 'search_name', 'password', 'remember_token'] as $field) {
            $this->assertArrayNotHasKey($field, $updated);
        }
        $this->callApi('PUT', '/'.$doctor['id'], $this->input(['lock_version' => 1]))->assertConflict()->assertJsonPath('error.code', 'DOCTOR_VERSION_CONFLICT');
        $this->callApi('DELETE', '/'.$doctor['id'], ['lock_version' => 2])->assertNoContent();
        $this->assertDatabaseMissing('staff', ['id' => $doctor['id']]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'doctor', 'entity_id' => $doctor['id'], 'event' => 'deleted']);
    }

    public function test_conflict_lookup_uses_stable_ids_deduplicates_and_hides_foreign_clinics(): void
    {
        $clinic = $this->clinic('OLD');
        $foreign = $this->clinic('FOREIGN-SECRET', $this->other);
        $inactive = $this->clinic('INACTIVE', active: false);
        $this->clinic('UNTOUCHED');
        $doctor = $this->create(['clinic_add_ids' => [$clinic]]);
        DB::table('clinics')->where('id', $clinic)->update(['code' => 'CURRENT', 'name_ar' => 'الاسم الحالي']);
        DB::table('clinic_staff')->insert(['clinic_id' => $foreign, 'staff_id' => $doctor['id'], 'starts_on' => '2020-01-01']);
        $missing = $foreign + 100000;
        $result = $this->callApi('GET', '/options/clinics', ['doctor_id' => $doctor['id'], 'ids' => [$clinic, $foreign, $inactive, $missing, $clinic]])
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'CURRENT')->assertJsonPath('data.0.name_ar', 'الاسم الحالي')
            ->assertJsonPath('data.0.is_linked', true)->assertDontSee('FOREIGN-SECRET')->assertDontSee('UNTOUCHED');
        $this->assertSame([['id' => $foreign, 'reason' => 'UNAVAILABLE'], ['id' => $inactive, 'reason' => 'INACTIVE'], ['id' => $missing, 'reason' => 'UNAVAILABLE']], $result->json('unavailable'));
        foreach ([[], range(1, 101), ['bad'], [-1], [[1]]] as $ids) {
            $this->callApi('GET', '/options/clinics', ['ids' => $ids])->assertUnprocessable();
        }
        $this->callApi('GET', '/options/clinics', ['facility_id' => $this->other, 'ids' => [$foreign]])->assertForbidden();
    }

    public function test_batched_doctor_choices_keep_current_codes_and_only_selected_clinic_linkage(): void
    {
        $clinic = $this->clinic();
        $doctor = $this->create(['clinic_add_ids' => [$clinic]]);
        $this->create(['code' => 'UNTOUCHED']);
        DB::table('staff')->where('id', $doctor['id'])->update(['staff_code' => 'RENAMED', 'full_name' => 'الاسم الحالي']);
        $headers = ['Authorization' => 'Bearer '.$this->token];
        $this->json('GET', '/api/clinics/options/doctors', ['facility_id' => $this->facility, 'clinic_id' => $clinic, 'ids' => [$doctor['id'], $doctor['id']]], $headers)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'RENAMED')->assertJsonPath('data.0.is_linked', true);
        $foreign = $this->clinic('FOREIGN', $this->other);
        $this->json('GET', '/api/clinics/options/doctors', ['facility_id' => $this->facility, 'clinic_id' => $foreign, 'ids' => [$doctor['id']]], $headers)->assertNotFound();
    }

    public function test_full_lookup_batch_is_complete_and_query_count_does_not_grow_per_id(): void
    {
        $doctor = $this->create();
        $ids = [];
        foreach (range(1, 100) as $i) {
            $ids[] = $this->clinic('BATCH-'.$i);
        }
        $counts = [];
        foreach ([[$ids[0]], $ids] as $batch) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $result = $this->callApi('GET', '/options/clinics', ['doctor_id' => $doctor['id'], 'ids' => $batch])->assertOk()->assertJsonCount(count($batch), 'data')->assertJsonPath('meta.last_page', 1);
            $counts[] = collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower(ltrim($query['query'])), 'select '))->count();
            DB::disableQueryLog();
            $this->assertEqualsCanonicalizing($batch, array_column($result->json('data'), 'id'));
        }
        $this->assertSame($counts[0], $counts[1]);
    }

    public function test_facility_role_is_never_global_and_two_facility_doctor_is_isolated(): void
    {
        $doctor = $this->create();
        $first = $this->clinic();
        $other = $this->clinic('CL-B', $this->other);
        foreach ([$first, $other] as $clinic) {
            DB::table('clinic_staff')->insert(['staff_id' => $doctor['id'], 'clinic_id' => $clinic, 'starts_on' => '2020-01-01']);
        }
        DB::table('global_user_roles')->where('user_id', $this->user->id)->delete();
        $this->callApi('GET', '/options')->assertJsonPath('data.capabilities.update', false)->assertJsonPath('data.capabilities.link', true);
        $this->callApi('POST', '', $this->input(['code' => 'NEW']))->assertForbidden()->assertJsonPath('error.code', 'DOCTOR_DIRECTORY_ACCESS_DENIED');
        $this->callApi('PUT', '/'.$doctor['id'], $this->input(['lock_version' => 1]))->assertForbidden();
        $this->callApi('POST', '/'.$doctor['id'].'/deactivate', ['lock_version' => 1])->assertForbidden();
        $this->callApi('DELETE', '/'.$doctor['id'], ['lock_version' => 1])->assertForbidden();
        $this->callApi('GET', '/'.$doctor['id'])->assertJsonPath('data.clinic_count', 1)->assertDontSee('CL-B');
        $this->callApi('GET', '/'.$doctor['id'].'/clinics')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $first);
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 1, 'name' => 'forbidden'])->assertUnprocessable();
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 1, 'clinic_remove_ids' => [$other]])->assertUnprocessable();
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 1, 'clinic_remove_ids' => [$first]])->assertOk()->assertJsonPath('data.clinic_count', 0);
        $this->assertDatabaseHas('clinic_staff', ['staff_id' => $doctor['id'], 'clinic_id' => $other, 'ends_on' => null]);
        $this->assertDatabaseHas('staff', ['id' => $doctor['id'], 'is_active' => true]);
        foreach (['', '/options', '/options/clinics', '/'.$doctor['id'], '/'.$doctor['id'].'/clinics', '/export/pdf', '/'.$doctor['id'].'/report'] as $path) {
            $this->callApi('GET', $path, ['facility_id' => $this->other])->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        }
    }

    public function test_bidirectional_links_history_versions_hidden_and_future_periods(): void
    {
        $clinic = $this->clinic();
        $second = $this->clinic('CL-02');
        $doctor = $this->create(['clinic_add_ids' => [$clinic, $second]]);
        $this->assertSame(2, $doctor['clinic_count']);
        $this->assertDatabaseHas('clinics', ['id' => $clinic, 'lock_version' => 2]);
        $clinicInput = ['facility_id' => $this->facility, 'name_ar' => 'عيادة CL-01', 'is_active' => true, 'lock_version' => 1, 'doctor_remove_ids' => [$doctor['id']]];
        $this->json('PUT', '/api/clinics/'.$clinic, $clinicInput, ['Authorization' => 'Bearer '.$this->token])->assertConflict();
        $clinicInput['lock_version'] = 2;
        $this->json('PUT', '/api/clinics/'.$clinic, $clinicInput, ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonPath('data.doctor_count', 0);
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 1, 'clinic_add_ids' => [$clinic]])->assertConflict();
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 2, 'clinic_add_ids' => [$clinic]])->assertOk()->assertJsonPath('data.clinic_count', 2);
        $this->assertSame(2, DB::table('clinic_staff')->where('staff_id', $doctor['id'])->count()); // Same-day reopening, not a duplicate period.
        DB::table('clinics')->where('id', $second)->update(['is_active' => false]);
        $this->callApi('PUT', '/'.$doctor['id'], $this->input(['lock_version' => 3, 'name' => 'اسم فقط']))->assertOk()->assertJsonPath('data.clinic_count', 1);
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $second, 'ends_on' => null]);
        $future = $this->clinic('FUTURE');
        DB::table('clinic_staff')->insert(['staff_id' => $doctor['id'], 'clinic_id' => $future, 'starts_on' => now()->addMonth()->toDateString()]);
        $this->callApi('PUT', '/'.$doctor['id'].'/clinics', ['lock_version' => 4, 'clinic_add_ids' => [$future]])->assertConflict()->assertJsonPath('error.code', 'CLINIC_PERIOD_CONFLICT');
        $this->assertDatabaseHas('staff', ['id' => $doctor['id'], 'lock_version' => 4]);
        $this->callApi('DELETE', '/'.$doctor['id'], ['lock_version' => 4])->assertConflict()->assertJsonPath('error.code', 'DOCTOR_REFERENCED');
        $this->callApi('POST', '/'.$doctor['id'].'/deactivate', ['lock_version' => 4])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(3, DB::table('clinic_staff')->where('staff_id', $doctor['id'])->count());
    }

    public function test_types_specialties_validation_and_configuration_fail_closed(): void
    {
        $unlisted = DB::table('staff_types')->insertGetId(['code' => 'UNLISTED', 'name_ar' => 'طبيب']);
        $this->callApi('POST', '', $this->input(['staff_type_id' => $unlisted]))->assertUnprocessable()->assertJsonValidationErrors('staff_type_id');
        $this->callApi('POST', '', $this->input(['specialty_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('specialty_ids');
        $this->callApi('POST', '', $this->input(['name' => str_repeat('x', 201), 'phone' => str_repeat('0', 31), 'license_no' => str_repeat('x', 61)]))->assertUnprocessable()->assertJsonValidationErrors(['name', 'phone', 'license_no']);
        $doctor = $this->create();
        DB::table('specialties')->where('id', $this->specialty)->update(['is_active' => false]);
        $this->callApi('PUT', '/'.$doctor['id'], $this->input(['lock_version' => 1]))->assertOk(); // Preserve existing inactive specialty.
        $this->callApi('POST', '', $this->input(['code' => 'NEW']))->assertUnprocessable();
        $this->create(['code' => 'EMPTY', 'specialty_ids' => []]);
        config(['clinics.doctor_staff_types' => []]);
        $this->callApi('GET')->assertJsonCount(0, 'data');
        $this->callApi('GET', '/options')->assertJsonPath('data.doctor_types_configured', false)->assertJsonCount(0, 'data.staff_types');
        $this->callApi('POST', '', $this->input())->assertUnprocessable()->assertJsonValidationErrors('staff_type_id');
    }

    public function test_counts_are_distinct_attending_complete_not_clinic_patients_and_references_block_delete(): void
    {
        $doctor = $this->create();
        $otherDoctor = $this->create(['code' => 'OTHER']);
        $clinic = $this->clinic();
        $periods = [];
        foreach ([$this->facility, $this->other] as $facility) {
            $periods[$facility] = DB::table('reporting_periods')->insertGetId(['facility_id' => $facility, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        }
        $patients = [];
        foreach ([1, 2, 3] as $i) {
            $patients[] = DB::table('patients')->insertGetId(['patient_code' => 'SECRET-'.$i, 'first_name' => 'مريض سري', 'family_name' => 'خاص', 'search_name' => 'سري', 'identity_document_type' => 'unknown', 'created_by' => $this->user->id]);
        }
        foreach ([[$patients[0], 'complete', $doctor['id'], $this->facility], [$patients[0], 'complete', $doctor['id'], $this->facility], [$patients[1], 'draft', $doctor['id'], $this->facility], [$patients[1], 'complete', $otherDoctor['id'], $this->facility], [$patients[2], 'complete', $doctor['id'], $this->other]] as [$patient, $status, $staff, $facility]) {
            DB::table('visits')->insert(['visit_no' => (string) Str::uuid(), 'facility_id' => $facility, 'patient_id' => $patient, 'reporting_period_id' => $periods[$facility], 'visit_date' => '2026-09-11', 'clinic_id' => $facility === $this->facility ? $clinic : null, 'attending_staff_id' => $staff, 'resident_staff_id' => $otherDoctor['id'], 'status' => $status, 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->user->id]);
        }
        $this->callApi('GET', '/'.$doctor['id'])->assertJsonPath('data.patient_count', 1)->assertDontSee('SECRET')->assertDontSee('مريض سري');
        $listed = $this->callApi('GET', '/'.$doctor['id'].'/patients')->assertOk()->assertJsonPath('meta.total', 1)->json('data');
        $this->assertSame('SECRET-1', $listed[0]['patient_code']);
        $this->assertSame('مريض سري خاص', $listed[0]['patient_name']);
        $this->assertSame(2, $listed[0]['visit_count']);
        $this->callApi('GET', '/'.$doctor['id'].'/patients', ['search' => 'سري'])->assertJsonPath('meta.total', 1);
        $this->callApi('GET', '/'.$otherDoctor['id'].'/patients')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.patient_code', 'SECRET-2');
        $document = null;
        View::composer('reports.directory', function ($view) use (&$document) {
            $document = $view->getData();
        });
        $this->callApi('GET', '/'.$doctor['id'].'/report')->assertOk();
        $this->assertSame('SECRET-1', $document['rows'][0]['patients'][0]['code']);
        $this->assertStringContainsString('جدول المرضى', view('reports.directory', $document)->render());
        $voided = (array) DB::table('visits')->where('attending_staff_id', $doctor['id'])->where('facility_id', $this->facility)->first();
        unset($voided['id']);
        DB::table('visits')->insert(array_replace($voided, ['visit_no' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'patient_id' => $patients[1], 'status' => 'void', 'voided_at' => now(), 'voided_by' => $this->user->id, 'void_reason' => 'اختبار إلغاء']));
        $this->callApi('GET', '/'.$doctor['id'])->assertJsonPath('data.patient_count', 1);
        $this->callApi('DELETE', '/'.$doctor['id'], ['lock_version' => 1])->assertConflict();
        $accountDoctor = $this->create(['code' => 'ACCOUNT']);
        User::factory()->create(['staff_id' => $accountDoctor['id']]);
        $this->callApi('DELETE', '/'.$accountDoctor['id'], ['lock_version' => 1])->assertConflict();
        $procedureDoctor = $this->create(['code' => 'PROCEDURE']);
        $procedure = DB::table('procedures')->insertGetId(['code' => 'TEST', 'name_ar' => 'إجراء اختباري']);
        $visit = DB::table('visits')->where('facility_id', $this->facility)->first();
        DB::table('visit_procedures')->insert(['visit_id' => $visit->id, 'facility_id' => $this->facility, 'reporting_period_id' => $periods[$this->facility], 'performed_on' => '2026-09-11', 'procedure_id' => $procedure, 'specialist_id' => $procedureDoctor['id'], 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->user->id]);
        $this->callApi('GET', '/'.$procedureDoctor['id'])->assertJsonPath('data.patient_count', 0);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'desc', 'per_page' => 1])->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.patient_count', 1);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'asc', 'per_page' => 1])->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.patient_count', 0);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'desc', 'per_page' => 1, 'page' => 3])->assertJsonPath('data.0.patient_count', 0);
        $this->callApi('DELETE', '/'.$procedureDoctor['id'], ['lock_version' => 1])->assertConflict()->assertJsonPath('error.code', 'DOCTOR_REFERENCED');
        $this->assertSame(1, DB::table('visit_procedures')->where('facility_id', $this->facility)->count());
        $this->assertSame(6, DB::table('visits')->whereIn('facility_id', [$this->facility, $this->other])->count());
    }

    public function test_search_matches_only_current_scoped_clinics_without_duplicate_doctors(): void
    {
        $clinic = $this->clinic('LINK-CODE');
        DB::table('clinics')->where('id', $clinic)->update(['name_ar' => 'جراحة مشتركة']);
        $doctor = $this->create(['clinic_add_ids' => [$clinic]]);
        $second = $this->create(['code' => '0002', 'clinic_add_ids' => [$clinic]]);
        DB::table('clinic_staff')->insert(['staff_id' => $doctor['id'], 'clinic_id' => $clinic, 'starts_on' => '2020-01-01']);
        foreach (['LINK-CODE', 'جراحة مشتركة'] as $search) {
            $this->callApi('GET', '', ['search' => $search, 'per_page' => 1, 'page' => 2])
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
        }
        foreach (['expired', 'future', 'inactive', 'foreign'] as $case) {
            $excluded = $this->clinic('EXCLUDED-'.$case, $case === 'foreign' ? $this->other : null, $case !== 'inactive');
            DB::table('clinic_staff')->insert(['staff_id' => $doctor['id'], 'clinic_id' => $excluded,
                'starts_on' => $case === 'future' ? now('Asia/Damascus')->addDay()->toDateString() : '2020-01-01',
                'ends_on' => $case === 'expired' ? now('Asia/Damascus')->toDateString() : null]);
            $this->callApi('GET', '', ['search' => 'EXCLUDED-'.$case])->assertOk()->assertJsonPath('meta.total', 0);
        }
    }

    public function test_paginated_link_options_do_not_load_patient_counts_and_reject_mixed_batch_mode(): void
    {
        $doctor = $this->create();
        $clinic = $this->clinic();
        foreach ([['/api/doctors/options/clinics', ['doctor_id' => $doctor['id']], $clinic], ['/api/clinics/options/doctors', ['clinic_id' => $clinic], $doctor['id']]] as [$path, $parent, $id]) {
            $headers = ['Authorization' => 'Bearer '.$this->token];
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->json('GET', $path, $parent + ['facility_id' => $this->facility, 'search' => '', 'page' => 1, 'per_page' => 20], $headers)
                ->assertOk()->assertJsonPath('meta.total', 1);
            $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
            DB::disableQueryLog();
            $this->assertStringNotContainsString('`visits`', $queries);
            foreach (['search' => '', 'page' => 1, 'per_page' => 20] as $key => $value) {
                $this->json('GET', $path, $parent + ['facility_id' => $this->facility, 'ids' => [$id], $key => $value], $headers)
                    ->assertUnprocessable()->assertJsonValidationErrors($key);
            }
        }
        config(['clinics.doctor_staff_types' => ['DOES_NOT_EXIST']]);
        $this->callApi('GET', '/options')->assertOk()->assertJsonPath('data.doctor_types_configured', false);
    }

    public function test_search_filters_pagination_and_bounded_queries(): void
    {
        $clinic = $this->clinic();
        $this->create(['clinic_add_ids' => [$clinic]]);
        $inactive = $this->create(['is_active' => false]);
        $this->callApi('GET', '', ['search' => '0', 'per_page' => 1, 'page' => 2])->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.code', $inactive['code']);
        $this->callApi('GET', '', ['clinic_id' => $clinic, 'specialty_id' => $this->specialty, 'status' => 'active'])->assertJsonCount(1, 'data');
        $this->callApi('GET', '', ['search' => 'توصيف', 'sort' => 'code', 'direction' => 'desc'])->assertJsonPath('data.0.code', $inactive['code']);
        $this->callApi('GET', '', ['sort' => 'staff.password', 'per_page' => 101])->assertUnprocessable();
        DB::enableQueryLog();
        $this->callApi('GET')->assertOk();
        // Sanctum may UPDATE last_used_at when the clock crosses a second. Measure
        // SELECTs so token bookkeeping cannot make the N+1 assertion time-dependent.
        $readCount = fn () => collect(DB::getQueryLog())->filter(fn ($query) => str_starts_with(strtolower(ltrim($query['query'])), 'select '))->count();
        $small = $readCount();
        DB::disableQueryLog();
        foreach (range(3, 8) as $i) {
            $this->create(['code' => 'D'.$i]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->callApi('GET')->assertOk();
        $this->assertSame($small, $readCount());
        DB::disableQueryLog();
    }

    public function test_sanctum_dynamic_scope_private_errors_and_reporting(): void
    {
        $this->callApi('GET', '', [], 'invalid')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->callApi('GET', '', [], $this->user->createToken('limited', [])->plainTextToken)->assertForbidden()->assertJsonPath('error.code', 'MISSING_API_ABILITY');
        $exception = new \RuntimeException('SQL credentials secret');
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $error) use (&$reported) {
            $reported[] = $error;

            return false;
        });
        $this->mock(DoctorQueries::class, fn ($mock) => $mock->shouldReceive('list')->once()->andThrow($exception));
        $this->callApi('GET')->assertStatus(500)->assertJsonPath('error.code', 'DOCTORS_UNAVAILABLE')->assertDontSee('credentials')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$exception], $reported);
        DB::table('permissions')->where('code', 'doctors.view')->update(['is_active' => false]);
        $this->callApi('GET')->assertForbidden();
        $this->user->forceFill(['is_active' => false])->save();
        $this->callApi('GET')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_reports_cairo_all_filtered_rows_safe_text_long_appendices_and_scope(): void
    {
        $doctor = $this->create(['name' => '=HYPERLINK("bad")', 'description' => str_repeat('توصيف عربي طويل ', 180)]);
        $second = $this->create(['name' => '+SUM(1,2)']);
        $this->create(['is_active' => false]);
        $response = $this->callApi('GET', '/export/xlsx', ['status' => 'active', 'per_page' => 1, 'page' => 2, 'columns' => ['code', 'name', 'description']])->assertOk();
        $file = tempnam(storage_path('framework/testing'), 'doctor-report');
        try {
            file_put_contents($file, $response->getContent());
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertSame('Cairo', $book->getDefaultStyle()->getFont()->getName());
            $this->assertSame($doctor['code'], $sheet->getCell('A9')->getValue());
            $this->assertSame($second['code'], $sheet->getCell('A10')->getValue());
            $this->assertSame('=HYPERLINK("bad")', $sheet->getCell('B9')->getValue());
            $this->assertSame('s', $sheet->getCell('B9')->getDataType());
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            $this->assertSame($doctor['description'], $sheet->getCell('C9')->getValue());
            $this->assertSame(3, $book->getSheetCount());
            $this->assertNotNull($book->getSheetByName('المرضى'));
            $allText = '';
            foreach ($book->getSheetByName('النصوص للطباعة')->toArray() as $index => $row) {
                if ($index > 1) {
                    $allText .= $row[3];
                }
            }
            $this->assertSame($doctor['description'], $allText);
            $this->assertStringNotContainsString('active', $sheet->getCell('A5')->getValue());
            $book->disconnectWorksheets();
            unset($sheet, $book);
        } finally {
            @unlink($file);
            gc_collect_cycles();
        }
        foreach (['/export/pdf', '/'.$doctor['id'].'/report'] as $path) {
            $pdf = $this->callApi('GET', $path)->assertOk()->assertHeader('Content-Type', 'application/pdf')->getContent();
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertStringContainsString('/FontFile2', $pdf);
            $this->assertStringContainsString('Cairo', $pdf);
            $this->assertStringNotContainsString('DejaVu', $pdf);
        }
        config(['clinics.export_limit' => 1]);
        $this->callApi('GET', '/export/pdf')->assertUnprocessable()->assertJsonPath('error.code', 'EXPORT_LIMIT_EXCEEDED');
    }

    public function test_explicit_grant_is_previewed_idempotent_and_never_guessed_from_role_name(): void
    {
        DB::table('global_user_roles')->where('user_id', $this->user->id)->delete();
        $this->artisan('doctors:grant-access', ['--user' => 'testadmin', '--role' => DB::table('roles')->where('id', $this->role)->value('code'), '--facility' => ['TEST-A'], '--global' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('global_user_roles')->where('user_id', $this->user->id)->count());
        foreach ([1, 2] as $attempt) {
            $this->artisan('doctors:grant-access', ['--user' => 'testadmin', '--role' => DB::table('roles')->where('id', $this->role)->value('code'), '--facility' => ['TEST-A'], '--global' => true, '--apply' => true])->assertSuccessful();
        }
        $this->assertSame(1, DB::table('global_user_roles')->where('user_id', $this->user->id)->count());
        $this->assertSame(1, DB::table('facility_user_roles')->where('user_id', $this->user->id)->count());
        DB::table('permissions')->where('code', 'doctors.directory.delete')->update(['is_active' => false]);
        $this->seed(DoctorPermissionsSeeder::class);
        $this->seed(DoctorPermissionsSeeder::class);
        $this->assertDatabaseHas('permissions', ['code' => 'doctors.directory.delete', 'is_active' => false]);
        $this->callApi('GET', '/options')->assertJsonPath('data.capabilities.delete', false);
        $this->artisan('doctors:grant-access', ['--user' => 'missing', '--facility' => ['TEST-A'], '--apply' => true])->assertFailed();
    }

    public function test_openapi_matches_all_doctor_shapes_and_separates_link_only_writes(): void
    {
        $doctor = $this->create(['clinic_add_ids' => [$this->clinic()]]);
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['' => '/api/doctors', '/options' => '/api/doctors/options', '/options/clinics' => '/api/doctors/options/clinics', '/'.$doctor['id'] => '/api/doctors/{doctor}', '/'.$doctor['id'].'/clinics' => '/api/doctors/{doctor}/clinics', '/'.$doctor['id'].'/patients' => '/api/doctors/{doctor}/patients'] as $suffix => $path) {
            $operation = $document['paths'][$path]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $this->assertMatchesSchema($document, $operation['responses'][200]['content']['application/json']['schema'], $this->callApi('GET', $suffix)->assertOk()->json());
            foreach ([401, 403, 404, 409, 422, 500] as $code) {
                $this->assertArrayHasKey($code, $operation['responses']);
            }
        }
        $body = $this->resolveSchema($document, $document['paths']['/api/doctors/{doctor}/clinics']['put']['requestBody']['content']['application/json']['schema']);
        foreach (['/api/doctors/options/clinics', '/api/clinics/options/doctors'] as $path) {
            $parameters = collect($document['paths'][$path]['get']['parameters']);
            $ids = $parameters->firstWhere('name', 'ids[]');
            $this->assertNotNull($ids);
            $this->assertFalse($ids['required'] ?? false);
            $this->assertSame(100, $ids['schema']['maxItems']);
        }
        $lookup = $this->callApi('GET', '/options/clinics', ['doctor_id' => $doctor['id'], 'ids' => [$doctor['clinics_preview'][0]['id'], 999999]])->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/doctors/options/clinics']['get']['responses'][200]['content']['application/json']['schema'], $lookup);
        $this->assertArrayNotHasKey('name', $body['properties']);
        $this->assertContains('lock_version', $body['required']);
        $this->assertSame(200, $body['properties']['clinic_add_ids']['maxItems']);
        $create = $this->resolveSchema($document, $document['paths']['/api/doctors']['post']['requestBody']['content']['application/json']['schema']);
        $this->assertArrayNotHasKey('code', $create['properties']);
        $this->assertContains('request_id', $create['required']);
        $this->assertSame('uuid', $create['properties']['request_id']['format']);
        $this->assertSame(10000, $create['properties']['description']['maxLength']);
        $this->assertArrayHasKey('application/pdf', $document['paths']['/api/doctors/{doctor}/report']['get']['responses'][200]['content']);
        $this->assertArrayHasKey('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $document['paths']['/api/doctors/export/{format}']['get']['responses'][200]['content']);
    }
}
