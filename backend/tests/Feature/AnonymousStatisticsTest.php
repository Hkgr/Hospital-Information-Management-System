<?php

namespace Tests\Feature;

use App\Services\Reports\AnonymousStatisticsReport;
use Database\Seeders\PermissionMatrixPhaseThreeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\StatisticsFixture;
use Tests\TestCase;

class AnonymousStatisticsTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = StatisticsFixture::make();
        $this->token = $this->f['users']['statistics']->createToken('statistics-test', ['api'])->plainTextToken;
    }

    private function report(string $format = '', array $input = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($format ? 'POST' : 'GET', '/api/statistics'.($format ? '/export/'.$format : ''), $input + ['facility_id' => $this->f['facility'], 'from_month' => '2020-01', 'to_month' => '2020-01'], ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_privacy_pooling_distinct_people_actual_dates_and_event_counts(): void
    {
        $data = $this->report()->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data');
        $sections = collect($data['months'][0]['sections'])->keyBy('key');
        $this->assertSame(11, $sections['status']['patients']);
        $this->assertSame(12, $sections['status']['events']);
        $this->assertSame(6, $sections['services']['patients']);
        $this->assertSame(7, $sections['services']['events']);
        $this->assertSame([['label' => 'فئات مجمّعة لحماية الخصوصية', 'patients' => 6, 'events' => 7]], $sections['services']['rows']);
        $this->assertSame(2, count($sections['gender']['rows']));
        foreach ($sections as $s) {
            foreach ($s['rows'] as $row) {
                $this->assertGreaterThanOrEqual(5, $row['patients']);
            }
        }
        $serialized = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (['SECRET', '0900999000', 'patient_id', 'dossier_id', 'birth_date', 'attachment', 'clinical_history', 'RARE-SERVICE'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $february = $this->report('', ['from_month' => '2020-02', 'to_month' => '2020-02'])->assertOk()->json('data.months.0.sections.0');
        $this->assertTrue($february['suppressed']);
        $this->assertNull($february['patients']);
        $this->assertNull($february['events']);
        $this->assertSame([], $february['rows']);
        $this->report('', ['from_month' => now()->format('Y-m'), 'to_month' => now()->format('Y-m')])->assertUnprocessable();
        $this->report('', ['clinic_id' => 1])->assertUnprocessable()->assertExactJson(['error' => ['code' => 'STATISTICS_FILTERS_INVALID', 'message' => 'الفلاتر المتقاطعة غير متاحة لحماية الخصوصية.']]);
        $this->report('', ['from_month' => '2018-01'])->assertUnprocessable();
    }

    public function test_permissions_are_separate_dynamic_and_never_grant_identified_reports(): void
    {
        $this->report('', ['facility_id' => $this->f['other']])->assertForbidden();
        foreach (['dossiers', 'reports?period=day', 'reception/patients?search=SECRET', 'users'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->getJson('/api/'.$path.(str_contains($path, '?') ? '&' : '?').'facility_id='.$this->f['facility'], ['Authorization' => 'Bearer '.$this->token])->assertForbidden();
        }
        $role = DB::table('roles')->where('code', 'statistics')->value('id');
        $permission = DB::table('permissions')->where('code', 'statistics.export')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        $this->report()->assertOk();
        $this->report('xlsx')->assertForbidden();
        DB::table('permissions')->where('id', $permission)->update(['is_active' => false]);
        $this->seed(PermissionMatrixPhaseThreeSeeder::class);
        $this->assertDatabaseHas('permissions', ['id' => $permission, 'is_active' => false]);
        $this->assertFalse(DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->exists());
    }

    public function test_exports_only_serialize_protected_aggregate_and_have_valid_print_settings(): void
    {
        $xlsx = $this->report('xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'stats-');
        file_put_contents($path, $xlsx->getContent());
        try {
            $book = IOFactory::load($path);
            $sheet = $book->getActiveSheet();
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('Cairo', $book->getDefaultStyle()->getFont()->getName());
            $this->assertSame(1, $book->getSheetCount());
            $this->assertSame('landscape', $sheet->getPageSetup()->getOrientation());
            $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            $this->assertSame('A9', $sheet->getFreezePane());
            $this->assertSame([8, 8], $sheet->getPageSetup()->getRowsToRepeatAtTop());
            $this->assertSame('n', $sheet->getCell('D9')->getDataType());
            $this->assertSame(11, $sheet->getCell('D9')->getValue());
            $this->assertSame('s', $sheet->getCell('A9')->getDataType());
            $zip = new \ZipArchive;
            $zip->open($path);
            $xml = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $xml .= $zip->getFromIndex($i);
            }
            foreach (['SECRET', '0900999000', 'patient_id', 'dossier_id', '<f>', 'externalLink', 'RARE-SERVICE'] as $secret) {
                $this->assertStringNotContainsString($secret, $xml);
            }
            $zip->close();
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $pdf = $this->report('pdf')->assertOk();
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertSame(2, DB::table('audit_logs')->where('facility_id', $this->f['facility'])->where('entity_type', 'anonymous_statistics')->count());
    }

    public function test_export_rechecks_permission_after_generation_before_sending_bytes(): void
    {
        $this->mock(AnonymousStatisticsReport::class)->shouldReceive('render')->once()->andReturnUsing(function () {
            DB::table('permissions')->where('code', 'statistics.export')->update(['is_active' => false]);

            return 'MUST-NOT-RELEASE';
        });
        $response = $this->report('pdf')->assertForbidden();
        $this->assertStringNotContainsString('MUST-NOT-RELEASE', $response->getContent());
        $this->assertSame(0, DB::table('audit_logs')->where('facility_id', $this->f['facility'])->where('entity_type', 'anonymous_statistics')->count());
    }

    public function test_actual_event_dates_voids_drafts_and_formula_labels_are_safe(): void
    {
        foreach (['diagnoses', 'procedures'] as $kind) {
            $id = DB::table($kind)->insertGetId(['code' => $kind.$this->f['tag'], 'name_ar' => '=HYPERLINK("https://invalid.test","formula")']);
            foreach (range(0, 6) as $n) {
                $row = ['facility_id' => $this->f['facility'], 'visit_id' => $this->f['visits'][$n], 'client_request_id' => (string) Str::uuid(), 'entered_by' => $this->f['users']['hospital_admin']->id];
                $row += $kind === 'diagnoses'
                    ? ['diagnosis_id' => $id, 'diagnosing_staff_id' => $this->f['doctor'], 'diagnosed_on' => $n % 2 ? '2020-01-01' : '2020-01-31']
                    : ['procedure_id' => $id, 'specialist_id' => $this->f['doctor'], 'performed_on' => $n % 2 ? '2020-01-01' : '2020-01-31'];
                if ($n === 6) {
                    $row += ['voided_at' => now(), 'voided_by' => $this->f['users']['hospital_admin']->id, 'void_reason' => 'SECRET-VOID'];
                }
                DB::table('visit_'.$kind)->insert($row);
                $draft = $row;
                $draft['visit_id'] = $this->f['visits'][10];
                $draft['client_request_id'] = (string) Str::uuid();
                DB::table('visit_'.$kind)->insert($draft);
            }
        }
        $sections = collect($this->report()->assertOk()->json('data.months.0.sections'))->keyBy('key');
        foreach (['diagnoses', 'procedures'] as $kind) {
            $this->assertSame(6, $sections[$kind]['patients']);
            $this->assertSame(6, $sections[$kind]['events']);
        }
        $xlsx = $this->report('xlsx')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'stats-safe');
        file_put_contents($path, $xlsx->getContent());
        try {
            $sheet = IOFactory::load($path)->getActiveSheet();
            $found = 0;
            foreach ($sheet->getRowIterator() as $row) {
                foreach ($row->getCellIterator() as $cell) {
                    if (str_starts_with((string) $cell->getValue(), '=HYPERLINK')) {
                        $found++;
                        $this->assertSame('s', $cell->getDataType());
                    }
                }
            }
            $this->assertSame(2, $found);
        } finally {
            unlink($path);
        }
    }

    public function test_fewer_than_five_distinct_people_stay_hidden_despite_many_events(): void
    {
        DB::table('visits')->where('facility_id', $this->f['facility'])->whereNull('voided_at')->update(['patient_id' => $this->f['patients'][0]]);
        $section = $this->report()->assertOk()->json('data.months.0.sections.0');
        $this->assertTrue($section['suppressed']);
        $this->assertNull($section['patients']);
        $this->assertNull($section['events']);
        $this->assertSame([], $section['rows']);
    }

    public function test_seeders_refuse_unrelated_statistics_authority_without_silent_reassignment(): void
    {
        $role = DB::table('roles')->where('code', 'statistics')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'dossiers.view')->value('id')]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('operator review required');
        $this->seed(PermissionMatrixPhaseThreeSeeder::class);
    }

    public function test_openapi_exposes_closed_month_inputs_and_session_contract(): void
    {
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['/api/statistics' => 'get', '/api/statistics/export/{format}' => 'post'] as $path => $method) {
            $op = $doc['paths'][$path][$method];
            $names = array_column($op['parameters'], 'name');
            foreach (['facility_id', 'from_month', 'to_month'] as $field) {
                $this->assertContains($field, $names);
            }
            $this->assertStringContainsString('statistics.view', $op['description']);
        }
        $this->assertStringContainsString('cannot be revived', $doc['paths']['/api/session/activity']['post']['description']);
    }
}
