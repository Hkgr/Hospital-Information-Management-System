<?php

namespace Tests\Feature;

use Database\Seeders\DossierCompletionPermissionsSeeder;
use Database\Seeders\DossierOutcomeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\DossierCompletionCase;

class DossierCompletionSafetyTest extends DossierCompletionCase
{
    public function test_definition_seeders_are_idempotent_without_grants_or_reactivation(): void
    {
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        $assignments = DB::table('global_user_roles')->orderBy('id')->get()->toJson();
        DB::table('permissions')->where('code', 'medications.create')->update(['is_active' => false]);
        foreach (range(1, 2) as $_) {
            (new DossierCompletionPermissionsSeeder)->run();
            (new DossierOutcomeSeeder)->run();
        }
        $this->assertSame($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertSame($assignments, DB::table('global_user_roles')->orderBy('id')->get()->toJson());
        $this->assertDatabaseHas('permissions', ['code' => 'medications.create', 'is_active' => false]);
    }

    public function test_historical_inactive_contexts_remain_but_new_rows_and_future_prescriptions_are_rejected(): void
    {
        $this->s = $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service']] + $this->context()], 'procedures' => []])->assertOk()->json('data');
        DB::table('clinics')->where('id', $this->f['clinics'][0])->update(['is_active' => false]);
        DB::table('staff')->where('id', $this->f['workflow_doctors'][0])->update(['is_active' => false]);
        DB::table('services')->where('id', $this->f['service'])->update(['is_active' => false]);
        $row = $this->s['clinical']['services'][0];
        $row['note'] = 'تصحيح ملاحظة دون مسح السياق التاريخي';
        $this->s = $this->saveSection('clinical', ['services' => [$row], 'procedures' => []])->assertOk()->assertJsonPath('data.clinical.services.0.note', $row['note'])->json('data');
        $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service']] + $this->context()], 'procedures' => []])->assertUnprocessable();
        $rx = $this->rx();
        $rx['prescribed_on'] = '2099-01-01';
        $this->saveSection('medications', ['prescription' => $rx, 'outcome' => null])->assertUnprocessable()->assertJsonValidationErrors('prescription.prescribed_on');
        $this->assertSame(0, DB::table('visit_prescriptions')->where('visit_id', $this->s['visit']['id'])->count());
    }

    public function test_a_late_invalid_row_rolls_back_all_rows_versions_and_progress(): void
    {
        $before = DB::table('visits')->where('id', $this->s['visit']['id'])->first();
        $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service']] + $this->context(), ['catalog_id' => $this->f['service'], 'clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][1]]], 'procedures' => []])->assertUnprocessable();
        $this->assertSame(0, DB::table('visit_services')->where('visit_id', $this->s['visit']['id'])->count());
        $this->assertEquals($before, DB::table('visits')->where('id', $before->id)->first());
        $this->assertDatabaseMissing('dossier_section_progress', ['visit_id' => $before->id, 'section' => 'clinical']);
    }

    public function test_upload_size_excel_types_scope_pagination_and_cancelled_tickets(): void
    {
        Storage::fake('dossier_private');
        $ticket = $this->callApi('POST', $this->path('/uploads'), ['lock_version' => $this->s['visit']['lock_version'], 'title' => 'حجم', 'original_filename' => 'large.pdf'])->assertCreated()->json('data.upload_id');
        config(['dossiers.attachment_max_kb' => 1]);
        $this->upload($ticket, UploadedFile::fake()->createWithContent('large.pdf', '%PDF-1.4'.str_repeat('a', 2048)))->assertUnprocessable();
        $this->callApi('POST', $this->path("/uploads/$ticket/cancel"))->assertNoContent();
        $this->upload($ticket, UploadedFile::fake()->createWithContent('large.pdf', '%PDF-1.4 test'))->assertConflict();
        config(['dossiers.attachment_max_kb' => 10240]);
        foreach (['xls' => 'Xls', 'xlsx' => 'Xlsx'] as $ext => $writer) {
            $book = new Spreadsheet;
            $book->getActiveSheet()->setCellValue('A1', 'بيانات اصطناعية');
            $path = tempnam(storage_path('framework/testing'), 'attachment');
            IOFactory::createWriter($book, $writer)->save($path);
            try {
                $ticket = $this->callApi('POST', $this->path('/uploads'), ['lock_version' => $this->s['visit']['lock_version'], 'title' => 'مصنف '.$ext, 'original_filename' => 'clinical.'.$ext])->assertCreated()->json('data.upload_id');
                $id = $this->upload($ticket, UploadedFile::fake()->createWithContent('clinical.'.$ext, file_get_contents($path)))->assertCreated()->json('data.attachment_id');
                $this->s = $this->callApi('GET', $this->path('/progress'))->assertOk()->json('data');
            } finally {
                unlink($path);
                $book->disconnectWorksheets();
            }
        }
        $row = (array) DB::table('visit_attachments')->where('id', $id)->first();
        unset($row['id']);
        for ($n = 0; $n < 9; $n++) {
            DB::table('visit_attachments')->insert(array_replace($row, ['storage_key' => 'files/'.Str::uuid(), 'client_request_id' => (string) Str::uuid(), 'extension' => 'png', 'mime_type' => 'image/png']));
        }
        $first = $this->callApi('GET', "/{$this->s['id']}/attachments", ['page' => 1, 'per_page' => 10])->assertOk()->assertJsonPath('meta.total', 11)->json('data.0.id');
        $next = $this->callApi('GET', "/{$this->s['id']}/attachments", ['page' => 2, 'per_page' => 10])->assertOk()->json('data.0.id');
        $this->assertNotSame($first, $next);
        $this->callApi('GET', "/{$this->s['id']}/attachments", ['extension' => 'xlsx', 'from' => '2001-03-02', 'to' => '2001-03-02'])->assertJsonPath('meta.total', 1);
        $this->token = $this->f['viewer']->createToken('attachment-denied', ['api'])->plainTextToken;
        $this->callApi('GET', $this->path("/attachments/$id/download"))->assertForbidden();
        $this->callApi('GET', "/{$this->s['id']}/attachments")->assertForbidden();
        $this->token = '';
        $this->callApi('GET', $this->path("/attachments/$id/download"))->assertUnauthorized();
    }

    private function upload(int $ticket, UploadedFile $file)
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/dossiers'.$this->path("/uploads/$ticket").'?facility_id='.$this->f['facility'], ['file' => $file], ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token]);
    }

    public function test_database_rejects_cross_facility_prescription_and_incomplete_void_metadata(): void
    {
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => null])->assertOk()->json('data');
        $id = $this->s['clinical']['prescription']['id'];
        foreach ([['facility_id' => $this->f['other']], ['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => null]] as $change) {
            try {
                DB::table('visit_prescriptions')->where('id', $id)->update($change);
                $this->fail('Expected database integrity rejection');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [1451, 1452, 4025]);
            }
        }
        $this->assertDatabaseHas('visit_prescriptions', ['id' => $id, 'facility_id' => $this->f['facility'], 'voided_at' => null]);
    }

    public function test_report_limits_are_explicit_and_openapi_describes_new_contracts(): void
    {
        config(['dossiers.report_detail_limit' => 0]);
        $this->callApi('POST', $this->path('/report/pdf'))->assertUnprocessable()->assertJsonValidationErrors('export');
        $schema = $this->getJson('/docs/api.json')->assertOk()->json('paths');
        $this->assertArrayHasKey('/api/dossiers/{dossier}/visits/{visit}/clinical', $schema);
        $complete = $schema['/api/dossiers/{dossier}/visits/{visit}/complete']['post'];
        $this->assertArrayHasKey('200', $complete['responses']);
        $this->assertStringContainsString('dossiers.finalize', $complete['description']);
        $med = $schema['/api/dossiers/medications']['post'];
        $this->assertStringContainsString('global medications.create', $med['description']);
        $this->assertArrayHasKey('/api/dossiers/{dossier}/visits/{visit}/attachments/{attachment}/download', $schema);
    }
}
