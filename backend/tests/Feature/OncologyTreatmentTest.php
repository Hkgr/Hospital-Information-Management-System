<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReports;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use App\Services\Dossiers\OncologyWriter;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionCase;

class OncologyTreatmentTest extends DossierCompletionCase
{
    private int $funding;

    private int $period;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([OncologyPermissionsSeeder::class, DossierPathologyPermissionsSeeder::class] as $seeder) {
            $this->seed($seeder);
            foreach (DB::table('permissions')->whereIn('code', array_keys($seeder::CODES))->pluck('id') as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
            }
        }
        $this->funding = DB::table('funding_sources')->insertGetId(['code' => 'ONC-'.Str::random(10), 'name_ar' => 'تمويل اختباري', 'is_active' => true]);
        $this->period = DB::table('reporting_periods')->where('facility_id', $this->f['facility'])->where('starts_on', '<=', '2001-03-02')->where('ends_on', '>=', '2001-03-02')->value('id') ?? DB::table('reporting_periods')->insertGetId(['facility_id' => $this->f['facility'], 'starts_on' => '2001-01-01', 'ends_on' => '2001-12-31', 'status' => 'open']);
    }

    private function planPath(?int $id = null): string
    {
        return '/'.$this->s['id'].'/treatment-plans'.($id ? '/'.$id : '');
    }

    private function item(): array
    {
        return ['medication_id' => $this->f['medication'], 'dose_value' => '2.5', 'dose_unit' => 'mg', 'route' => 'IV', 'funding_source_id' => $this->funding, 'quantity' => '1', 'quantity_unit' => 'vial'];
    }

    private function planData(array $extra = []): array
    {
        return $extra + ['modality' => 'chemotherapy', 'intent' => 'curative', 'protocol_name' => 'خطة اختبار =1+1', 'protocol_code' => '000123', 'starts_on' => '2001-03-02', 'planned_sessions' => 3, 'items' => [$this->item()]] + $this->context();
    }

    private function makePlan(array $extra = []): array
    {
        return $this->callApi('POST', $this->planPath(), $this->planData($extra))->assertCreated()->json('data');
    }

    private function ready(): int
    {
        $id = $this->callApi('POST', $this->path('/pathology'), ['source' => 'external', 'status' => 'completed', 'result_on' => '1999-01-01', 'external_organization' => 'مختبر', 'conclusion' => 'دليل مؤكد'])->assertCreated()->json('data.id');
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_confirmed', 'evidence_pathology_id' => $id, 'assessed_on' => '2001-03-02'])->assertOk();

        return $id;
    }

    private function activate(array $p, array $extra = [])
    {
        return $this->callApi('POST', $this->planPath($p['id']).'/status', $extra + ['lock_version' => $p['lock_version'], 'status' => 'active', 'reason' => 'مراجعة الطبيب']);
    }

    private function schedule(array $p, string $date = '2001-03-02'): array
    {
        $this->callApi('POST', $this->planPath($p['id']).'/sessions', ['lock_version' => $p['lock_version'], 'sessions' => [['session_number' => 1, 'planned_on' => $date], ['session_number' => 2, 'planned_on' => '2090-02-01']]])->assertCreated();

        return (array) DB::table('oncology_sessions')->where('plan_id', $p['id'])->orderBy('id')->first();
    }

    private function dose(array $s, array $extra = []): array
    {
        return $extra + ['session_id' => $s['id'], 'session_lock_version' => $s['lock_version'], 'plan_lock_version' => DB::table('oncology_plans')->where('id', $s['plan_id'])->value('lock_version'), 'visit_lock_version' => $this->s['visit']['lock_version'], 'administered_on' => '2001-03-02', 'reporting_period_id' => $this->period, 'supervising_staff_id' => $this->f['workflow_doctors'][0], 'administered_by' => $this->f['workflow_doctors'][0], 'items' => [$this->item()]];
    }

    private function historicalSession(array $p): array
    {
        $this->callApi('POST', $this->planPath($p['id']).'/sessions', ['lock_version' => $p['lock_version'], 'sessions' => [['session_number' => 1, 'planned_on' => '2001-03-02']]])->assertCreated();

        return (array) DB::table('oncology_sessions')->where('plan_id', $p['id'])->first();
    }

    private function resolve(array $s, array $extra = [])
    {
        return $this->callApi('PUT', '/'.$this->s['id'].'/treatment-sessions/'.$s['id'], $extra + ['lock_version' => $s['lock_version'], 'plan_lock_version' => DB::table('oncology_plans')->where('id', $s['plan_id'])->value('lock_version'), 'status' => 'rescheduled', 'planned_on' => '2001-03-02', 'reason' => 'تصحيح موعد الحضور']);
    }

    private function obsoleteSession(bool $administer = false): array
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->historicalSession($p);
        $dose = $administer ? $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id') : null;
        $p = $this->callApi('PUT', $this->planPath($p['id']), $this->planData(['lock_version' => $this->dose($s)['plan_lock_version'], 'clinic_id' => $this->f['clinics'][1], 'doctor_id' => $this->f['workflow_doctors'][1], 'note' => 'تصحيح النسخة', 'amendment_reason' => 'اعتماد طبيب وعيادة جديدين']))->assertOk()->json('data');
        $p = $this->activate($p)->assertOk()->json('data');

        return [$p, (array) DB::table('oncology_sessions')->find($s['id']), $dose];
    }

    private function writerResolutionRejected(array $s, array $changes, string $code = 'ONCOLOGY_INVALID_CARRY_FORWARD'): void
    {
        $r = Request::create('/testing/oncology-resolution');
        $r->setUserResolver(fn () => $this->f['user']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility']);
        $data = $changes + ['request_id' => (string) Str::uuid(), 'lock_version' => $s['lock_version'], 'plan_lock_version' => $this->dose($s)['plan_lock_version'], 'reason' => 'قرار صريح'];
        $audit = DB::table('audit_logs')->count();
        try {
            app(OncologyWriter::class)->session($r, $f, $this->s['id'], $s['id'], $data);
            $this->fail('The writer itself must reject invalid resolution without relying on FormRequest');
        } catch (HttpResponseException $e) {
            $this->assertSame(422, $e->getResponse()->getStatusCode());
            $this->assertSame($code, json_decode($e->getResponse()->getContent(), true)['error']['code']);
        }
        $this->assertSame($s, (array) DB::table('oncology_sessions')->find($s['id']));
        $this->assertSame($audit, DB::table('audit_logs')->count());
    }

    public function test_resolution_terminal_states_preserve_obsolete_clinical_context(): void
    {
        [, $s] = $this->obsoleteSession();
        foreach (['cancelled', 'missed', 'referred'] as $status) {
            $this->resolve($s, ['status' => $status, 'planned_on' => '2002-01-01'])->assertOk();
            $next = (array) DB::table('oncology_sessions')->find($s['id']);
            foreach (['revision_id', 'clinic_id', 'doctor_id', 'planned_on'] as $key) {
                $this->assertSame($s[$key], $next[$key], $status.' preserves '.$key);
            }
            $this->assertSame($status, $next['status']);
            $s = $next;
        }
    }

    public function test_resolution_terminal_carry_is_rejected_centrally_and_by_api(): void
    {
        [, $s] = $this->obsoleteSession();
        foreach (['cancelled', 'missed', 'referred'] as $status) {
            $data = ['status' => $status, 'carry_forward' => true, 'planned_on' => '2001-03-02'];
            $this->writerResolutionRejected($s, $data);
            $this->resolve($s, $data)->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_INVALID_CARRY_FORWARD');
            $this->assertSame($s, (array) DB::table('oncology_sessions')->find($s['id']));
        }
    }

    public function test_resolution_current_revision_cannot_be_carried_forward(): void
    {
        $this->ready();
        $s = $this->historicalSession($this->activate($this->makePlan())->assertOk()->json('data'));
        $this->writerResolutionRejected($s, ['status' => 'rescheduled', 'carry_forward' => true, 'planned_on' => '2001-03-02']);
        $this->resolve($s, ['carry_forward' => true])->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_INVALID_CARRY_FORWARD');
    }

    public function test_resolution_carry_requires_explicit_date_in_writer_and_request(): void
    {
        [, $s] = $this->obsoleteSession();
        $this->writerResolutionRejected($s, ['status' => 'rescheduled', 'carry_forward' => true]);
        $this->writerResolutionRejected($s, ['status' => 'rescheduled'], 'ONCOLOGY_RESCHEDULE_DATE_REQUIRED');
        $this->resolve($s, ['carry_forward' => true, 'planned_on' => null])->assertUnprocessable()->assertJsonValidationErrors('planned_on')->assertJsonPath('errors.planned_on.0', 'حدد تاريخًا صريحًا لإعادة الجدولة.');
        $this->resolve($s, ['planned_on' => null])->assertUnprocessable()->assertJsonValidationErrors('planned_on');
        $this->resolve($s)->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_OBSOLETE_SESSION_REVISION');
        $this->resolve($s, ['carry_forward' => true])->assertOk();
    }

    public function test_resolution_invalid_atomic_void_keeps_dose_session_and_audit_unchanged(): void
    {
        [$p, $s, $dose] = $this->obsoleteSession(true);
        $before = (array) DB::table('dose_sessions')->find($dose);
        $audit = DB::table('audit_logs')->count();
        foreach (['cancelled', 'missed', 'referred'] as $status) {
            $this->callApi('POST', $this->path('/doses/'.$dose.'/void'), ['lock_version' => 1, 'session_lock_version' => $s['lock_version'], 'plan_lock_version' => $p['lock_version'], 'session_resolution' => $status, 'carry_forward' => true, 'reason' => 'قرار إبطال غير صالح'])->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_INVALID_CARRY_FORWARD');
            $this->assertSame($before, (array) DB::table('dose_sessions')->find($dose));
            $this->assertSame($s, (array) DB::table('oncology_sessions')->find($s['id']));
            $this->assertSame($audit, DB::table('audit_logs')->count());
        }
    }

    public function test_resolution_session_history_is_independent_of_active_dose_without_fanout(): void
    {
        $this->ready();
        $s = $this->historicalSession($this->activate($this->makePlan())->assertOk()->json('data'));
        $this->callApi('GET', '/'.$this->s['id'].'/treatment-sessions')->assertJsonPath('data.0.has_voided_dose', false)->assertJsonPath('data.0.dose_id', null);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $dose = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
            $this->callApi('POST', $this->path('/doses/'.$dose.'/void'), ['lock_version' => 1, 'session_lock_version' => $s['lock_version'] + 1, 'plan_lock_version' => $this->dose($s)['plan_lock_version'], 'session_resolution' => 'rescheduled', 'planned_on' => '2001-03-02', 'reason' => 'إبطال واقعة مسجلة خطأ'])->assertOk();
            $this->callApi('GET', '/'.$this->s['id'].'/treatment-sessions')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.dose_id', null)->assertJsonPath('data.0.visit_id', null)->assertJsonPath('data.0.has_voided_dose', true);
            $s = (array) DB::table('oncology_sessions')->find($s['id']);
        }
        $dose = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $this->callApi('GET', '/'.$this->s['id'].'/treatment-sessions')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.dose_id', $dose)->assertJsonPath('data.0.visit_id', $this->s['visit']['id'])->assertJsonPath('data.0.has_voided_dose', true);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $r = Request::create('/api/dossiers');
        $r->setUserResolver(fn () => $this->f['user']);
        $doc = app(DossierReports::class)->document($r, $f, [], $this->s['id']);
        $section = collect($doc['sections'])->firstWhere('title', 'سجل الجرعات المجدولة');
        $this->assertNotNull($section);
        $this->assertContains('توجد وقائع إعطاء مبطلة محفوظة تاريخيًا', array_column($section['rows'], 'value'));
    }

    public function test_integrity_future_appointment_requires_explicit_historical_reschedule(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p, '2090-01-01');
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_SCHEDULE_DATE_MISMATCH');
        $this->assertSame(0, DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->count());
        $this->resolve($s)->assertOk();
        $s = (array) DB::table('oncology_sessions')->find($s['id']);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated();
        $audit = DB::table('audit_logs')->where('entity_type', 'oncology_sessions')->where('entity_id', $s['id'])->whereNotNull('old_values')->orderBy('id')->first();
        $this->assertSame('2090-01-01', json_decode($audit->old_values, true)['planned_on']);
        $this->assertSame('2001-03-02', json_decode($audit->new_values, true)['planned_on']);
        $this->assertSame('تصحيح موعد الحضور', json_decode($audit->new_values, true)['reason']);
        $this->assertNotNull($audit->occurred_at);
    }

    public function test_integrity_old_revision_requires_explicit_carry_forward(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->historicalSession($p);
        $oldRevision = $s['revision_id'];
        $p = $this->callApi('PUT', $this->planPath($p['id']), $this->planData(['lock_version' => $this->dose($s)['plan_lock_version'], 'clinic_id' => $this->f['clinics'][1], 'doctor_id' => $this->f['workflow_doctors'][1], 'note' => 'تعديل سريري', 'amendment_reason' => 'مراجعة']))->assertOk()->json('data');
        $p = $this->activate($p)->assertOk()->json('data');
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_OBSOLETE_SESSION_REVISION');
        $this->resolve($s, ['carry_forward' => true, 'reason' => ''])->assertUnprocessable();
        $this->resolve($s, ['carry_forward' => true, 'plan_lock_version' => 1])->assertConflict();
        $this->resolve($s, ['carry_forward' => true])->assertOk();
        $s = (array) DB::table('oncology_sessions')->find($s['id']);
        $this->assertSame($p['current_revision_id'], $s['revision_id']);
        $this->assertSame($this->f['clinics'][1], $s['clinic_id']);
        $this->assertSame($this->f['workflow_doctors'][1], $s['doctor_id']);
        $audit = DB::table('audit_logs')->where('entity_type', 'oncology_sessions')->where('entity_id', $s['id'])->orderByDesc('id')->first();
        $this->assertSame($oldRevision, json_decode($audit->old_values, true)['revision_id']);
        $this->assertSame($this->f['clinics'][0], json_decode($audit->old_values, true)['clinic_id']);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['supervising_staff_id' => $s['doctor_id'], 'administered_by' => $s['doctor_id']]))->assertCreated();
        $this->resolve($s, ['carry_forward' => true])->assertConflict();
        $this->assertSame($p['current_revision_id'], DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->value('plan_revision_id'));
        $this->assertDatabaseHas('oncology_plan_revisions', ['id' => $oldRevision]);
    }

    public function test_integrity_revision_boundaries_are_enforced_without_silent_expansion(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan(['ends_on' => '2001-04-01', 'planned_sessions' => 2, 'planned_cycles' => 1]))->assertOk()->json('data');
        foreach ([['planned_on' => '2001-03-01'], ['planned_on' => '2001-04-02'], ['session_number' => 3], ['cycle_number' => 2]] as $change) {
            $this->callApi('POST', $this->planPath($p['id']).'/sessions', ['lock_version' => $p['lock_version'], 'sessions' => [$change + ['planned_on' => '2001-03-02', 'session_number' => 1]]])->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_SESSION_OUTSIDE_PLAN');
        }
        $s = $this->historicalSession($p);
        $this->resolve($s, ['planned_on' => '2001-04-02'])->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_SESSION_OUTSIDE_PLAN');
        $this->assertSame('2001-03-02', DB::table('oncology_sessions')->find($s['id'])->planned_on);
    }

    public function test_integrity_dose_void_requires_resolution_and_can_be_replaced_atomically(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->historicalSession($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $url = $this->path('/doses/'.$id.'/void');
        $this->callApi('POST', $url, ['lock_version' => 1, 'reason' => 'لم يحدث الإعطاء'])->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_INVALID_VOID_RESOLUTION');
        $resolution = ['lock_version' => 1, 'session_lock_version' => 2, 'plan_lock_version' => $this->dose($s)['plan_lock_version'], 'reason' => 'لم يحدث الإعطاء', 'session_resolution' => 'rescheduled', 'planned_on' => '2001-03-01'];
        $this->callApi('POST', $url, $resolution)->assertUnprocessable();
        $this->assertDatabaseHas('dose_sessions', ['id' => $id, 'voided_at' => null]);
        $this->assertDatabaseHas('oncology_sessions', ['id' => $s['id'], 'status' => 'completed']);
        $resolution['planned_on'] = '2001-03-02';
        $this->callApi('POST', $url, $resolution)->assertOk();
        $s = (array) DB::table('oncology_sessions')->find($s['id']);
        $new = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $this->assertNotSame($id, $new);
        $this->assertSame(2, DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->count());
        $this->assertSame(1, DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->whereNull('voided_at')->count());
        $this->assertNotNull(DB::table('dose_sessions')->find($id)->voided_at);
    }

    public function test_integrity_exact_plan_duplicate_requires_explicit_confirmation_and_reason(): void
    {
        $p = $this->makePlan();
        $this->callApi('POST', $this->planPath(), $this->planData())->assertConflict()->assertJsonPath('error.code', 'ONCOLOGY_DUPLICATE_PLAN')->assertJsonPath('error.existing_plan_id', $p['id']);
        $this->callApi('POST', $this->planPath(), $this->planData(['confirm_duplicate' => true]))->assertUnprocessable();
        $copy = $this->callApi('POST', $this->planPath(), $this->planData(['confirm_duplicate' => true, 'duplicate_reason' => 'سبب سريري صريح للتكرار']))->assertCreated()->json('data');
        $this->assertNotSame($p['id'], $copy['id']);
        $this->assertTrue(DB::table('audit_logs')->where('entity_type', 'oncology_plans')->where('entity_id', $copy['id'])->where('new_values', 'like', '%duplicate_reason%')->exists());
        $this->makePlan(['modality' => 'supportive']);
    }

    public function test_integrity_noop_revision_keeps_approval_and_version(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $before = DB::table('oncology_plan_revisions')->where('plan_id', $p['id'])->count();
        $data = $this->planData(['lock_version' => $p['lock_version'], 'amendment_reason' => 'سبب وحده ليس تغييرًا سريريًا']);
        $data['items'][0]['dose_value'] = '2.5000';
        $data['protocol_name'] = '  خطة   اختبار =1+1  ';
        $this->callApi('PUT', $this->planPath($p['id']), $data)->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_NO_CLINICAL_CHANGE');
        $this->assertSame($before, DB::table('oncology_plan_revisions')->where('plan_id', $p['id'])->count());
        $this->assertDatabaseHas('oncology_plans', ['id' => $p['id'], 'status' => 'active', 'lock_version' => $p['lock_version']]);
    }

    public function test_integrity_void_preserves_historical_revision_and_independent_dispensing_in_reports(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->historicalSession($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $dispensed = $this->callApi('POST', $this->path('/dispensing'), ['dose_session_id' => $id, 'dispensed_on' => '2001-03-02', 'reporting_period_id' => $this->period, 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'dispensing_purpose' => 'take_home'] + $this->item())->assertCreated()->json('data.id');
        $current = $this->callApi('PUT', $this->planPath($p['id']), $this->planData(['lock_version' => $this->dose($s)['plan_lock_version'], 'protocol_name' => 'نسخة علاجية معدلة', 'amendment_reason' => 'تغيير سريري']))->assertOk()->json('data');
        $current = $this->activate($current)->assertOk()->json('data');
        $payload = ['lock_version' => 1, 'session_lock_version' => 2, 'plan_lock_version' => $current['lock_version'], 'reason' => 'إعطاء سُجل خطأ', 'session_resolution' => 'rescheduled', 'planned_on' => '2001-03-02'];
        $this->callApi('POST', $this->path('/doses/'.$id.'/void'), $payload)->assertUnprocessable()->assertJsonPath('error.code', 'ONCOLOGY_OBSOLETE_SESSION_REVISION');
        $this->assertDatabaseHas('dose_sessions', ['id' => $id, 'voided_at' => null]);
        $this->callApi('POST', $this->path('/doses/'.$id.'/void'), $payload + ['carry_forward' => true])->assertOk();
        $this->assertDatabaseHas('dose_sessions', ['id' => $id, 'plan_revision_id' => $p['current_revision_id']]);
        $this->assertDatabaseHas('oncology_sessions', ['id' => $s['id'], 'revision_id' => $current['current_revision_id']]);
        $this->assertDatabaseHas('visit_medications', ['id' => $dispensed, 'voided_at' => null]);
        $invalidActive = (array) DB::table('dose_sessions')->where('id', $id)->first();
        unset($invalidActive['id'], $invalidActive['active_oncology_session_id'], $invalidActive['active_plan_revision_id']);
        $invalidActive = array_replace($invalidActive, ['client_request_id' => (string) Str::uuid(), 'voided_at' => null, 'voided_by' => null, 'void_reason' => null]);
        try {
            DB::table('dose_sessions')->insert($invalidActive);
            $this->fail('An active dose cannot use the old revision of a carried session, even outside the writer');
        } catch (QueryException $e) {
            $this->assertSame(1452, (int) $e->errorInfo[1]);
        }
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $request = Request::create('/api/dossiers');
        $request->setUserResolver(fn () => $this->f['user']);
        foreach ([false, true] as $individual) {
            $doc = app(DossierReports::class)->document($request, $f, [], $this->s['id'], $individual ? $this->s['visit']['id'] : null);
            $section = collect($doc['sections'])->firstWhere('title', 'تفاصيل الأدوية المعطاة');
            if ($individual) {
                $this->assertEmpty($section['rows']);
            } else {
                $this->assertContains('إعطاء مبطل — محفوظ تاريخيًا', array_column($section['rows'], 'value'));
                $this->assertContains('إعطاء سُجل خطأ', array_column($section['rows'], 'value'));
            }
            $path = tempnam(sys_get_temp_dir(), 'onc-void');
            file_put_contents($path, app(BloodBankReports::class)->xlsx($doc));
            try {
                $book = IOFactory::load($path);
                $values = [];
                foreach ($book->getAllSheets() as $sheet) {
                    foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
                        $cell = $sheet->getCell($coordinate);
                        $this->assertNotSame('f', $cell->getDataType());
                        $values[] = $cell->getValue();
                    }
                }
                if (! $individual) {
                    $this->assertContains('إعطاء مبطل — محفوظ تاريخيًا', $values);
                }
                $this->assertContains('إعطاء مبطل — الصرف واقعة مستقلة', $values);
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        }
        $this->callApi('POST', $this->path('/dispensing/'.$dispensed.'/void'), ['lock_version' => 1, 'reason' => 'قرار مستقل لإبطال الصرف'])->assertOk();
        $migration = require database_path('migrations/2026_09_21_000001_preserve_voided_oncology_administrations.php');
        try {
            $migration->down();
            $this->fail('Historical revision must prevent unsafe rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback refused', $e->getMessage());
        }
    }

    public function test_integrity_active_unique_constraint_preserves_multiple_attempts_and_scope(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->historicalSession($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $row = (array) DB::table('dose_sessions')->where('id', $id)->first();
        unset($row['id'], $row['active_oncology_session_id'], $row['active_plan_revision_id']);
        $row['client_request_id'] = (string) Str::uuid();
        try {
            DB::table('dose_sessions')->insert($row);
            $this->fail('Active session key must reject a second administration');
        } catch (QueryException $e) {
            $this->assertSame(1062, (int) $e->errorInfo[1]);
        }
        foreach (['missed', 'cancelled', 'referred'] as $resolution) {
            $currentSession = (array) DB::table('oncology_sessions')->where('id', $s['id'])->first();
            $this->callApi('POST', $this->path('/doses/'.$id.'/void'), ['lock_version' => 1, 'session_lock_version' => $currentSession['lock_version'], 'plan_lock_version' => $this->dose($s)['plan_lock_version'], 'reason' => 'اختبار قرار مستقل', 'session_resolution' => $resolution])->assertOk();
            $this->assertDatabaseHas('oncology_sessions', ['id' => $s['id'], 'status' => $resolution]);
            $currentSession = (array) DB::table('oncology_sessions')->where('id', $s['id'])->first();
            $this->resolve($currentSession)->assertOk();
            $currentSession = (array) DB::table('oncology_sessions')->where('id', $s['id'])->first();
            $id = $this->callApi('POST', $this->path('/doses'), $this->dose($currentSession))->assertCreated()->json('data.id');
        }
        $this->assertSame(4, DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->count());
        $this->assertSame(1, DB::table('dose_sessions')->where('oncology_session_id', $s['id'])->whereNull('voided_at')->count());
        $this->callApi('GET', '/'.$this->s['id'].'/treatment-sessions')->assertJsonCount(1, 'data');
        try {
            DB::table('dose_sessions')->where('id', $id)->update(['facility_id' => $this->f['other']]);
            $this->fail('Dose facility composite FK must remain enforced');
        } catch (QueryException $e) {
            $this->assertSame(1452, (int) $e->errorInfo[1]);
        }
        $migration = require database_path('migrations/2026_09_21_000001_preserve_voided_oncology_administrations.php');
        try {
            $migration->down();
            $this->fail('Multiple preserved attempts must prevent unsafe rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rollback refused', $e->getMessage());
        }
    }

    public function test_multiple_plans_filtered_list_aggregates_do_not_issue_per_row_queries(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $this->schedule($p, '2090-01-01');
        $other = $this->activate($this->makePlan(['protocol_code' => 'OTHER']))->assertOk()->json('data');
        $measure = function () {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $r = $this->callApi('GET', '', ['treatment_status' => 'active', 'treatment_modality' => 'chemotherapy', 'dose_from' => '2089-01-01', 'dose_to' => '2091-01-01'])->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$n, $r];
        };
        [$n, $r] = $measure();
        $row = collect($r->json('data'))->firstWhere('id', $this->s['id']);
        $this->assertSame(2, $row['active_treatment_count']);
        $this->assertSame('2090-01-01', $row['next_dose_on']);
        for ($i = 0; $i < 5; $i++) {
            $this->makePlan(['protocol_code' => 'LOAD-'.$i]);
        }
        [$after] = $measure();
        $this->assertSame($n, $after, 'List query count must not grow with plans');
        $this->activate($other, ['lock_version' => 1])->assertConflict();
    }

    public function test_corrections_preserve_omitted_items_snapshots_and_void_history(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['items' => [$this->item(), $this->item()]]))->assertCreated()->json('data.id');
        $items = DB::table('dose_session_items')->where('dose_session_id', $id)->orderBy('id')->get();
        $before = $items[0]->medication_name_snapshot;
        DB::table('medications')->where('id', $this->f['medication'])->update(['name_ar' => 'اسم دليل مصحح']);
        $edit = $this->dose($s, ['lock_version' => 1, 'reason' => 'تصحيح موثق', 'items' => [(array) $items[0] + ['note' => 'تصحيح']]]);
        $this->callApi('PUT', $this->path('/doses/'.$id), $edit)->assertOk();
        $this->assertDatabaseHas('dose_session_items', ['id' => $items[0]->id, 'medication_name_snapshot' => $before]);
        $this->assertDatabaseHas('dose_session_items', ['id' => $items[1]->id, 'voided_at' => null, 'lock_version' => 1]);
        $this->callApi('POST', $this->path('/doses/'.$id.'/void'), ['lock_version' => 2, 'session_lock_version' => 2, 'plan_lock_version' => $this->dose($s)['plan_lock_version'], 'session_resolution' => 'cancelled', 'reason' => 'تصحيح الإعطاء'])->assertOk();
        $this->assertSame(2, DB::table('dose_session_items')->where('dose_session_id', $id)->count());
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertConflict();
        $permission = DB::table('permissions')->where('code', 'dossiers.audit')->value('id');
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        $this->callApi('GET', '/'.$this->s['id'].'/audit')->assertOk()->assertJsonFragment(['entity' => 'dose_sessions']);
    }

    public function test_permissions_separate_each_write_and_new_treatment_reads(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        foreach (['create', 'update', 'activate', 'status', 'schedule', 'administer', 'dispense', 'correct', 'void'] as $action) {
            $permission = DB::table('permissions')->where('code', 'dossiers.treatment.'.$action)->value('id');
            DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
            [$method, $path, $data] = match ($action) {
                'create' => ['POST', $this->planPath(), $this->planData()],
                'update' => ['PUT', $this->planPath($p['id']), $this->planData(['lock_version' => 1])],
                'activate', 'status' => ['POST', $this->planPath($p['id']).'/status', ['lock_version' => 1, 'status' => $action === 'activate' ? 'active' : 'paused', 'reason' => 'قرار']],
                'schedule' => ['POST', $this->planPath($p['id']).'/sessions', ['lock_version' => 1, 'sessions' => [['session_number' => 3, 'planned_on' => '2090-03-01']]]],
                'administer' => ['POST', $this->path('/doses'), $this->dose($s)],
                'dispense' => ['POST', $this->path('/dispensing'), $this->item() + ['dose_session_id' => 1, 'dispensed_on' => '2001-03-02', 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'reporting_period_id' => $this->period, 'dispensing_purpose' => 'supportive']],
                'correct' => ['PUT', $this->path('/doses/1'), $this->dose($s, ['lock_version' => 1, 'reason' => 'تصحيح'])],
                'void' => ['POST', $this->path('/doses/1/void'), ['lock_version' => 1, 'session_lock_version' => 1, 'plan_lock_version' => 1, 'session_resolution' => 'cancelled', 'reason' => 'إبطال']],
            };
            $this->callApi($method, $path, $data)->assertForbidden();
            DB::table('role_permissions')->insert(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        }
        $permission = DB::table('permissions')->where('code', 'dossiers.treatment.view')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->callApi('GET', $this->planPath())->assertForbidden();
        $this->callApi('GET', '', ['treatment_status' => 'active'])->assertForbidden();
        $this->callApi('POST', '/export/xlsx', ['columns' => ['code', 'active_treatment_count']])->assertForbidden();
    }

    public function test_new_report_sections_preserve_scope_numeric_dates_and_text_cells(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated();
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $request = Request::create('/api/dossiers');
        $request->setUserResolver(fn () => $this->f['user']);
        foreach (['list', 'card', 'visit'] as $kind) {
            $doc = app(DossierReports::class)->document($request, $f, ['columns' => ['code', 'next_dose_on', 'active_treatment_count']], $kind === 'list' ? null : $this->s['id'], $kind === 'visit' ? $this->s['visit']['id'] : null);
            $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
            if ($kind === 'card') {
                $this->assertStringContainsString('2090-02-01', $json);
            }
            if ($kind === 'visit') {
                $this->assertStringNotContainsString('2090-02-01', $json);
                $this->assertStringContainsString('2001-03-02', $json);
            }
            $bytes = app(BloodBankReports::class)->xlsx($doc);
            $path = tempnam(sys_get_temp_dir(), 'oncology');
            file_put_contents($path, $bytes);
            try {
                $book = IOFactory::load($path);
                $this->assertGreaterThan(0, $book->getSheetCount());
                foreach ($book->getAllSheets() as $sheet) {
                    $this->assertTrue($sheet->getRightToLeft());
                    foreach ($sheet->getCellCollection()->getCoordinates() as $c) {
                        $this->assertNotSame('f', $sheet->getCell($c)->getDataType());
                    }
                }
                $book->disconnectWorksheets();
            } finally {
                unlink($path);
            }
        }
    }

    public function test_drafts_allow_pending_but_activation_requires_current_qualifying_evidence(): void
    {
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_pending'])->assertOk();
        $p = $this->makePlan();
        $this->activate($p)->assertUnprocessable();
        foreach (['not_assessed', 'pathology_required', 'referred_out'] as $state) {
            DB::table('visit_diagnostic_assessments')->where('visit_id', $this->s['visit']['id'])->update(['disposition' => $state, 'required_reason' => $state === 'pathology_required' ? 'مطلوب' : null]);
            $this->activate($p)->assertUnprocessable();
        }
        $this->assertDatabaseHas('oncology_plans', ['id' => $p['id'], 'status' => 'draft']);
    }

    public function test_override_requires_permission_reason_and_audited_responsible_physician(): void
    {
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_not_required', 'not_required_reason' => 'قرار مسجل'])->assertOk();
        $p = $this->makePlan();
        $permission = DB::table('permissions')->where('code', 'dossiers.treatment.override')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->activate($p, ['override_reason' => 'مبرر'])->assertForbidden();
        DB::table('role_permissions')->insert(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        $this->activate($p)->assertUnprocessable();
        $this->activate($p, ['override_reason' => 'مبرر لهذه الخطة'])->assertOk()->assertJsonPath('data.effective_status', 'active');
        $this->assertDatabaseHas('oncology_plan_revisions', ['plan_id' => $p['id'], 'doctor_id' => $this->f['workflow_doctors'][0]]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'oncology_plans', 'entity_id' => $p['id'], 'actor_id' => $this->f['user']->id]);
    }

    public function test_scheduling_creates_no_visits_and_has_traceable_status_history_and_next_dose(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $visits = DB::table('visits')->count();
        $doses = DB::table('dose_sessions')->count();
        $s = $this->schedule($p, '2090-01-01');
        $this->assertSame($visits, DB::table('visits')->count());
        $this->assertSame($doses, DB::table('dose_sessions')->count());
        $this->callApi('GET', $this->planPath())->assertOk()->assertJsonPath('next_dose.planned_on', '2090-01-01');
        $path = '/'.$this->s['id'].'/treatment-sessions/'.$s['id'];
        $this->callApi('PUT', $path, ['plan_lock_version' => $this->dose($s)['plan_lock_version'], 'lock_version' => 1, 'status' => 'rescheduled', 'planned_on' => '2090-03-01', 'reason' => 'طلب الطبيب'])->assertOk();
        $this->callApi('GET', $this->planPath())->assertOk()->assertJsonPath('next_dose.planned_on', '2090-02-01');
        $audit = DB::table('audit_logs')->where('entity_type', 'oncology_sessions')->where('entity_id', $s['id'])->orderByDesc('id')->first();
        $this->assertSame('2090-01-01', json_decode($audit->old_values, true)['planned_on']);
        foreach (['missed', 'cancelled', 'referred'] as $i => $status) {
            $this->callApi('PUT', $path, ['plan_lock_version' => $this->dose($s)['plan_lock_version'], 'lock_version' => $i + 2, 'status' => $status, 'reason' => 'سبب صريح'])->assertOk();
        }
        $this->callApi('PUT', $path, ['plan_lock_version' => $this->dose($s)['plan_lock_version'], 'lock_version' => 1, 'status' => 'cancelled', 'reason' => 'قديم'])->assertConflict();
    }

    public function test_actual_administration_and_dispensing_are_distinct_idempotent_facts(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $dispensed = DB::table('visit_medications')->count();
        $rx = DB::table('visit_prescriptions')->count();
        $input = $this->dose($s, ['request_id' => (string) Str::uuid()]);
        $id = $this->callApi('POST', $this->path('/doses'), $input)->assertCreated()->json('data.id');
        $this->callApi('POST', $this->path('/doses'), $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->callApi('POST', $this->path('/doses'), array_replace($input, ['note' => 'different']))->assertConflict();
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertConflict();
        $this->assertSame($dispensed, DB::table('visit_medications')->count());
        $this->assertSame($rx, DB::table('visit_prescriptions')->count());
        $this->assertDatabaseHas('dose_sessions', ['id' => $id, 'visit_id' => $this->s['visit']['id'], 'plan_revision_id' => $p['current_revision_id']]);
        $data = ['dose_session_id' => $id, 'dispensed_on' => '2001-03-02', 'reporting_period_id' => $this->period, 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'dispensing_purpose' => 'take_home'] + $this->item();
        $this->callApi('POST', $this->path('/dispensing'), $data)->assertCreated();
        $this->assertSame($dispensed + 1, DB::table('visit_medications')->count());
        $this->callApi('GET', $this->path('/doses'))->assertOk()->assertJsonCount(1, 'data.doses')->assertJsonCount(1, 'data.dispensed');
    }

    public function test_evidence_invalidation_preserves_administered_history_and_blocks_new_administration(): void
    {
        $evidence = $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $before = (array) DB::table('dose_sessions')->where('id', $id)->first();
        $this->callApi('POST', $this->path('/pathology/'.$evidence.'/void'), ['lock_version' => 1, 'void_reason' => 'سحب الدليل'])->assertOk();
        $this->callApi('GET', $this->planPath($p['id']))->assertOk()->assertJsonPath('data.effective_status', 'needs_review');
        $next = (array) DB::table('oncology_sessions')->where('plan_id', $p['id'])->where('session_number', 2)->first();
        $this->callApi('POST', $this->path('/doses'), $this->dose($next))->assertUnprocessable();
        $this->assertSame($before, (array) DB::table('dose_sessions')->where('id', $id)->first());
        $this->callApi('GET', $this->planPath())->assertJsonPath('next_dose', null);
    }

    public function test_revisions_preserve_prior_snapshots_and_require_explicit_review(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertCreated()->json('data.id');
        $version = DB::table('oncology_plans')->where('id', $p['id'])->value('lock_version');
        $this->callApi('PUT', $this->planPath($p['id']), $this->planData(['lock_version' => $version]))->assertUnprocessable();
        $next = $this->callApi('PUT', $this->planPath($p['id']), $this->planData(['lock_version' => $version, 'protocol_name' => 'نسخة ثانية', 'amendment_reason' => 'تعديل مدروس']))->assertOk()->assertJsonPath('data.status', 'needs_review')->assertJsonCount(2, 'data.revisions')->json('data');
        $this->assertDatabaseHas('dose_sessions', ['id' => $id, 'plan_revision_id' => $p['current_revision_id']]);
        $this->assertDatabaseHas('oncology_plan_revisions', ['id' => $p['current_revision_id'], 'protocol_name' => 'خطة اختبار =1+1']);
        $this->activate($next)->assertOk();
    }

    public function test_actual_dates_periods_permissions_and_cross_facility_are_enforced(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $s = $this->schedule($p);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['administered_on' => '2099-01-01']))->assertUnprocessable();
        $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['administered_on' => '2001-03-03']))->assertUnprocessable();
        DB::table('reporting_periods')->where('id', $this->period)->update(['status' => 'locked']);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertUnprocessable();
        $this->callApi('GET', $this->planPath(), ['facility_id' => $this->f['other']])->assertForbidden();
        DB::table('facility_user_roles')->insert(['user_id' => $this->f['user']->id, 'facility_id' => $this->f['other'], 'role_id' => $this->f['dossier_role']]);
        $this->callApi('GET', $this->planPath($p['id']), ['facility_id' => $this->f['other']])->assertNotFound();
        $permission = DB::table('permissions')->where('code', 'dossiers.treatment.administer')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->callApi('POST', $this->path('/doses'), $this->dose($s))->assertForbidden();
        $this->callApi('GET', $this->planPath())->assertOk();
    }

    public function test_composite_scope_constraints_and_populated_rollback_refuse_before_data_loss(): void
    {
        $p = $this->makePlan();
        try {
            DB::table('oncology_plan_revisions')->where('id', $p['current_revision_id'])->update(['facility_id' => $this->f['other']]);
            $this->fail('Expected composite FK');
        } catch (QueryException $e) {
            $this->assertSame(1452, (int) $e->errorInfo[1]);
        }
        $migration = require database_path('migrations/2026_09_20_000002_add_oncology_plans_and_sessions.php');
        try {
            $migration->down();
            $this->fail('Expected populated rollback refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must be preserved', $e->getMessage());
        }
        $this->assertDatabaseHas('oncology_plans', ['id' => $p['id']]);
    }

    public function test_radiotherapy_requires_explicit_actual_description_without_inventing_medication(): void
    {
        $this->ready();
        $p = $this->makePlan(['modality' => 'radiotherapy', 'items' => []]);
        $p = $this->activate($p)->assertOk()->json('data');
        $s = $this->schedule($p);
        $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['items' => []]))->assertUnprocessable();
        $id = $this->callApi('POST', $this->path('/doses'), $this->dose($s, ['items' => [], 'session_label' => 'جلسة شعاعية موثقة', 'note' => 'وصف اصطناعي لما أُجري فعليًا']))->assertCreated()->json('data.id');
        $this->assertSame(0, DB::table('dose_session_items')->where('dose_session_id', $id)->count());
    }

    public function test_plan_omissions_keep_snapshots_and_actual_dispensing_rejects_future_or_referral_only_visit(): void
    {
        $p = $this->makePlan(['note' => 'ملاحظة محفوظة']);
        $data = $this->planData(['lock_version' => $p['lock_version'], 'protocol_name' => 'تعديل بروتوكول مع حفظ البنود']);
        unset($data['items']);
        DB::table('medications')->where('id', $this->f['medication'])->update(['name_ar' => 'تسمية لاحقة']);
        $next = $this->callApi('PUT', $this->planPath($p['id']), $data)->assertOk()->assertJsonPath('data.revisions.0.note', 'ملاحظة محفوظة')->json('data');
        $this->assertSame($p['revisions'][0]['items'][0]['medication_name_snapshot'], $next['revisions'][0]['items'][0]['medication_name_snapshot']);
        $this->ready();
        $p = $this->activate($next)->assertOk()->json('data');
        $s = $this->schedule($p);
        $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome(['code' => 'DOS-REFER', 'referral_target' => 'جهة اختبار', 'outgoing_referral_date' => '2001-03-02', 'outgoing_referral_reason' => 'العلاج غير متاح'])])->assertOk();
        $input = $this->dose($s, ['visit_lock_version' => DB::table('visits')->where('id', $this->s['visit']['id'])->value('lock_version')]);
        $this->callApi('POST', $this->path('/doses'), $input)->assertUnprocessable();
        $dispense = $this->item() + ['dose_session_id' => 1, 'dispensed_on' => '2099-01-01', 'reporting_period_id' => $this->period, 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'dispensing_purpose' => 'supportive'];
        $this->callApi('POST', $this->path('/dispensing'), $dispense)->assertUnprocessable();
    }
}
