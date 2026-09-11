<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Directory\DirectoryReferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class DirectoryLifecycleTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private User $user;

    private string $token;

    private int $facility;

    private int $other;

    private int $doctor;

    private int $clinic;

    private int $type;

    protected function setUp(): void
    {
        parent::setUp();
        config(['clinics.doctor_staff_types' => ['DOCTOR']]);
        $this->travelTo(now()->setTime(12, 0));
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('lifecycle', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'TEST-A', 'name_ar' => 'اختبار', 'timezone' => 'Asia/Damascus']);
        $this->other = DB::table('facilities')->insertGetId(['code' => 'SECRET-B', 'name_ar' => 'منشأة محجوبة', 'timezone' => 'Pacific/Honolulu']);
        $role = DB::table('roles')->insertGetId(['code' => 'lifecycle', 'name_ar' => 'اختبار']);
        foreach (['doctors.view', 'doctors.link', 'doctors.directory.update', 'doctors.directory.delete', 'clinics.view', 'clinics.update', 'clinics.delete'] as $code) {
            $permission = DB::table('permissions')->insertGetId(['code' => $code, 'name_ar' => $code]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'facility_id' => $this->facility, 'role_id' => $role]);
        DB::table('global_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $role]);
        $this->type = DB::table('staff_types')->insertGetId(['code' => 'DOCTOR', 'name_ar' => 'طبيب']);
        $this->doctor = DB::table('staff')->insertGetId(['staff_code' => 'D1', 'full_name' => 'طبيب اختبار', 'search_name' => 'اختبار', 'staff_type_id' => $this->type]);
        $this->clinic = DB::table('clinics')->insertGetId(['facility_id' => $this->facility, 'code' => 'C1', 'name_ar' => 'عيادة اختبار']);
    }

    public static function directories(): array
    {
        return [['doctors'], ['clinics']];
    }

    private function id(string $kind): int
    {
        return $kind === 'doctors' ? $this->doctor : $this->clinic;
    }

    private function table(string $kind): string
    {
        return $kind === 'doctors' ? 'staff' : 'clinics';
    }

    private function api(string $kind, string $method, string $suffix = '', array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$kind.'/'.$this->id($kind).$suffix, $data + ['facility_id' => $this->facility], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function act(string $kind, string $action, ?int $version = null)
    {
        return $this->api($kind, 'POST', '/'.$action, ['lock_version' => $version ?? DB::table($this->table($kind))->where('id', $this->id($kind))->value('lock_version')]);
    }

    private function link(string $start = '2020-01-01', ?string $end = null): int
    {
        return DB::table('clinic_staff')->insertGetId(['clinic_id' => $this->clinic, 'staff_id' => $this->doctor, 'starts_on' => $start, 'ends_on' => $end]);
    }

    #[DataProvider('directories')]
    public function test_organizational_references_offer_archive_and_preserve_history(string $kind): void
    {
        $this->link();
        $this->api($kind, 'DELETE', '', ['lock_version' => 1])->assertConflict();
        $this->api($kind, 'GET', '/deletion-preview')->assertOk()->assertJsonPath('data.action', 'archive')->assertJsonPath('data.organizational_links', 1);
        $this->act($kind, 'archive', 1)->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $this->clinic, 'staff_id' => $this->doctor, 'ends_on' => now('Asia/Damascus')->toDateString()]);
        $this->assertDatabaseHas('staff', ['id' => $this->doctor]);
        $this->assertDatabaseHas('clinics', ['id' => $this->clinic]);
        $this->act($kind, 'archive', 1)->assertConflict();
        $this->act($kind, 'archive')->assertConflict();
        $this->act($kind, 'restore')->assertOk()->assertJsonPath('data.archived_at', null)->assertJsonPath('data.is_active', false);
        $this->act($kind, 'reactivate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->api($kind, 'GET')->assertJsonPath('data.'.($kind === 'doctors' ? 'clinic_count' : 'doctor_count'), 0);
        $this->assertSame(1, DB::table('audit_logs')->where('entity_type', $kind === 'doctors' ? 'doctor' : 'clinic')->where('entity_id', $this->id($kind))->where('event', 'archived')->count());
    }

    #[DataProvider('directories')]
    public function test_deactivation_with_links_succeeds_and_reactivation_preserves_dates(string $kind): void
    {
        $this->link();
        $before = DB::table('clinic_staff')->first();
        $this->act($kind, 'deactivate')->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertEquals($before, DB::table('clinic_staff')->first());
        $this->act($kind, 'reactivate')->assertOk()->assertJsonPath('data.is_active', true);
        $this->assertEquals($before, DB::table('clinic_staff')->first());
        $this->api($kind, 'GET')->assertJsonPath('data.'.($kind === 'doctors' ? 'clinic_count' : 'doctor_count'), 1);
    }

    public function test_inactive_clinic_cannot_receive_a_new_doctor_link(): void
    {
        DB::table('clinics')->where('id', $this->clinic)->update(['is_active' => false]);
        $this->api('clinics', 'PUT', '', ['lock_version' => 1, 'code' => 'C1', 'name_ar' => 'عيادة اختبار', 'is_active' => false, 'doctor_add_ids' => [$this->doctor]])->assertUnprocessable();
        $this->assertDatabaseCount('clinic_staff', 0);
    }

    #[DataProvider('directories')]
    public function test_unreferenced_records_are_hard_deleted_and_preview_is_not_a_guarantee(string $kind): void
    {
        $this->api($kind, 'GET', '/deletion-preview')->assertJsonPath('data.action', 'delete');
        $this->api($kind, 'DELETE', '', ['lock_version' => 99])->assertConflict();
        $this->api($kind, 'DELETE', '', ['lock_version' => 1])->assertNoContent();
        $this->assertDatabaseMissing($this->table($kind), ['id' => $this->id($kind)]);
        $this->assertDatabaseHas($kind === 'doctors' ? 'clinics' : 'staff', ['id' => $kind === 'doctors' ? $this->clinic : $this->doctor]);
    }

    private function visit(): int
    {
        $patient = DB::table('patients')->insertGetId(['patient_code' => 'SECRET', 'first_name' => 'اسم مريض سري', 'family_name' => 'خاص', 'search_name' => 'سري', 'identity_document_type' => 'unknown', 'created_by' => $this->user->id]);
        $type = DB::table('visit_types')->insertGetId(['code' => 'TEST', 'name_ar' => 'اختبار']);
        $period = DB::table('reporting_periods')->insertGetId(['facility_id' => $this->facility, 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString()]);

        return DB::table('visits')->insertGetId(['visit_no' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'reporting_period_id' => $period, 'facility_id' => $this->facility, 'patient_id' => $patient, 'visit_type_id' => $type, 'clinic_id' => $this->clinic, 'attending_staff_id' => $this->doctor, 'visit_date' => now()->toDateString(), 'status' => 'complete', 'entered_by' => $this->user->id]);
    }

    #[DataProvider('directories')]
    public function test_reference_added_after_preview_blocks_delete_but_archive_preserves_medical_history(string $kind): void
    {
        $this->api($kind, 'GET', '/deletion-preview')->assertJsonPath('data.action', 'delete');
        $visit = $this->visit();
        $this->api($kind, 'DELETE', '', ['lock_version' => 1])->assertConflict();
        $this->api($kind, 'GET', '/deletion-preview')->assertJsonPath('data.has_other_references', true)->assertDontSee('اسم مريض سري');
        $this->act($kind, 'deactivate')->assertOk();
        $this->act($kind, 'archive')->assertOk()->assertJsonPath('data.patient_count', 1);
        $this->assertDatabaseHas('visits', ['id' => $visit, 'clinic_id' => $this->clinic, 'attending_staff_id' => $this->doctor]);
        $this->api($kind, 'GET')->assertOk()->assertJsonPath('data.patient_count', 1);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/'.$kind.'?facility_id='.$this->facility, ['Authorization' => 'Bearer '.$this->token])->assertJsonCount(0, 'data');
        $this->getJson('/api/'.$kind.'?facility_id='.$this->facility.'&status=archived', ['Authorization' => 'Bearer '.$this->token])->assertJsonPath('data.0.id', $this->id($kind));
    }

    #[DataProvider('directories')]
    public function test_archive_cancels_future_periods_and_restore_never_reopens_any_period(string $kind): void
    {
        $past = $this->link('2018-01-01', '2019-01-01');
        $current = $this->link();
        $futureDate = now()->addMonth()->toDateString();
        $future = $this->link($futureDate);
        $this->act($kind, 'archive')->assertOk();
        $this->assertDatabaseHas('clinic_staff', ['id' => $past, 'ends_on' => '2019-01-01']);
        $this->assertDatabaseHas('clinic_staff', ['id' => $current, 'ends_on' => now('Asia/Damascus')->toDateString()]);
        $this->assertDatabaseHas('clinic_staff', ['id' => $future, 'ends_on' => $futureDate]);
        $after = DB::table('clinic_staff')->get()->all();
        $this->api($kind, 'GET', '/link-history')->assertOk()->assertJsonPath('meta.total', 3);
        $this->act($kind, 'reactivate')->assertConflict();
        $this->act($kind, 'restore')->assertOk();
        $this->act($kind, 'reactivate')->assertOk();
        $this->assertEquals($after, DB::table('clinic_staff')->get()->all());
        $this->travelTo(now()->addMonths(2));
        $this->api($kind, 'GET')->assertJsonPath('data.'.($kind === 'doctors' ? 'clinic_count' : 'doctor_count'), 0);
    }

    public function test_counts_require_both_endpoints_and_current_dates(): void
    {
        $this->link('2018-01-01', '2019-01-01');
        $this->link();
        $this->link(now()->addYear()->toDateString());
        $before = DB::table('clinic_staff')->get()->all();
        foreach (['doctors', 'clinics'] as $kind) {
            $this->act($kind, 'deactivate')->assertOk();
        }
        $this->act('doctors', 'reactivate')->assertOk()->assertJsonPath('data.clinic_count', 0);
        $this->api('clinics', 'GET')->assertJsonPath('data.doctor_count', 0);
        $this->act('clinics', 'reactivate')->assertOk()->assertJsonPath('data.doctor_count', 1);
        $this->api('doctors', 'GET')->assertJsonPath('data.clinic_count', 1);
        $this->assertEquals($before, DB::table('clinic_staff')->get()->all());
    }

    public function test_activation_requires_active_configured_medical_type_in_both_write_paths(): void
    {
        $this->act('doctors', 'deactivate')->assertOk();
        DB::table('staff_types')->where('id', $this->type)->update(['is_active' => false]);
        $this->act('doctors', 'reactivate')->assertUnprocessable()->assertJsonValidationErrors('staff_type_id');
        $this->api('doctors', 'PUT', '', ['code' => 'D1', 'name' => 'طبيب', 'staff_type_id' => $this->type, 'specialty_ids' => [], 'lock_version' => 2, 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('staff_type_id');
        $this->assertDatabaseHas('staff', ['id' => $this->doctor, 'is_active' => false, 'lock_version' => 2]);
    }

    public function test_doctor_archive_is_global_but_preview_and_history_do_not_leak_foreign_facility(): void
    {
        $this->link();
        $foreign = DB::table('clinics')->insertGetId(['facility_id' => $this->other, 'code' => 'SECRET-CLINIC', 'name_ar' => 'محجوب']);
        DB::table('clinic_staff')->insert(['clinic_id' => $foreign, 'staff_id' => $this->doctor, 'starts_on' => '2020-01-01']);
        $this->api('doctors', 'GET', '/deletion-preview')->assertJsonPath('data.organizational_links', 1)->assertDontSee('SECRET');
        $this->api('doctors', 'GET', '/link-history')->assertJsonPath('meta.total', 1)->assertDontSee('SECRET');
        $this->act('doctors', 'archive')->assertOk();
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $foreign, 'ends_on' => now('Pacific/Honolulu')->toDateString()]);
        $this->assertDatabaseHas('clinics', ['id' => $foreign, 'is_active' => true, 'lock_version' => 2]);
        $this->api('clinics', 'GET')->assertJsonPath('data.doctor_count', 0)->assertJsonPath('data.lock_version', 2);
    }

    public function test_link_only_permissions_never_grant_global_lifecycle_and_foreign_clinic_is_hidden(): void
    {
        DB::table('global_user_roles')->delete();
        foreach (['archive', 'deactivate', 'reactivate', 'restore'] as $action) {
            $this->act('doctors', $action)->assertForbidden();
        }
        $this->api('doctors', 'GET', '/deletion-preview')->assertForbidden();
        $this->api('doctors', 'DELETE', '', ['lock_version' => 1])->assertForbidden();
        $this->api('clinics', 'POST', '/archive', ['facility_id' => $this->other, 'lock_version' => 1])->assertForbidden();
        DB::table('clinics')->where('id', $this->clinic)->update(['facility_id' => $this->other]);
        $this->act('clinics', 'archive')->assertNotFound();
        $this->api('clinics', 'GET', '/link-history')->assertNotFound();
    }

    #[DataProvider('directories')]
    public function test_archived_records_cannot_be_edited_or_assigned_and_counterpart_version_advances(string $kind): void
    {
        $this->link();
        $this->act($kind, 'archive')->assertOk();
        $fields = $kind === 'doctors' ? ['code' => 'D1', 'name' => 'طبيب', 'staff_type_id' => $this->type, 'specialty_ids' => []] : ['code' => 'C1', 'name_ar' => 'عيادة'];
        $this->api($kind, 'PUT', '', $fields + ['lock_version' => 2, 'is_active' => true])->assertConflict();
        $opposite = $kind === 'doctors' ? 'clinics' : 'doctors';
        $this->api($opposite, 'GET')->assertJsonPath('data.lock_version', 2)->assertJsonPath('data.'.($kind === 'doctors' ? 'doctor_count' : 'clinic_count'), 0);
        $path = $kind === 'doctors' ? '/api/clinics/options/doctors' : '/api/doctors/options/clinics';
        $this->getJson($path.'?facility_id='.$this->facility, ['Authorization' => 'Bearer '.$this->token])->assertJsonCount(0, 'data');
    }

    public function test_reference_inventory_covers_all_current_foreign_keys(): void
    {
        $covered = ['staff' => DirectoryReferences::DOCTOR + ['clinic_staff' => ['staff_id'], 'staff_specialties' => ['staff_id']], 'clinics' => DirectoryReferences::CLINIC + ['clinic_staff' => ['clinic_id']]];
        foreach (Schema::getTableListing() as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                if (isset($covered[$fk['foreign_table']])) {
                    $this->assertContains($fk['columns'][0], $covered[$fk['foreign_table']][Str::afterLast($table, '.')] ?? [], $table.' reference must be classified');
                }
            }
        }
    }

    #[DataProvider('directories')]
    public function test_lifecycle_openapi_matches_actual_responses(string $kind): void
    {
        $this->link();
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        $parameter = $kind === 'doctors' ? 'doctor' : 'clinic';
        foreach (['deletion-preview', 'link-history', 'archive', 'restore', 'reactivate', 'deactivate'] as $action) {
            $method = in_array($action, ['deletion-preview', 'link-history']) ? 'get' : 'post';
            $operation = $document['paths']['/api/'.$kind.'/{'.$parameter.'}/'.$action][$method];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $response = $method === 'get' ? $this->api($kind, 'GET', '/'.$action) : $this->act($kind, $action);
            $this->assertMatchesSchema($document, $operation['responses'][200]['content']['application/json']['schema'], $response->assertOk()->json());
        }
    }

    public function test_view_only_clinic_user_can_read_archived_history_but_cannot_change_lifecycle(): void
    {
        $this->link();
        $this->act('clinics', 'archive')->assertOk();
        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', ['clinics.update', 'clinics.delete'])->select('id'))->delete();
        $this->api('clinics', 'GET', '/link-history')->assertOk()->assertJsonPath('meta.total', 1);
        foreach (['archive', 'restore', 'reactivate', 'deactivate'] as $action) {
            $this->act('clinics', $action)->assertForbidden();
        }
        $this->api('clinics', 'GET', '/deletion-preview')->assertForbidden();
        $this->api('clinics', 'DELETE', '', ['lock_version' => 2])->assertForbidden();
        $this->assertDatabaseHas('clinics', ['id' => $this->clinic, 'lock_version' => 2, 'is_active' => false]);
    }

    #[DataProvider('directories')]
    public function test_archived_filter_exports_the_same_records_and_detail_reports_stay_available(string $kind): void
    {
        $role = DB::table('global_user_roles')->where('user_id', $this->user->id)->value('role_id');
        $permission = DB::table('permissions')->insertGetId(['code' => $kind.'.export', 'name_ar' => 'تصدير']);
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        $this->link();
        $this->act($kind, 'archive')->assertOk();
        $code = $kind === 'doctors' ? 'D1' : 'C1';
        $headers = ['Authorization' => 'Bearer '.$this->token];
        $response = $this->getJson('/api/'.$kind.'/export/xlsx?facility_id='.$this->facility.'&status=archived&columns[]=code&per_page=1&page=2', $headers)->assertOk();
        $file = tempnam(storage_path('framework/testing'), 'lifecycle-export');
        try {
            file_put_contents($file, $response->getContent());
            $book = IOFactory::load($file);
            $sheet = $book->getActiveSheet();
            $this->assertSame($code, $sheet->getCell('A9')->getValue());
            $this->assertSame('s', $sheet->getCell('A9')->getDataType());
            $this->assertStringContainsString('الحالة: مؤرشف', json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE));
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $this->assertStringStartsWith('%PDF-', $this->api($kind, 'GET', '/report')->assertOk()->getContent());
    }

    #[DataProvider('directories')]
    public function test_explicit_link_after_restore_does_not_reopen_cancelled_future_period(string $kind): void
    {
        $futureDate = now()->addYear()->toDateString();
        $future = $this->link($futureDate);
        $this->act($kind, 'archive')->assertOk();
        $this->act($kind, 'restore')->assertOk();
        $this->act($kind, 'reactivate')->assertOk();
        $fields = $kind === 'doctors'
            ? ['code' => 'D1', 'name' => 'طبيب', 'staff_type_id' => $this->type, 'specialty_ids' => [], 'clinic_add_ids' => [$this->clinic]]
            : ['code' => 'C1', 'name_ar' => 'عيادة', 'doctor_add_ids' => [$this->doctor]];
        $this->api($kind, 'PUT', '', $fields + ['lock_version' => 4, 'is_active' => true])->assertOk();
        $this->assertDatabaseHas('clinic_staff', ['id' => $future, 'starts_on' => $futureDate, 'ends_on' => $futureDate]);
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $this->clinic, 'staff_id' => $this->doctor, 'starts_on' => now('Asia/Damascus')->toDateString(), 'ends_on' => null]);
        $this->assertDatabaseCount('clinic_staff', 2);
    }
}
