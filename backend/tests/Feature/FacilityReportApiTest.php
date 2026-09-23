<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsOpenApi;
use Tests\TestCase;

class FacilityReportApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private function token(User $user): string
    {
        return $user->createToken('test', ['api'])->plainTextToken;
    }

    private function assignment(User $user, string $code, array $permissions): int
    {
        $facility = DB::table('facilities')->insertGetId(['code' => $code, 'name_ar' => 'مشفى '.$code, 'timezone' => 'Asia/Damascus', 'is_active' => true]);
        $role = DB::table('roles')->insertGetId(['code' => $code, 'name_ar' => $code, 'is_active' => true]);
        DB::table('facility_user_roles')->insert(['facility_id' => $facility, 'role_id' => $role, 'user_id' => $user->id]);
        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('code', $permission)->value('id')
                ?? DB::table('permissions')->insertGetId(['code' => $permission, 'name_ar' => $permission, 'is_active' => true]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }

        return $facility;
    }

    private function getReport(string $query, ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/reports'.$query, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    public function test_reports_view_opens_empty_stats_and_user_one_does_not_bypass(): void
    {
        $user = User::factory()->create(['name' => 'مدير النظام']);
        $outsider = User::factory()->create(['name' => 'مستخدم سري']);
        $token = $this->token($user);
        $this->getReport('?period=day', $token)->assertUnprocessable()->assertJsonValidationErrors('facility_id');
        $this->getReport('?facility_id=2147483647&period=day', $token)->assertForbidden()->assertJsonPath('error.code', 'FACILITY_ACCESS_DENIED');
        $facility = $this->assignment($user, 'REP0', []);
        $this->assignment($outsider, 'REPX', ['dossiers.view']);
        $this->getReport('?facility_id='.$facility.'&period=day', $token)->assertForbidden()->assertJsonPath('error.code', 'FACILITY_ACCESS_DENIED');
        $facility = $this->assignment($user, 'REP1', ['reports.view']);
        $empty = $this->getReport('?facility_id='.$facility.'&period=day', $token)->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Authorization');
        $this->assertSame([], $empty->json('data.counters'));
        $this->assertSame([], $empty->json('data.visit_status'));
        $this->assertSame([], $empty->json('data.clinics'));
        $this->assertSame([], $empty->json('data.patients'));
        $this->assertSame('day', $empty->json('data.period.key'));
        $this->assertSame($facility, $empty->json('data.facility.id'));
        $this->assertStringNotContainsString($outsider->name, $empty->getContent());
        $this->assertStringNotContainsString('"password"', $empty->getContent());
    }

    public function test_period_windows_permission_gating_and_facility_isolation(): void
    {
        $user = User::factory()->create(['name' => 'قارئ التقارير']);
        $outsider = User::factory()->create(['name' => 'مستخدم سري']);
        $allowed = $this->assignment($user, 'REPA', ['reports.view', 'dossiers.view', 'dossiers.treatment.view', 'clinics.view', 'doctors.view']);
        $denied = $this->assignment($user, 'REPB', ['reports.view']);
        $foreign = $this->assignment($outsider, 'REPC', ['dossiers.view', 'clinics.view', 'doctors.view']);
        $today = now('Asia/Damascus')->toDateString();
        $yesterday = now('Asia/Damascus')->subDay()->toDateString();
        $lastWeek = now('Asia/Damascus')->subDays(8)->toDateString();
        $type = DB::table('staff_types')->insertGetId(['code' => 'REP-DR', 'name_ar' => 'طبيب تقارير']);
        $doctor = DB::table('staff')->insertGetId(['staff_code' => 'REP-DR-1', 'full_name' => 'طبيب النشاط', 'search_name' => 'طبيب النشاط', 'staff_type_id' => $type]);
        $hiddenDoctor = DB::table('staff')->insertGetId(['staff_code' => 'REP-DR-2', 'full_name' => 'طبيب محجوب', 'search_name' => 'طبيب محجوب', 'staff_type_id' => $type]);
        $clinic = DB::table('clinics')->insertGetId(['facility_id' => $allowed, 'code' => 'REP-CL', 'name_ar' => 'عيادة النشاط']);
        $hiddenClinic = DB::table('clinics')->insertGetId(['facility_id' => $denied, 'code' => 'REP-HID', 'name_ar' => 'عيادة بلا صلاحية']);
        $foreignClinic = DB::table('clinics')->insertGetId(['facility_id' => $foreign, 'code' => 'REP-FOR', 'name_ar' => 'عيادة منشأة أخرى']);
        $patient = DB::table('patients')->insertGetId(['patient_code' => 'REP-P1', 'first_name' => 'سامي', 'family_name' => 'التقرير', 'search_name' => 'سامي التقرير', 'identity_document_type' => 'unknown', 'created_by' => $user->id]);
        $hiddenPatient = DB::table('patients')->insertGetId(['patient_code' => 'REP-P2', 'first_name' => 'محجوب', 'family_name' => 'الزيارة', 'search_name' => 'محجوب الزيارة', 'identity_document_type' => 'unknown', 'created_by' => $user->id]);
        $foreignPatient = DB::table('patients')->insertGetId(['patient_code' => 'REP-P3', 'first_name' => 'أجنبي', 'family_name' => 'العيادة', 'search_name' => 'أجنبي العيادة', 'identity_document_type' => 'unknown', 'created_by' => $outsider->id]);
        $dossier = DB::table('patient_dossiers')->insertGetId(['facility_id' => $allowed, 'patient_id' => $patient, 'code' => 'REP-D1', 'opening_date' => $today, 'status' => 'active', 'entered_by' => $user->id]);
        DB::table('patient_dossiers')->insert(['facility_id' => $denied, 'patient_id' => $hiddenPatient, 'code' => 'REP-D2', 'opening_date' => $today, 'status' => 'draft', 'entered_by' => $user->id]);
        $this->visit($allowed, $patient, $clinic, $doctor, $user->id, 'complete', $today, $dossier);
        $this->visit($allowed, $patient, $clinic, $doctor, $user->id, 'draft', $yesterday, $dossier);
        $this->visit($allowed, $patient, $clinic, $doctor, $user->id, 'complete', $lastWeek, $dossier);
        $this->visit($denied, $hiddenPatient, $hiddenClinic, $hiddenDoctor, $user->id, 'complete', $today);
        $this->visit($foreign, $foreignPatient, $foreignClinic, $hiddenDoctor, $outsider->id, 'complete', $today);

        $token = $this->token($user);
        $day = $this->getReport('?facility_id='.$allowed.'&period=day', $token)->assertOk()->json('data');
        $this->assertSame($today, $day['period']['starts_on']);
        $this->assertSame($today, $day['period']['ends_on']);
        $this->assertSame(1, collect($day['counters'])->firstWhere('key', 'visits')['value']);
        $this->assertSame(1, collect($day['counters'])->firstWhere('key', 'completed_visits')['value']);
        $this->assertSame(1, collect($day['counters'])->firstWhere('key', 'dossiers')['value']);
        $this->assertSame(0, collect($day['counters'])->firstWhere('key', 'procedures')['value']);
        $this->assertSame(['complete' => 1, 'draft' => 0], array_column($day['visit_status'], 'value', 'key'));
        $this->assertSame([['id' => $clinic, 'name_ar' => 'عيادة النشاط', 'visit_count' => 1]], $day['clinics']);
        $this->assertSame([['id' => $doctor, 'name_ar' => 'طبيب النشاط', 'visit_count' => 1]], $day['doctors']);
        $this->assertSame([], $day['procedures']);
        $this->assertSame('سامي التقرير', $day['patients'][0]['patient_name']);
        $this->assertSame($dossier, $day['patients'][0]['dossier_id']);
        $this->assertSame(1, collect($day['series'])->firstWhere('key', $today)['value']);

        $week = $this->getReport('?facility_id='.$allowed.'&period=week', $token)->assertOk()->json('data');
        $this->assertSame(now('Asia/Damascus')->subDays(6)->toDateString(), $week['period']['starts_on']);
        $this->assertSame($today, $week['period']['ends_on']);
        $this->assertSame(2, collect($week['counters'])->firstWhere('key', 'visits')['value']);
        $this->assertCount(7, $week['series']);

        $custom = $this->getReport('?facility_id='.$allowed.'&period=custom&from='.$lastWeek.'&to='.$today, $token)->assertOk()->json('data');
        $this->assertSame(3, collect($custom['counters'])->firstWhere('key', 'visits')['value']);
        $this->getReport('?facility_id='.$allowed.'&period=custom', $token)->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getReport('?facility_id='.$allowed.'&period=day&from='.$today, $token)->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->getReport('?facility_id='.$allowed.'&period=custom&from=1990-01-01&to=1992-01-02', $token)->assertUnprocessable()->assertJsonValidationErrors('to');

        $blank = $this->getReport('?facility_id='.$denied.'&period=day', $token)->assertOk();
        $this->assertSame([], $blank->json('data.counters'));
        $this->assertSame([], $blank->json('data.patients'));
        foreach ([$outsider->name, 'عيادة بلا صلاحية', 'عيادة منشأة أخرى', 'طبيب محجوب', 'محجوب الزيارة', 'أجنبي العيادة'] as $secret) {
            $this->assertStringNotContainsString($secret, $this->getReport('?facility_id='.$allowed.'&period=day', $token)->getContent());
        }
    }

    public function test_json_get_does_not_write_audit_and_pdf_export_does(): void
    {
        $user = User::factory()->create(['name' => 'مُصدر التقرير']);
        $facility = $this->assignment($user, 'REPP', ['reports.view', 'reports.export', 'dossiers.view']);
        $token = $this->token($user);
        $before = DB::table('audit_logs')->count();
        $this->getReport('?facility_id='.$facility.'&period=day', $token)->assertOk();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->app['auth']->forgetGuards();
        $pdf = $this->get('/api/reports/export/pdf?facility_id='.$facility.'&period=day', ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/pdf']);
        $pdf->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertDatabaseHas('audit_logs', ['facility_id' => $facility, 'entity_type' => 'facility_report', 'event' => 'exported', 'actor_id' => $user->id]);
        $this->assertNotEmpty($pdf->headers->get('X-Report-Number'));
        $this->assertSame($before + 1, DB::table('audit_logs')->count());
    }

    public function test_documentation_matches_actual_report_responses(): void
    {
        config(['scramble.enabled' => true]);
        $viewer = User::factory()->create();
        $id = $this->assignment($viewer, 'REPE', ['reports.view']);
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        $this->assertArrayHasKey('/api/reports', $doc['paths']);
        $this->assertArrayHasKey('/api/reports/export/pdf', $doc['paths']);
        $operation = $doc['paths']['/api/reports']['get'];
        $this->assertSame('facilityReport', $operation['operationId']);
        $this->assertStringContainsString('api ability', $operation['description']);
        $this->assertStringContainsString('reports.view', $operation['description']);
        $response = $this->getReport('?facility_id='.$id.'&period=week', $this->token($viewer))->assertOk();
        $this->assertMatchesSchema($doc, $operation['responses'][200]['content']['application/json']['schema'], $response->json());
    }

    public function test_unexpected_errors_are_redacted(): void
    {
        config(['app.debug' => true]);
        $reported = [];
        app(ExceptionHandler::class)->reportable(function (\Throwable $exception) use (&$reported) {
            $reported[] = $exception;

            return false;
        });
        $token = $this->token(User::factory()->create());
        $this->mock(UserAccessContext::class)->shouldReceive('forUser')->andThrow(new \RuntimeException('internal database detail'));
        $this->getReport('?facility_id=1&period=day', $token)->assertStatus(500)->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['error' => ['code' => 'REPORTS_UNAVAILABLE', 'message' => 'تعذّر تحميل التقارير. حاول مجددًا.']]);
        $this->assertCount(1, $reported);
    }

    private function visit(int $facility, int $patient, int $clinic, int $staff, int $actor, string $status, string $date, ?int $dossier = null): int
    {
        return DB::table('visits')->insertGetId([
            'visit_no' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
            'facility_id' => $facility, 'patient_id' => $patient, 'clinic_id' => $clinic, 'attending_staff_id' => $staff,
            'visit_date' => $date, 'status' => $status, 'entered_by' => $actor, 'dossier_id' => $dossier,
        ]);
    }
}
