<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionCase;
use Tests\Support\DossierWorkbookAssertions;

class DossierPathologyTest extends DossierCompletionCase
{
    public function test_assessment_transitions_normalize_stored_and_returned_fields(): void
    {
        $evidence = $this->callApi('POST', $this->path('/pathology'), $this->report())->assertCreated()->json('data.id');
        $path = $this->path('/diagnostic-assessment');
        $this->callApi('PUT', $path, ['lock_version' => 0, 'disposition' => 'pathology_not_required', 'not_required_reason' => 'OLD-NOT-REQUIRED', 'note' => 'generic note', 'follow_up' => 'generic follow up'] + $this->context())->assertOk();
        $steps = [
            ['pathology_required', ['required_reason' => 'continuing request'], ['required_reason' => 'continuing request', 'not_required_reason' => null, 'evidence_pathology_id' => null]],
            ['pathology_pending', [], ['required_reason' => 'continuing request', 'not_required_reason' => null, 'evidence_pathology_id' => null]],
            ['pathology_confirmed', ['evidence_pathology_id' => $evidence], ['required_reason' => 'continuing request', 'not_required_reason' => null, 'evidence_pathology_id' => $evidence]],
            ['pathology_not_required', ['not_required_reason' => 'NEW-NOT-REQUIRED'], ['required_reason' => null, 'not_required_reason' => 'NEW-NOT-REQUIRED', 'evidence_pathology_id' => null]],
            ['not_assessed', ['required_reason' => 'INCOMPATIBLE-REASON', 'not_required_reason' => 'INCOMPATIBLE-REASON', 'evidence_pathology_id' => $evidence], ['required_reason' => null, 'not_required_reason' => null, 'evidence_pathology_id' => null]],
        ];
        foreach ($steps as $i => [$disposition, $fields, $expected]) {
            if ($disposition === 'pathology_confirmed') {
                $this->callApi('PUT', $path, ['lock_version' => $i + 1, 'disposition' => $disposition, 'evidence_pathology_id' => 999999999])->assertUnprocessable();
            }
            $response = $this->callApi('PUT', $path, ['lock_version' => $i + 1, 'disposition' => $disposition] + $fields)->assertOk();
            foreach ($expected + ['note' => 'generic note', 'follow_up' => 'generic follow up'] + $this->context() as $field => $value) {
                $response->assertJsonPath('data.'.$field, $value);
            }
            $this->assertDatabaseHas('visit_diagnostic_assessments', ['visit_id' => $this->s['visit']['id'], 'disposition' => $disposition] + $expected);
        }
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $request = Request::create('/testing', 'POST');
        $request->setUserResolver(fn () => $this->f['user']);
        $doc = app(DossierReports::class)->document($request, $f, [], $this->s['id']);
        $html = view('reports.blood-bank', $doc)->render();
        foreach (['OLD-NOT-REQUIRED', 'NEW-NOT-REQUIRED', 'INCOMPATIBLE-REASON', 'continuing request'] as $stale) {
            $this->assertStringNotContainsString($stale, $html);
        }
        $this->callApi('POST', '/'.$this->s['id'].'/report/pdf')->assertOk();
        $file = tempnam(storage_path('framework/testing'), 'normalized-');
        file_put_contents($file, $this->callApi('POST', '/'.$this->s['id'].'/report/xlsx')->assertOk()->getContent());
        try {
            $book = IOFactory::load($file);
            DossierWorkbookAssertions::check($book, false);
            foreach ($book->getAllSheets() as $sheet) {
                $text = json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE);
                foreach (['OLD-NOT-REQUIRED', 'NEW-NOT-REQUIRED', 'INCOMPATIBLE-REASON', 'continuing request'] as $stale) {
                    $this->assertStringNotContainsString($stale, $text);
                }
            }
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
    }

