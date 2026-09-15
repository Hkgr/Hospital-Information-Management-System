<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReports;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionCase;

class DossierCompletionIOTest extends DossierCompletionCase
{
    public function test_private_upload_validation_reservation_retry_download_and_void_history(): void
    {
        Storage::fake('dossier_private');
        foreach (['../../secret.pdf', 'report.php.pdf', 'bad.svg', 'bad.html'] as $name) {
            $this->callApi('POST', $this->path('/uploads'), ['lock_version' => 1, 'title' => 'اختبار', 'original_filename' => $name])->assertUnprocessable();
        }
        $begin = ['lock_version' => 1, 'title' => 'مرفق اختبار خاص', 'original_filename' => 'clinical.png', 'request_id' => (string) Str::uuid()];
        $ticket = $this->callApi('POST', $this->path('/uploads'), $begin)->assertCreated()->json('data.upload_id');
        $this->callApi('POST', $this->path('/uploads'), $begin)->assertCreated()->assertJsonPath('data.upload_id', $ticket);
        $this->callApi('POST', $this->path('/review'), ['lock_version' => 1, 'confirmed' => true])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $this->app['auth']->forgetGuards();
        $url = '/api/dossiers'.$this->path('/uploads/'.$ticket).'?facility_id='.$this->f['facility'];
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->token];
        $this->post($url, ['file' => UploadedFile::fake()->createWithContent('clinical.png', '<html>not an image</html>')], $headers)->assertUnprocessable();
        $file = UploadedFile::fake()->image('clinical.png', 40, 40);
        $id = $this->post($url, ['file' => $file], $headers)->assertCreated()->json('data.attachment_id');
        $this->post($url, ['file' => $file], $headers)->assertCreated()->assertJsonPath('data.attachment_id', $id);
        $list = $this->callApi('GET', "/{$this->s['id']}/attachments", ['per_page' => 10])->assertOk()->assertJsonPath('meta.total', 1)->json('data.0');
        $this->assertArrayNotHasKey('storage_key', $list);
        $download = $this->callApi('GET', $this->path('/attachments/'.$id.'/download'))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment;', $download->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $download->headers->get('Cache-Control'));
        $this->callApi('GET', $this->path('/attachments/'.$id.'/download'), ['facility_id' => $this->f['other']])->assertForbidden();
        $this->s = $this->callApi('GET', $this->path('/progress'))->assertOk()->json('data');
        $row = DB::table('visit_attachments')->where('id', $id)->first();
        $this->callApi('POST', $this->path('/attachments/'.$id.'/void'), ['lock_version' => $this->s['visit']['lock_version'], 'attachment_lock_version' => 1, 'void_reason' => 'رفع غير مقصود'])->assertOk();
        $this->callApi('GET', $this->path('/attachments/'.$id.'/download'))->assertNotFound();
        Storage::disk('dossier_private')->assertExists($row->storage_key);
        $this->assertDatabaseHas('visit_attachments', ['id' => $id, 'void_reason' => 'رفع غير مقصود']);
        $this->assertCount(0, Storage::disk('dossier_private')->files('staging'));
    }

    public function test_export_all_filtered_rows_column_types_formula_safety_and_authorization(): void
    {
        DB::table('patients')->where('id', $this->s['patient']['id'])->update(['first_name' => '=HYPERLINK("https://invalid")', 'family_name' => '+اختبار']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $r = Request::create('/api/dossiers/export/xlsx', 'POST');
        $r->setUserResolver(fn () => $this->f['user']);
        $reports = app(DossierReports::class);
        $doc = $reports->document($r, $f, ['search' => $this->s['code']]);
        $this->assertCount(1, $doc['sections'][0]['rows']);
        $bytes = app(BloodBankReports::class)->xlsx($doc);
        $path = tempnam(storage_path('framework/testing'), 'dossier-xlsx');
        file_put_contents($path, $bytes);
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getSheet(0);
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('s', $sheet->getCell('D9')->getDataType());
            $this->assertStringStartsWith('=HYPERLINK', $sheet->getCell('D9')->getValue());
            foreach (['A9', 'H9', 'I9', 'K9'] as $cell) {
                $this->assertSame('n', $sheet->getCell($cell)->getDataType(), $cell);
            }
        } finally {
            unlink($path);
        }
        foreach (["/{$this->s['id']}/report/xlsx", $this->path('/report/xlsx'), '/export/xlsx'] as $endpoint) {
            $this->callApi('POST', $endpoint, ['search' => $this->s['code']])->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $pdf = $this->callApi('POST', $this->path('/report/pdf'))->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
        $detail = $reports->document($r, $f, [], $this->s['id'], $this->s['visit']['id']);
        $this->assertStringContainsString('مسودة — غير مكتملة', $detail['metadata']['title']);
        $this->assertStringContainsString('إضبارات المرضى', view('reports.blood-bank', $detail)->render());
        $this->token = $this->f['viewer']->createToken('no-export', ['api'])->plainTextToken;
        $this->callApi('POST', $this->path('/report/pdf'))->assertForbidden();
    }

    public function test_gets_do_not_write_and_scopes_optimistic_versions_and_permissions_are_enforced(): void
    {
        $before = [];
        foreach (['dossier_requests', 'dossier_section_progress', 'visits', 'visit_services', 'visit_prescriptions', 'visit_outcomes', 'audit_logs', 'number_sequences'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (["/{$this->s['id']}/progress", $this->path('/progress'), "/{$this->s['id']}", $this->path(), '/options', '/options/medications', "/{$this->s['id']}/attachments"] as $endpoint) {
            $this->callApi('GET', $endpoint)->assertOk();
        }
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
        $input = ['services' => [['catalog_id' => $this->f['service']] + $this->context()], 'procedures' => []];
        $this->saveSection('clinical', $input + ['facility_id' => $this->f['other']])->assertForbidden();
        $this->saveSection('clinical', $input + ['lock_version' => 999])->assertConflict();
        $bad = $input;
        $bad['services'][0]['doctor_id'] = $this->f['workflow_doctors'][1];
        $this->saveSection('clinical', $bad)->assertUnprocessable();
        $permission = DB::table('permissions')->where('code', 'dossiers.clinical.update')->value('id');
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', $permission)->delete();
        $this->saveSection('clinical', $input)->assertForbidden();
        $this->assertSame(0, DB::table('visit_services')->where('visit_id', $this->s['visit']['id'])->count());
    }
}
