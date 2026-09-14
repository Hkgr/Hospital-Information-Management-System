<?php

namespace Tests\Feature;

use App\Http\Requests\BloodBank\SaveBloodProfile;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodBankReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankReportsTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = BloodBankFixture::make();
        $this->token = $this->f['user']->createToken('reports-test', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path, array $input = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/blood-bank'.$path, $input + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function profile(string $kind = 'donor', array $overrides = []): array
    {
        return $this->api('POST', '', $overrides + BloodBankFixture::profile($this->f, $kind))->assertCreated()->json('data');
    }

    private function document(?string $kind = null, ?int $id = null, ?int $donation = null, array $filters = []): array
    {
        $request = Request::create('/api/blood-bank');
        $request->setUserResolver(fn () => $this->f['user']);
        $facility = app(BloodBankAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');

        return app(BloodBankReports::class)->document($request, $facility, $filters, $kind, $id, $donation);
    }

    private function workbook(string $bytes)
    {
        $path = tempnam(storage_path('framework/testing'), 'xlsx');
        file_put_contents($path, $bytes);
        try {
            return IOFactory::load($path);
        } finally {
            unlink($path);
        }
    }

    public function test_list_report_uses_all_filtered_sorted_profiles_and_literal_search_with_text_cells(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->profile('donor', ['first_name' => '=اختبار%_', 'phone' => '0012345000', 'governorate_text' => 'عنوان يدوي', 'city_text' => 'مدينة يدوية']);
        }
        $this->profile('recipient');
        $filters = ['kind' => 'donor', 'search' => '%_', 'per_page' => 10, 'page' => 2, 'direction' => 'desc'];
        $list = $this->api('GET', '', $filters)->assertOk()->assertJsonPath('meta.total', 12)->json('data');
        $doc = $this->document(filters: $filters);
        $this->assertCount(12, $doc['sections'][0]['rows']);
        $this->assertSame($list[0]['code'], $doc['sections'][0]['rows'][10]['code']);
        $response = $this->api('GET', '/export/xlsx', $filters)->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $sheet = $this->workbook($response->getContent())->getActiveSheet();
        $this->assertSame(20, $sheet->getHighestRow());
        $this->assertSame('s', $sheet->getCell('B9')->getDataType());
        $this->assertStringStartsWith('=اختبار%_', $sheet->getCell('B9')->getValue());
        $this->assertSame('0012345000', $sheet->getCell('D9')->getValue());
        $this->assertSame('عنوان يدوي', $sheet->getCell('E9')->getValue());
        $this->assertTrue($sheet->getRightToLeft());
        $this->assertSame('A9', $sheet->getFreezePane());
        $pdf = $this->api('GET', '/export/pdf', $filters)->assertOk()->getContent();
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_profile_and_donation_reports_preserve_unknowns_status_only_and_actual_typed_events(): void
    {
        $p = $this->profile();
        $empty = $this->document('donor', $p['id']);
        $this->assertSame('لا توجد تبرعات مسجلة', $empty['sections'][2]['empty']);
        $this->assertCount(0, $empty['sections'][2]['rows']);
        DB::table('blood_bank_screenings')->where('donor_id', $p['id'])->where('analyte', 'HCV')->update(['status' => 'complete', 'result' => 'positive']);
        $d = $this->api('POST', '/donor/'.$p['id'].'/donations', ['request_id' => (string) Str::uuid(), 'donated_on' => $this->f['today'], 'blood_group' => 'AB', 'rh' => 'negative', 'units' => '1.2500'])->assertCreated()->json('data');
        $doc = $this->document('donor', $p['id']);
        $this->assertSame(['analyte' => 'HCV', 'status' => 'منجز'], $doc['sections'][1]['rows'][1]);
        $this->assertStringNotContainsString('positive', json_encode($doc));
        $this->assertCount(1, $doc['sections'][2]['rows']);
        foreach (['/donor/'.$p['id'].'/report/', '/donor/'.$p['id'].'/donations/'.$d['id'].'/report/'] as $path) {
            $bytes = $this->api('GET', $path.'xlsx')->assertOk()->getContent();
            $book = $this->workbook($bytes);
            $sheet = $book->getSheetByName('التبرعات');
            $this->assertSame($d['donation_code'], $sheet->getCell('A9')->getValue());
            $this->assertSame('s', $sheet->getCell('A9')->getDataType());
            $this->assertSame('n', $sheet->getCell('B9')->getDataType());
            $this->assertSame(1.25, $sheet->getCell('D9')->getValue());
            $this->assertStringStartsWith('%PDF-', $this->api('GET', $path.'pdf')->assertOk()->getContent());
        }
        DB::table('blood_donations')->where('id', $d['id'])->update(['voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'اختبار اصطناعي']);
        $this->assertStringContainsString('منها الملغى: 1', $this->document('donor', $p['id'])['sections'][2]['note']);
    }

    public function test_recipient_reads_linked_patient_current_address_without_copying_and_has_no_donations(): void
    {
        $input = BloodBankFixture::profile($this->f, 'recipient');
        foreach (SaveBloodProfile::PERSON as $key) {
            unset($input[$key]);
        }
        $p = $this->api('POST', '', ['person_mode' => 'patient', 'patient_id' => $this->f['patients'][2]] + $input)->assertCreated()->json('data');
        DB::table('patients')->where('id', $p['patient_id'])->update(['address_line' => 'عنوان مرجعي حديث', 'phone' => '000123']);
        $doc = $this->document('recipient', $p['id']);
        $values = array_column($doc['sections'][0]['rows'], 'value', 'field');
        $this->assertSame('عنوان مرجعي حديث', $values['العنوان']);
        $this->assertSame($p['patient_code'], $values['كود المريض المرتبط']);
        $this->assertCount(2, $doc['sections']);
        $this->assertDatabaseHas('blood_recipients', ['id' => $p['id'], 'address_line' => null]);
        $this->api('GET', '/recipient/'.$p['id'].'/report/pdf')->assertOk();
        $this->api('GET', '/recipient/'.$p['id'].'/report/xlsx')->assertOk();
    }

    public function test_exports_require_view_and_export_in_active_facility_and_scoped_record(): void
    {
        $p = $this->profile();
        foreach (['/export/pdf', '/donor/'.$p['id'].'/report/xlsx', '/donor/'.$p['id'].'/donations/999999/report/pdf'] as $path) {
            $this->api('GET', $path, ['facility_id' => $this->f['other']])->assertForbidden();
        }
        $this->api('GET', '/recipient/'.$p['id'].'/report/pdf')->assertNotFound();
        $this->api('GET', '/donor/'.$p['id'].'/donations/999999/report/pdf')->assertNotFound();
        $this->token = $this->f['viewer']->createToken('reports-view-only', ['api'])->plainTextToken;
        $this->api('GET', '/export/pdf')->assertForbidden();
        $this->api('GET', '/donor/'.$p['id'].'/report/xlsx')->assertForbidden();
        $this->token = $this->f['user']->createToken('reports-again', ['api'])->plainTextToken;
        DB::table('permissions')->where('code', 'blood_bank.view')->update(['is_active' => false]);
        $this->api('GET', '/export/pdf')->assertForbidden();
    }

    public function test_over_limit_returns_explicit_error_without_partial_export(): void
    {
        $p = $this->profile();
        $row = (array) DB::table('blood_donors')->find($p['id']);
        unset($row['id']);
        $copies = [];
        for ($i = 0; $i < 1000; $i++) {
            $copies[] = array_replace($row, ['donor_code' => 'SYNTHETIC-LIMIT-'.$i]);
        }
        foreach (array_chunk($copies, 100) as $chunk) {
            DB::table('blood_donors')->insert($chunk);
        }
        foreach (['pdf', 'xlsx'] as $format) {
            $this->api('GET', '/export/'.$format)->assertUnprocessable()->assertJsonPath('error.code', 'EXPORT_LIMIT_EXCEEDED');
        }
        $this->api('GET', '/export/xlsx', ['search' => $p['code']])->assertOk();
    }
}