    public function test_pathology_normalization_and_completed_correction_cannot_downgrade(): void
    {
        foreach (['unavailable', 'cancelled'] as $status) {
            $id = $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'external', 'status' => $status, 'external_organization' => 'old organization', 'unavailable_reason' => 'old reason', 'result_on' => '1999-01-01', 'conclusion' => 'HIDDEN-FINAL', 'note' => 'keep note']))->assertCreated()->json('data.id');
            $this->callApi('PUT', $this->path('/pathology/'.$id), $this->case(['source' => 'internal', 'status' => 'requested', 'lock_version' => 1]))->assertOk();
            $expected = ['external_organization' => null, 'unavailable_reason' => null, 'result_on' => null, 'conclusion' => null, 'note' => 'keep note'] + $this->context();
            $read = $this->callApi('GET', $this->path('/pathology/'.$id))->assertOk();
            foreach ($expected as $field => $value) {
                $read->assertJsonPath('data.'.$field, $value);
            }
            $this->assertDatabaseHas('visit_pathologies', ['id' => $id] + $expected);
        }
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->case(['source' => 'internal', 'status' => 'completed', 'lock_version' => 2, 'result_on' => '2001-03-03', 'conclusion' => 'final']))->assertOk();
        $before = DB::table('audit_logs')->where('entity_type', 'visit_pathologies')->where('entity_id', $id)->count();
        foreach (['requested', 'pending_result', 'unavailable', 'cancelled'] as $status) {
            $this->callApi('PUT', $this->path('/pathology/'.$id), $this->case(['source' => 'internal', 'status' => $status, 'lock_version' => 3, 'unavailable_reason' => 'not a void']))->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        $this->assertSame($before, DB::table('audit_logs')->where('entity_type', 'visit_pathologies')->where('entity_id', $id)->count());
        $this->assertDatabaseHas('visit_pathologies', ['id' => $id, 'lock_version' => 3, 'status' => 'completed', 'conclusion' => 'final']);
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->case(['source' => 'internal', 'status' => 'completed', 'lock_version' => 3, 'result_on' => '2001-03-04', 'conclusion' => 'audited correction']))->assertOk();
        $audit = DB::table('audit_logs')->where('entity_type', 'visit_pathologies')->where('entity_id', $id)->orderByDesc('id')->first();
        $this->assertSame('final', json_decode($audit->old_values, true)['conclusion']);
        $this->assertSame('audited correction', json_decode($audit->new_values, true)['conclusion']);
    }

    public function test_known_dates_are_relative_to_visit_except_historical_external_reports(): void
    {
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'not_assessed', 'assessed_on' => '2001-03-01'])->assertUnprocessable()->assertJsonValidationErrors('assessed_on');
        foreach (['requested_on', 'collected_on', 'result_on'] as $field) {
            $data = $this->case(['source' => 'internal', 'status' => $field === 'result_on' ? 'completed' : 'requested', $field => '2001-03-01', 'conclusion' => 'result']);
            $this->callApi('POST', $this->path('/pathology'), $data)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->callApi('POST', $this->path('/pathology'), array_replace($data, [$field => '2099-01-01']))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'not_assessed', 'assessed_on' => '2099-01-01'])->assertUnprocessable()->assertJsonValidationErrors('assessed_on');
        $this->callApi('POST', $this->path('/pathology'), $this->report(['requested_on' => '1998-01-01', 'collected_on' => '1998-02-01']))->assertCreated();
        $this->callApi('POST', $this->path('/pathology'), $this->report(['requested_on' => '2000-01-01']))->assertUnprocessable()->assertJsonValidationErrors('result_on');
        DB::table('visits')->where('id', $this->s['visit']['id'])->update(['status' => 'complete', 'attending_staff_id' => $this->f['workflow_doctors'][0]]);
        $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'internal', 'status' => 'completed', 'result_on' => '2001-03-04', 'conclusion' => 'نتيجة وصلت بعد اكتمال الزيارة']))->assertCreated();
    }

    public function test_visit_date_correction_cannot_invalidate_saved_internal_dates(): void
    {
        $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'internal', 'status' => 'requested', 'requested_on' => '2001-03-02']))->assertCreated();
        $this->callApi('PUT', $this->path(), $this->visit(['visit_date' => '2001-03-03', 'lock_version' => $this->s['visit']['lock_version']]))->assertUnprocessable()->assertJsonValidationErrors('requested_on');
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id'], 'visit_date' => '2001-03-02']);
    }

    public function test_direct_writes_cannot_store_mutually_exclusive_fields(): void
    {
        $id = $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'internal', 'status' => 'requested']))->assertCreated()->json('data.id');
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'not_assessed'])->assertOk();
        foreach ([['visit_pathologies', ['id' => $id], ['external_organization' => 'invalid']], ['visit_pathologies', ['id' => $id], ['unavailable_reason' => 'invalid']], ['visit_pathologies', ['id' => $id], ['result_on' => '2001-03-02']], ['visit_pathologies', ['id' => $id], ['conclusion' => 'invalid']], ['visit_diagnostic_assessments', ['visit_id' => $this->s['visit']['id']], ['not_required_reason' => 'invalid']], ['visit_diagnostic_assessments', ['visit_id' => $this->s['visit']['id']], ['required_reason' => 'invalid']], ['visit_diagnostic_assessments', ['visit_id' => $this->s['visit']['id']], ['evidence_pathology_id' => $id]]] as [$table, $where, $values]) {
            try {
                DB::table($table)->where($where)->update($values);
                $this->fail('Expected mutually exclusive state constraint: '.json_encode($values));
            } catch (QueryException $e) {
                $this->assertSame(4025, (int) $e->errorInfo[1]);
            }
        }
    }

    public function test_omitted_responsibility_is_preserved_but_clearing_the_pair_is_rejected(): void
    {
        $id = $this->callApi('POST', $this->path('/pathology'), $this->report())->assertCreated()->json('data.id');
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['lock_version' => 1, 'clinic_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('clinic_id');
        $this->assertDatabaseHas('visit_pathologies', ['id' => $id, 'lock_version' => 1] + $this->context());
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['lock_version' => 1, 'note' => 'تصحيح دون حذف المسؤول']))->assertOk();
        $this->assertDatabaseHas('visit_pathologies', ['id' => $id, 'lock_version' => 2] + $this->context());
        $this->callApi('POST', $this->path('/pathology'), ['source' => 'internal', 'status' => 'requested'])->assertUnprocessable()->assertJsonValidationErrors(['clinic_id', 'doctor_id']);
    }

    public function test_attachment_and_procedure_scope_database_constraints_and_completed_visit_corrections(): void
    {
        $v = $this->s['visit']['id'];
        $file = DB::table('visit_attachments')->insertGetId(['facility_id' => $this->f['facility'], 'dossier_id' => $this->s['id'], 'visit_id' => $v, 'title' => 'وثيقة اصطناعية', 'original_filename' => 'report.pdf', 'storage_key' => 'files/'.Str::uuid(), 'mime_type' => 'application/pdf', 'extension' => 'pdf', 'size' => 50, 'sha256' => str_repeat('a', 64), 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->f['user']->id]);
        $id = $this->callApi('POST', $this->path('/pathology'), $this->report(['attachment_ids' => [$file]]))->assertCreated()->json('data.id');
        $this->callApi('GET', $this->path('/pathology/'.$id))->assertOk()->assertJsonPath('data.attachments.0.id', $file)->assertJsonMissingPath('data.attachments.0.storage_key');
        $this->callApi('POST', $this->path('/pathology'), $this->report(['attachment_ids' => [999999999]]))->assertUnprocessable();
        $otherVisit = (array) DB::table('visits')->where('id', $v)->first();
        unset($otherVisit['id']);
        $otherVisit['visit_no'] = 'V-'.Str::uuid();
        $otherVisit['client_request_id'] = (string) Str::uuid();
        $otherVisit['dossier_visit_kind'] = 'subsequent';
        $otherId = DB::table('visits')->insertGetId($otherVisit);
        $this->callApi('POST', '/'.$this->s['id'].'/visits/'.$otherId.'/pathology', $this->report(['attachment_ids' => [$file]]))->assertUnprocessable()->assertJsonValidationErrors('attachment_ids');
        $this->callApi('POST', $this->path('/pathology'), $this->report(['procedure_event_id' => 999999999]))->assertUnprocessable();
        foreach ([['facility_id' => $this->f['other']], ['patient_id' => 999999999], ['voided_at' => now()], ['status' => 'completed', 'conclusion' => null]] as $change) {
            try {
                DB::table('visit_pathologies')->where('id', $id)->update($change);
                $this->fail('Expected FK/check refusal');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [1451, 1452, 4025]);
            }
        }
        // Completion does not prevent an independently authorized late pathology result/correction.
        DB::table('visits')->where('id', $v)->update(['status' => 'complete', 'attending_staff_id' => $this->f['workflow_doctors'][0]]);
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['lock_version' => 1, 'conclusion' => 'تصحيح مدقق']))->assertOk();
        $this->assertDatabaseHas('pathology_attachments', ['pathology_id' => $id, 'attachment_id' => $file]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'visit_pathologies', 'entity_id' => $id]);
        $this->callApi('POST', $this->path('/pathology/'.$id.'/void'), ['lock_version' => 2, 'void_reason' => 'إلغاء مصحح'])->assertOk();
        $this->assertDatabaseHas('visit_attachments', ['id' => $file, 'voided_at' => null]);
    }

    public function test_reports_preserve_typed_full_filtered_pathology_and_supporting_privacy(): void
    {
        $id = $this->callApi('POST', $this->path('/pathology'), $this->report(['conclusion' => '=1+1']))->assertCreated()->json('data.id');
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_confirmed', 'evidence_pathology_id' => $id])->assertOk();
        foreach (['/'.$this->s['id'].'/report', $this->path('/report'), '/export'] as $path) {
            $filters = $path === '/export' ? ['search' => $this->s['code'], 'pathology_status' => 'pathology_confirmed', 'columns' => ['patient_code', 'pathology_status', 'visit_count']] : [];
            $this->callApi('POST', $path.'/pdf', $filters)->assertOk()->assertHeader('content-type', 'application/pdf');
            $bytes = $this->callApi('POST', $path.'/xlsx', $filters)->assertOk()->getContent();
            $file = tempnam(storage_path('framework/testing'), 'pathology-xlsx-');
            file_put_contents($file, $bytes);
            try {
                $book = IOFactory::load($file);
                $texts = [];
                foreach ($book->getAllSheets() as $sheet) {
                    $this->assertTrue($sheet->getRightToLeft());
                    foreach ($sheet->getCoordinates() as $coordinate) {
                        $cell = $sheet->getCell($coordinate);
                        $texts[] = (string) $cell->getValue();
                        $this->assertNotSame('f', $cell->getDataType());
                        if ($cell->getValue() === '0000123') {
                            $this->assertSame('s', $cell->getDataType());
                        }
                    }
                }
                $text = implode(' ', $texts);
                $this->assertStringContainsString('نتيجة', $text);
                if ($path !== '/export') {
                    $this->assertStringContainsString('0000123', $text);
                    $this->assertStringContainsString('=1+1', $text);
                }
                $this->assertStringNotContainsString('storage_key', $text);
                $book->disconnectWorksheets();
            } finally {
                unlink($file);
            }
        }
    }

    public function test_rollback_refuses_populated_new_facts_before_any_ddl_and_seeder_never_grants(): void
    {
        $this->callApi('POST', $this->path('/pathology'), $this->report())->assertCreated();
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        app(DossierPathologyPermissionsSeeder::class)->run();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $migration = require database_path('migrations/2026_09_20_000001_add_visit_pathology_workflow.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('must be preserved', $e->getMessage());
        }
        $this->assertDatabaseHas('visit_pathologies', ['visit_id' => $this->s['visit']['id']]);
        $this->assertTrue(Schema::hasTable('pathology_attachments'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        app(DossierPathologyPermissionsSeeder::class)->run();
        foreach (array_keys(DossierPathologyPermissionsSeeder::CODES) as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
    }

    private function report(array $extra = []): array
    {
        return array_replace($this->context(), ['source' => 'external', 'status' => 'completed', 'external_organization' => 'مختبر خارجي', 'result_on' => '1999-01-02', 'report_number' => '0000123', 'conclusion' => 'خلاصة محفوظة'], $extra);
    }

    private function case(array $extra = []): array
    {
        return array_replace($this->context(), $extra);
    }

    public function test_historical_external_report_replay_confirmation_void_history_and_atomic_conflict(): void
    {
        $patients = DB::table('patients')->count();
        $services = DB::table('visit_services')->count();
        $input = $this->report(['request_id' => (string) Str::uuid()]);
        $id = $this->callApi('POST', $this->path('/pathology'), $input)->assertCreated()->json('data.id');
        $this->callApi('POST', $this->path('/pathology'), $input)->assertCreated()->assertJsonPath('data.id', $id);
        $this->callApi('POST', $this->path('/pathology'), array_replace($input, ['conclusion' => 'مختلف']))->assertConflict();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_confirmed', 'evidence_pathology_id' => $id, 'assessed_on' => '2001-03-02'])->assertOk()->assertJsonPath('data.effective_disposition', 'pathology_confirmed');
        $this->callApi('GET', '/'.$this->s['id'])->assertOk()->assertJsonPath('data.pathology_summary.disposition', 'pathology_confirmed');
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['lock_version' => 9, 'conclusion' => 'لا تحفظ']))->assertConflict();
        $this->assertDatabaseHas('visit_pathologies', ['id' => $id, 'conclusion' => 'خلاصة محفوظة', 'lock_version' => 1]);
        $this->callApi('POST', $this->path('/pathology/'.$id.'/void'), ['lock_version' => 1, 'void_reason' => 'تصحيح مرجع'])->assertOk();
        $this->callApi('GET', $this->path('/diagnostic-assessment'))->assertOk()->assertJsonPath('data.effective_disposition', 'not_assessed')->assertJsonPath('data.needs_review', true);
        $this->callApi('GET', '/'.$this->s['id'].'/pathology')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.void_reason', 'تصحيح مرجع');
        $this->assertSame($patients, DB::table('patients')->count());
        $this->assertSame($services, DB::table('visit_services')->count());
    }

    public function test_internal_progress_and_explicit_decisions_require_valid_evidence_and_reasons(): void
    {
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_not_required'])->assertUnprocessable();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_not_required', 'not_required_reason' => 'قرار الطبيب'])->assertOk();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 1, 'disposition' => 'pathology_required', 'required_reason' => 'لتحديد الخطة'])->assertOk();
        $id = $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'internal', 'status' => 'requested', 'requested_on' => '2001-03-02']))->assertCreated()->json('data.id');
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 2, 'disposition' => 'pathology_confirmed', 'evidence_pathology_id' => $id])->assertUnprocessable();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 2, 'disposition' => 'pathology_pending'])->assertOk();
        foreach (['specimen_collected', 'pending_result', 'completed'] as $i => $status) {
            $this->callApi('PUT', $this->path('/pathology/'.$id), $this->case(['lock_version' => $i + 1, 'source' => 'internal', 'status' => $status, 'collected_on' => '2001-03-03', 'result_on' => $status === 'completed' ? '2001-03-04' : null, 'conclusion' => $status === 'completed' ? 'نتيجة' : null]))->assertOk();
        }
        $this->callApi('GET', '/'.$this->s['id'])->assertOk()->assertJsonPath('data.pathology_summary.disposition', 'pathology_confirmed');
        $this->callApi('POST', $this->path('/pathology'), $this->report(['result_on' => '2099-01-01']))->assertUnprocessable();
        $this->callApi('POST', $this->path('/pathology'), $this->report(['collected_on' => '2001-03-01']))->assertUnprocessable();
    }

    public function test_unavailable_referral_uses_existing_outcome(): void
    {
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'referred_out'])->assertUnprocessable();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 0, 'disposition' => 'pathology_not_required', 'not_required_reason' => 'previous decision'])->assertOk();
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome(['code' => 'DOS-REFER', 'referral_target' => 'مشفى آخر', 'outgoing_referral_date' => '2001-03-02', 'outgoing_referral_reason' => 'التشريح غير متاح'])])->assertOk()->json('data');
        $this->callApi('POST', $this->path('/pathology'), $this->case(['source' => 'internal', 'status' => 'unavailable', 'unavailable_reason' => 'الفحص غير متاح']))->assertCreated();
        $this->callApi('PUT', $this->path('/diagnostic-assessment'), ['lock_version' => 1, 'disposition' => 'referred_out'])->assertOk()->assertJsonPath('data.not_required_reason', null)->assertJsonPath('data.evidence_pathology_id', null);
        $this->assertDatabaseHas('visit_diagnostic_assessments', ['visit_id' => $this->s['visit']['id'], 'disposition' => 'referred_out', 'not_required_reason' => null, 'evidence_pathology_id' => null]);
        $this->assertSame(1, DB::table('visit_outcomes')->where('visit_id', $this->s['visit']['id'])->count());
    }

    public function test_patient_card_listing_succeeds_with_treatment_view(): void
    {
        $this->seed(OncologyPermissionsSeeder::class);
        foreach (DB::table('permissions')->whereIn('code', array_keys(OncologyPermissionsSeeder::CODES))->pluck('id') as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        }
        $this->callApi('POST', $this->path('/pathology'), $this->report())->assertCreated();
        $this->callApi('GET', '', ['search' => $this->s['code']])->assertOk()->assertJsonPath('totals.dossiers', 1)->assertJsonPath('data.0.treatment_count', 0);
    }

    public function test_permissions_scope_filters_and_bounded_queries(): void
    {
        $id = $this->callApi('POST', $this->path('/pathology'), $this->report())->assertCreated()->json('data.id');
        foreach (range(1, 12) as $i) {
            $this->callApi('POST', '', ['person_mode' => 'new', 'code' => 'PL-'.Str::random(12), 'opening_date' => '2001-01-01', 'visit_date' => '2001-03-02', 'first_name' => 'اختبار', 'family_name' => 'الاستعلامات', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'])->assertCreated();
        }
        DB::enableQueryLog();
        $this->callApi('GET', '', ['search' => $this->s['code'], 'pathology_status' => 'pathology_confirmed', 'per_page' => 10])->assertOk()->assertJsonPath('totals.dossiers', 1);
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $largerPage = $this->callApi('GET', '', ['per_page' => 100])->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(13, count($largerPage));
        $this->assertLessThanOrEqual($queries + 2, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->callApi('GET', '', ['search' => $this->s['code'], 'pathology_status' => 'not_assessed'])->assertOk()->assertJsonPath('totals.dossiers', 0);
        $this->callApi('GET', '/'.$this->s['id'].'/pathology', ['facility_id' => 999999])->assertForbidden();
        DB::table('facility_user_roles')->insert(['user_id' => $this->f['user']->id, 'facility_id' => $this->f['other'], 'role_id' => $this->f['dossier_role']]);
        $this->callApi('GET', '/'.$this->s['id'].'/pathology', ['facility_id' => $this->f['other']])->assertNotFound();
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['facility_id' => $this->f['other'], 'lock_version' => 1]))->assertNotFound();
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->whereIn('permission_id', DB::table('permissions')->whereIn('code', array_keys(DossierPathologyPermissionsSeeder::CODES))->select('id'))->delete();
        $this->callApi('GET', '/'.$this->s['id'].'/pathology')->assertOk();
        $this->callApi('POST', $this->path('/pathology'), $this->report())->assertForbidden();
        $this->callApi('PUT', $this->path('/pathology/'.$id), $this->report(['lock_version' => 1]))->assertForbidden();
        $this->callApi('POST', $this->path('/pathology/'.$id.'/void'), ['lock_version' => 1, 'void_reason' => 'سبب'])->assertForbidden();
    }
}
