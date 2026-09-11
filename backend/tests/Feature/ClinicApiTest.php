<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Clinics\ClinicQueries;
use Carbon\Carbon;
use Database\Seeders\ClinicPermissionsSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class ClinicApiTest extends TestCase
{
    use AssertsOpenApi;
    use RefreshDatabase;

    private User $user;

    private string $token;

    private int $facility;

    private int $doctor;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        gc_collect_cycles();
        config(['clinics.doctor_staff_types' => ['DOCTOR']]);
        $this->user = User::factory()->create(['name' => 'مُصدر التقرير الاختباري']);
        $this->token = $this->user->createToken('clinic-test', ['api'])->plainTextToken;
        $this->facility = $this->assign('TEST-A', ['view', 'create', 'update', 'delete', 'export']);
        $this->type = DB::table('staff_types')->insertGetId(['code' => 'DOCTOR', 'name_ar' => 'نوع طبي اختباري']);
        $this->doctor = $this->staff('D001');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Report parsers and Laravel's test application contain cyclic references.
        gc_collect_cycles();
    }

    private function assign(string $code, array $actions): int
    {
        $facility = DB::table('facilities')->insertGetId(['code' => $code, 'name_ar' => 'منشأة اختبار '.$code, 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code]);
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'facility_id' => $facility, 'role_id' => $role]);
        foreach ($actions as $action) {
            $permission = DB::table('permissions')->where('code', 'clinics.'.$action)->value('id')
                ?? DB::table('permissions')->insertGetId(['code' => 'clinics.'.$action, 'name_ar' => $action]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }

        return $facility;
    }

    private function staff(string $code, array $extra = []): int
    {
        return DB::table('staff')->insertGetId($extra + ['staff_code' => $code, 'full_name' => 'طبيب تجريبي '.$code, 'search_name' => $code, 'staff_type_id' => $this->type]);
    }

    private function callApi(string $method, string $path = '', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/clinics'.$path, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function create(array $extra = []): array
    {
        return $this->callApi('POST', '', $extra + ['code' => '001', 'name_ar' => 'العيادة الاختبارية', 'description' => 'توصيف عربي', 'is_active' => true])
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')->json('data');
    }

    private function edit(array $clinic, array $changes = [])
    {
        return $this->callApi('PUT', '/'.$clinic['id'], $changes + [
            'code' => $clinic['code'], 'name_ar' => $clinic['name_ar'], 'description' => $clinic['description'],
            'is_active' => $clinic['is_active'], 'lock_version' => $clinic['lock_version'],
        ]);
    }

    public function test_crud_zero_many_doctors_uniqueness_and_audit(): void
    {
        $clinic = $this->create();
        $this->assertSame(0, $clinic['doctor_count']);
        $second = $this->staff('D002');
        $updated = $this->edit($clinic, ['doctor_add_ids' => [$this->doctor, $second]])->assertOk()->assertJsonPath('data.doctor_count', 2)->json('data');
        $this->callApi('GET', '/'.$clinic['id'])->assertJsonPath('data.code', '001');
        $this->callApi('GET', '/'.$clinic['id'].'/doctors')->assertJsonPath('meta.total', 2)->assertJsonCount(2, 'data');
        $this->callApi('POST', '', ['code' => '001', 'name_ar' => 'مكرر', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('code');
        $other = $this->assign('TEST-B', ['view', 'create']);
        $this->create(['facility_id' => $other]);
        $this->edit($updated, ['is_active' => false])->assertOk();
        foreach (['created', 'updated', 'doctors_changed', 'deactivated'] as $event) {
            $this->assertDatabaseHas('audit_logs', ['entity_id' => $clinic['id'], 'event' => $event, 'actor_id' => $this->user->id]);
        }
        $this->callApi('DELETE', '/'.$clinic['id'], ['lock_version' => 3])->assertConflict()->assertJsonPath('error.code', 'CLINIC_REFERENCED');
        $empty = $this->create(['code' => '002']);
        $this->callApi('DELETE', '/'.$empty['id'], ['lock_version' => 1])->assertNoContent();
        $this->assertDatabaseMissing('clinics', ['id' => $empty['id']]);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $empty['id'], 'event' => 'deleted']);
    }

    public function test_doctor_eligibility_fail_closed_and_validation(): void
    {
        $nurseType = DB::table('staff_types')->insertGetId(['code' => 'NURSE', 'name_ar' => 'طبيب']);
        $nurse = $this->staff('N1', ['staff_type_id' => $nurseType]);
        $inactive = $this->staff('D2', ['is_active' => false]);
        foreach ([$nurse, $inactive, 999999] as $id) {
            $this->callApi('POST', '', ['code' => 'bad', 'name_ar' => 'رفض', 'is_active' => true, 'doctor_add_ids' => [$id]])->assertUnprocessable()->assertJsonValidationErrors('doctor_add_ids');
        }
        $this->assertDatabaseCount('clinics', 0);
        $this->callApi('GET', '/options/doctors')->assertJsonPath('meta.total', 1);
        DB::table('staff_types')->where('id', $this->type)->update(['is_active' => false]);
        $this->callApi('GET', '/options/doctors')->assertJsonPath('meta.total', 0);
        config(['clinics.doctor_staff_types' => []]);
        $this->callApi('GET', '/options/doctors')->assertJsonPath('doctor_types_configured', false)->assertJsonCount(0, 'data');
        $this->callApi('POST', '', ['code' => str_repeat('x', 41), 'name_ar' => str_repeat('x', 201)])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name_ar', 'is_active']);
    }

    public function test_history_same_day_reopen_and_stale_version(): void
    {
        $this->travelTo(now()->setTimezone('Asia/Damascus')->setTime(12, 0));
        $clinic = $this->create(['doctor_add_ids' => [$this->doctor]]);
        $today = now('Asia/Damascus')->toDateString();
        $updated = $this->edit($clinic, ['doctor_remove_ids' => [$this->doctor]])->assertOk()->assertJsonPath('data.doctor_count', 0)->json('data');
        $this->assertDatabaseHas('clinic_staff', ['starts_on' => $today, 'ends_on' => $today]);
        $this->edit($clinic)->assertConflict()->assertJsonPath('error.code', 'CLINIC_VERSION_CONFLICT');
        $updated = $this->edit($updated, ['doctor_add_ids' => [$this->doctor]])->assertOk()->assertJsonPath('data.doctor_count', 1)->json('data');
        $this->edit($updated, ['doctor_add_ids' => [$this->doctor]])->assertOk();
        $this->assertDatabaseCount('clinic_staff', 1);
        $this->assertSame(7, DB::table('audit_logs')->where('entity_type', 'clinic')->count());
        $this->assertSame(3, DB::table('audit_logs')->where('entity_type', 'doctor')->where('event', 'clinics_changed')->count());
        $this->assertDatabaseHas('staff', ['id' => $this->doctor, 'lock_version' => 4]);
        $this->travelBack();
    }

    public function test_hidden_links_survive_and_future_overlap_is_rejected(): void
    {
        $clinic = $this->create(['doctor_add_ids' => [$this->doctor]]);
        DB::table('staff')->where('id', $this->doctor)->update(['is_active' => false]);
        $updated = $this->edit($clinic, ['name_ar' => 'تعديل الاسم فقط'])->assertOk()->assertJsonPath('data.doctor_count', 0)->json('data');
        $this->assertDatabaseHas('clinic_staff', ['staff_id' => $this->doctor, 'ends_on' => null]);
        $future = $this->staff('FUTURE');
        DB::table('clinic_staff')->insert(['clinic_id' => $clinic['id'], 'staff_id' => $future, 'starts_on' => now()->addMonth()->toDateString()]);
        $this->edit($updated, ['doctor_add_ids' => [$future]])->assertConflict()->assertJsonPath('error.code', 'CLINIC_PERIOD_CONFLICT');
        $this->assertDatabaseHas('clinics', ['id' => $clinic['id'], 'lock_version' => 2]);
    }

    public function test_authentication_dynamic_facility_permissions_and_no_idor(): void
    {
        $clinic = $this->create();
        $deniedFacility = $this->assign('DENIED', ['export']);
        foreach (['', '/'.$clinic['id'], '/'.$clinic['id'].'/doctors', '/options/doctors', '/options/specialties', '/export/xlsx', '/'.$clinic['id'].'/report'] as $path) {
            $this->callApi('GET', $path, [], 'invalid')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
            $this->callApi('GET', $path, ['facility_id' => $deniedFacility])->assertForbidden()->assertJsonMissing(['name_ar' => $clinic['name_ar']]);
        }
        $other = $this->assign('OTHER', ['view']);
        $this->callApi('GET', '/'.$clinic['id'], ['facility_id' => $other])->assertNotFound();
        $this->callApi('GET', '', [], $this->user->createToken('no-api', [])->plainTextToken)->assertForbidden()->assertJsonPath('error.code', 'MISSING_API_ABILITY');
        DB::table('permissions')->where('code', 'clinics.view')->update(['is_active' => false]);
        $this->callApi('GET')->assertForbidden();
        DB::table('permissions')->where('code', 'clinics.view')->update(['is_active' => true]);
        $this->user->forceFill(['is_active' => false])->save();
        $this->callApi('GET')->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_patients_count_distinct_complete_visits_only_and_delete_protection(): void
    {
        $clinic = $this->create();
        $period = DB::table('reporting_periods')->insertGetId(['facility_id' => $this->facility, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $visitType = DB::table('visit_types')->insertGetId(['code' => 'TEST', 'name_ar' => 'اختبار']);
        $patient = DB::table('patients')->insertGetId(['patient_code' => 'P-SECRET', 'first_name' => 'اسم سري', 'family_name' => 'خاص', 'search_name' => 'سري', 'identity_document_type' => 'unknown', 'created_by' => $this->user->id]);
        foreach (['complete', 'complete', 'draft', 'void'] as $status) {
            DB::table('visits')->insert(['visit_no' => (string) Str::uuid(), 'facility_id' => $this->facility, 'patient_id' => $patient, 'reporting_period_id' => $period, 'visit_date' => '2026-09-11', 'visit_type_id' => $visitType, 'clinic_id' => $clinic['id'], 'attending_staff_id' => $this->doctor, 'status' => $status, 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->user->id, 'voided_at' => $status === 'void' ? now() : null, 'voided_by' => $status === 'void' ? $this->user->id : null, 'void_reason' => $status === 'void' ? 'اختبار' : null]);
        }
        $this->callApi('GET', '/'.$clinic['id'])->assertJsonPath('data.patient_count', 1)->assertDontSee('P-SECRET')->assertDontSee('اسم سري');
        $this->create(['code' => 'EMPTY']);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'desc', 'per_page' => 1])->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $clinic['id'])->assertJsonPath('data.0.patient_count', 1);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'asc', 'per_page' => 1])->assertJsonPath('data.0.patient_count', 0);
        $this->callApi('GET', '', ['sort' => 'patient_count', 'direction' => 'desc', 'per_page' => 1, 'page' => 2])->assertJsonPath('data.0.patient_count', 0);
        $this->callApi('DELETE', '/'.$clinic['id'], ['lock_version' => 1])->assertConflict();
        $this->callApi('POST', '/'.$clinic['id'].'/deactivate', ['lock_version' => 1])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseCount('visits', 4);
    }

    public function test_search_matches_current_eligible_doctors_without_duplicate_clinics(): void
    {
        $clinic = $this->create(['doctor_add_ids' => [$this->doctor]]);
        $second = $this->create(['code' => '002', 'doctor_add_ids' => [$this->doctor]]);
        DB::table('clinic_staff')->insert(['clinic_id' => $clinic['id'], 'staff_id' => $this->doctor, 'starts_on' => '2020-01-01']);
        DB::table('staff')->where('id', $this->doctor)->update(['full_name' => 'اسم طبيب مرتبط']);
        foreach (['D001', 'اسم طبيب مرتبط'] as $search) {
            $this->callApi('GET', '', ['search' => $search, 'per_page' => 1, 'page' => 2])
                ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second['id']);
        }
        foreach (['expired', 'future', 'inactive', 'wrong-type'] as $case) {
            $doctor = $this->staff('EXCLUDED-'.$case, ['is_active' => $case !== 'inactive']);
            if ($case === 'wrong-type') {
                $type = DB::table('staff_types')->insertGetId(['code' => 'OTHER', 'name_ar' => 'طبيب']);
                DB::table('staff')->where('id', $doctor)->update(['staff_type_id' => $type]);
            }
            DB::table('clinic_staff')->insert(['clinic_id' => $clinic['id'], 'staff_id' => $doctor,
                'starts_on' => $case === 'future' ? now('Asia/Damascus')->addDay()->toDateString() : '2020-01-01',
                'ends_on' => $case === 'expired' ? now('Asia/Damascus')->toDateString() : null]);
            $this->callApi('GET', '', ['search' => 'EXCLUDED-'.$case])->assertOk()->assertJsonPath('meta.total', 0);
        }
        $foreign = $this->assign('OTHER', ['view', 'create']);
        $this->create(['facility_id' => $foreign, 'doctor_add_ids' => [$this->doctor]]);
        $this->callApi('GET', '', ['search' => 'D001'])->assertJsonPath('meta.total', 2);
        DB::table('staff_types')->where('id', $this->type)->update(['is_active' => false]);
        $this->callApi('GET', '', ['search' => 'D001'])->assertJsonPath('meta.total', 0);
        $this->callApi('GET', '/options/doctors')->assertOk()->assertJsonPath('doctor_types_configured', false);
    }

    public function test_search_sort_pagination_and_bounded_queries(): void
    {
        $specialty = DB::table('specialties')->insertGetId(['code' => 'S', 'name_ar' => 'تخصص اختباري']);
        $this->create(['code' => '010', 'specialty_id' => $specialty, 'doctor_add_ids' => [$this->doctor]]);
        $this->create(['code' => '020', 'is_active' => false]);
        $this->callApi('GET', '', ['search' => 'توصيف', 'per_page' => 1, 'page' => 2])->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.code', '020');
        $this->callApi('GET', '', ['doctor_id' => $this->doctor, 'specialty_id' => $specialty, 'status' => 'active'])->assertJsonCount(1, 'data');
        $this->callApi('GET', '', ['sort' => 'code', 'direction' => 'desc'])->assertJsonPath('data.0.code', '020');
        $this->callApi('GET', '', ['sort' => 'code; DROP TABLE clinics', 'per_page' => 101])->assertUnprocessable()->assertJsonValidationErrors(['sort', 'per_page']);
        DB::enableQueryLog();
        $this->callApi('GET')->assertOk();
        $small = count(DB::getQueryLog());
        for ($i = 0; $i < 10; $i++) {
            DB::table('clinics')->insert(['facility_id' => $this->facility, 'code' => 'M'.$i, 'name_ar' => 'اختبار']);
        }
        DB::flushQueryLog();
        $this->callApi('GET')->assertOk();
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->create(['code' => 'ABC']);
        $this->callApi('GET', '', ['search' => '0'])->assertJsonPath('meta.total', 3);
    }

    public function test_real_excel_all_filtered_rows_columns_issuer_unique_numbers_and_injection(): void
    {
        $this->create(['code' => '0001', 'name_ar' => '=HYPERLINK("bad")']);
        $this->create(['code' => '0002', 'name_ar' => '+SUM(1,2)']);
        $this->create(['code' => 'OTHER', 'is_active' => false]);
        $filters = ['per_page' => 1, 'page' => 2, 'status' => 'active', 'direction' => 'desc', 'columns' => ['code', 'name_ar']];
        $response = $this->callApi('GET', '/export/xlsx', $filters)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $file = tempnam(storage_path('framework/testing'), 'clinic-xlsx');
        try {
            file_put_contents($file, $response->getContent());
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('Cairo', $book->getDefaultStyle()->getFont()->getName());
            $this->assertSame('A9', $sheet->getFreezePane());
            $this->assertSame('0002', $sheet->getCell('A9')->getValue());
            $this->assertSame('0001', $sheet->getCell('A10')->getValue());
            $this->assertSame('s', $sheet->getCell('B10')->getDataType());
            $this->assertSame('=HYPERLINK("bad")', $sheet->getCell('B10')->getValue());
            $this->assertSame('B', $sheet->getHighestDataColumn());
            $this->assertSame(10, $sheet->getHighestDataRow());
            $this->assertSame($this->user->name, $book->getProperties()->getCreator());
            $this->assertCount(1, $sheet->getDrawingCollection());
            $book->disconnectWorksheets();
            unset($sheet, $book);
        } finally {
            @unlink($file);
            gc_collect_cycles();
        }
        $other = $this->callApi('GET', '/export/xlsx', $filters)->assertOk();
        $this->assertNotSame($response->headers->get('X-Report-Number'), $other->headers->get('X-Report-Number'));
        config(['clinics.export_limit' => 1]);
        $this->callApi('GET', '/export/xlsx')->assertUnprocessable()->assertJsonPath('error.code', 'EXPORT_LIMIT_EXCEEDED');
        $this->callApi('GET', '/export/xlsx', ['columns' => ['actions']])->assertUnprocessable();
    }

    public function test_pdf_is_real_arabic_multipage_and_escapes_untrusted_markup(): void
    {
        $clinic = $this->create(['description' => '<img src="https://example.invalid/secret"><script>private()</script>'.str_repeat(' هذا توصيف عربي طويل يختبر التقرير عبر عدة صفحات. ', 100)]);
        $response = $this->callApi('GET', '/'.$clinic['id'].'/report')->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $file = tempnam(storage_path('framework/testing'), 'clinic-pdf');
        try {
            file_put_contents($file, $response->getContent());
            $reader = new Mpdf(['tempDir' => storage_path('framework/cache/clinic-pdf')]);
            $this->assertGreaterThan(1, $reader->setSourceFile($file));
        } finally {
            unset($reader);
            gc_collect_cycles();
            @unlink($file);
        }
        $html = view('reports.directory', ['rows' => [], 'columns' => ['code'], 'labels' => ['code' => 'الكود'], 'metadata' => ['title' => '<script>secret</script>', 'number' => 'TEST', 'issued_at' => '', 'timezone' => '', 'issuer' => '', 'facility' => '', 'filters' => '', 'definition' => ''], 'detail' => false])->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_openapi_matches_actual_clinic_doctor_and_export_contracts(): void
    {
        $clinic = $this->create(['doctor_add_ids' => [$this->doctor]]);
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['' => '/api/clinics', '/'.$clinic['id'] => '/api/clinics/{clinic}', '/'.$clinic['id'].'/doctors' => '/api/clinics/{clinic}/doctors', '/options/doctors' => '/api/clinics/options/doctors', '/options/specialties' => '/api/clinics/options/specialties'] as $suffix => $path) {
            $operation = $document['paths'][$path]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $body = $this->callApi('GET', $suffix)->assertOk()->json();
            $this->assertMatchesSchema($document, $operation['responses'][200]['content']['application/json']['schema'], $body);
            foreach ([401, 403, 404, 500] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
        }
        $export = $document['paths']['/api/clinics/export/{format}']['get'];
        $this->assertArrayHasKey('application/pdf', $export['responses'][200]['content']);
        $this->assertArrayHasKey('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $export['responses'][200]['content']);
        $this->assertArrayNotHasKey('content', $document['paths']['/api/clinics/{clinic}']['delete']['responses'][204]);
        $createSchema = $this->resolveSchema($document, $document['paths']['/api/clinics']['post']['requestBody']['content']['application/json']['schema']);
        $updateSchema = $this->resolveSchema($document, $document['paths']['/api/clinics/{clinic}']['put']['requestBody']['content']['application/json']['schema']);
        $this->assertArrayNotHasKey('lock_version', $createSchema['properties']);
        $this->assertArrayNotHasKey('doctor_remove_ids', $createSchema['properties']);
        $this->assertContains('lock_version', $updateSchema['required']);
    }

    public function test_current_dates_use_facility_timezone_and_remove_duplicate_legacy_links(): void
    {
        $this->travelTo(Carbon::parse('2026-09-10 22:00:00', 'UTC'));
        $clinic = $this->create();
        foreach (['2026-09-01', '2026-09-11'] as $start) {
            DB::table('clinic_staff')->insert(['clinic_id' => $clinic['id'], 'staff_id' => $this->doctor, 'starts_on' => $start]);
        }
        $ended = $this->staff('ENDED');
        DB::table('clinic_staff')->insert(['clinic_id' => $clinic['id'], 'staff_id' => $ended, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-11']);
        $this->callApi('GET', '/'.$clinic['id'])->assertJsonPath('data.doctor_count', 1)->assertJsonCount(1, 'data.doctors_preview');
        $this->callApi('GET', '/'.$clinic['id'].'/doctors')->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data');
        $this->edit($clinic, ['doctor_add_ids' => [$this->doctor]])->assertConflict();
        $this->travelBack();
    }

    public function test_unexpected_exceptions_are_reported_and_sanitized_and_invalid_routes_are_private(): void
    {
        config(['app.debug' => true]);
        $exception = new \RuntimeException('secret SQL credentials and trace');
        $handler = $this->app->make(ExceptionHandler::class);
        $reported = [];
        $handler->reportable(function (\Throwable $error) use (&$reported) {
            $reported[] = $error;

            return false;
        });
        $this->mock(ClinicQueries::class, fn ($mock) => $mock->shouldReceive('list')->once()->andThrow($exception));
        $this->callApi('GET')->assertStatus(500)->assertExactJson(['error' => ['code' => 'CLINICS_UNAVAILABLE', 'message' => 'تعذّر إتمام العملية. حاول مجددًا.']])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$exception], $reported);
        $this->callApi('GET', '/invalid')->assertNotFound()->assertDontSee('trace')->assertDontSee('exception')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame([$exception], $reported);
    }

    public function test_permissions_seeder_never_grants_or_reactivates_permissions_and_pdf_never_silently_shrinks_long_cells(): void
    {
        DB::table('permissions')->where('code', 'clinics.delete')->update(['is_active' => false]);
        $count = DB::table('role_permissions')->count();
        $this->seed(ClinicPermissionsSeeder::class);
        $this->seed(ClinicPermissionsSeeder::class);
        $this->assertSame($count, DB::table('role_permissions')->count());
        $this->assertDatabaseHas('permissions', ['code' => 'clinics.delete', 'is_active' => false]);
        $this->create(['description' => str_repeat('توصيف طويل ', 200)]);
        $pdf = $this->callApi('GET', '/export/pdf')->assertOk()->getContent();
        $this->assertStringContainsString('Cairo', $pdf);
        $this->assertStringContainsString('/FontFile2', $pdf);
        $this->callApi('GET', '/export/pdf', ['columns' => ['code', 'name_ar']])->assertOk();
        $this->callApi('GET', '/export/xlsx')->assertOk();
    }
}
