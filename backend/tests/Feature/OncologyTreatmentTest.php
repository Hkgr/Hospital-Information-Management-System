<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReports;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Database\QueryException;
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

    private function schedule(array $p): array
    {
        $this->callApi('POST', $this->planPath($p['id']).'/sessions', ['lock_version' => $p['lock_version'], 'sessions' => [['session_number' => 1, 'planned_on' => '2090-01-01'], ['session_number' => 2, 'planned_on' => '2090-02-01']]])->assertCreated();

        return (array) DB::table('oncology_sessions')->where('plan_id', $p['id'])->orderBy('id')->first();
    }

    private function dose(array $s, array $extra = []): array
    {
        return $extra + ['session_id' => $s['id'], 'session_lock_version' => $s['lock_version'], 'plan_lock_version' => DB::table('oncology_plans')->where('id', $s['plan_id'])->value('lock_version'), 'visit_lock_version' => $this->s['visit']['lock_version'], 'administered_on' => '2001-03-02', 'reporting_period_id' => $this->period, 'supervising_staff_id' => $this->f['workflow_doctors'][0], 'administered_by' => $this->f['workflow_doctors'][0], 'items' => [$this->item()]];
    }

    public function test_multiple_plans_filtered_list_aggregates_do_not_issue_per_row_queries(): void
    {
        $this->ready();
        $p = $this->activate($this->makePlan())->assertOk()->json('data');
        $this->schedule($p);
        $other = $this->activate($this->makePlan())->assertOk()->json('data');
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
            $this->makePlan();
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
        $this->callApi('POST', $this->path('/doses/'.$id.'/void'), ['lock_version' => 2, 'reason' => 'تصحيح الإعطاء'])->assertOk();
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
                'void' => ['POST', $this->path('/doses/1/void'), ['lock_version' => 1, 'reason' => 'إبطال']],
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
        $s = $this->schedule($p);
        $this->assertSame($visits, DB::table('visits')->count());
        $this->assertSame($doses, DB::table('dose_sessions')->count());
        $this->callApi('GET', $this->planPath())->assertOk()->assertJsonPath('next_dose.planned_on', '2090-01-01');
        $path = '/'.$this->s['id'].'/treatment-sessions/'.$s['id'];
        $this->callApi('PUT', $path, ['lock_version' => 1, 'status' => 'rescheduled', 'planned_on' => '2090-03-01', 'reason' => 'طلب الطبيب'])->assertOk();
        $this->callApi('GET', $this->planPath())->assertOk()->assertJsonPath('next_dose.planned_on', '2090-02-01');
        $audit = DB::table('audit_logs')->where('entity_type', 'oncology_sessions')->where('entity_id', $s['id'])->orderByDesc('id')->first();
        $this->assertSame('2090-01-01', json_decode($audit->old_values, true)['planned_on']);
        foreach (['missed', 'cancelled', 'referred'] as $i => $status) {
            $this->callApi('PUT', $path, ['lock_version' => $i + 2, 'status' => $status, 'reason' => 'سبب صريح'])->assertOk();
        }
        $this->callApi('PUT', $path, ['lock_version' => 1, 'status' => 'cancelled', 'reason' => 'قديم'])->assertConflict();
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
        $data = $this->planData(['lock_version' => $p['lock_version']]);
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
