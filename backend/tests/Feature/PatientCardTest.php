<?php

namespace Tests\Feature;

use App\Services\Directory\DirectoryReport;
use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use App\Services\Dossiers\DossierWrites;
use App\Services\Dossiers\OncologyReports;
use App\Services\Dossiers\PatientCardInventory;
use App\Support\TestDatabaseSafety;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\DossierCompletionFixture;
use Tests\TestCase;

class PatientCardTest extends TestCase
{
    // An already migrated, populated isolated MariaDB. Never migrate:fresh.
    use DatabaseTransactions;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseSafety::assertAvailable($this->app);
        $this->f = DossierCompletionFixture::make();
        $this->token = $this->f['user']->createToken('card-test', ['api'])->plainTextToken;
    }

    private function input(array $extra = []): array
    {
        return $extra + ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'person_mode' => 'new',
            'code' => 'CARD-'.Str::random(12), 'opening_date' => '2000-01-01', 'visit_date' => '2001-03-02',
            'first_name' => 'أحمد', 'family_name' => 'محمد', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'];
    }

    private function callApi(string $method, string $path, array $body = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/dossiers'.$path, $body, ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_first_save_is_one_identity_context_and_genuine_draft_visit_and_replay(): void
    {
        $before = array_map(fn ($t) => DB::table($t)->count(), ['patients', 'patient_dossiers', 'visits']);
        $input = $this->input();
        $s = $this->callApi('POST', '', $input)->assertCreated()->json('data');
        $this->assertSame($input['code'], $s['patient']['patient_code']);
        $this->assertSame($s['code'], $s['patient']['patient_code']);
        $this->assertSame($s['patient']['id'], $s['card_id']);
        $this->assertNull(DB::table('patient_dossiers')->where('id', $s['id'])->value('code'));
        $this->assertSame('2001-03-02', $s['visit']['visit_date']);
        $this->assertSame('draft', $s['visit']['status']);
        $this->assertSame([], $s['visit']['diagnoses']);
        $this->assertSame('draft', $s['status']);
        $this->callApi('POST', '', $input)->assertCreated()->assertJsonPath('data.visit.id', $s['visit']['id']);
        foreach (['patients', 'patient_dossiers', 'visits'] as $i => $table) {
            $this->assertSame($before[$i] + 1, DB::table($table)->count());
        }
        $this->callApi('POST', '', array_replace($input, ['first_name' => 'آخر']))->assertConflict();
        $this->callApi('POST', '', array_replace($input, ['request_id' => (string) Str::uuid()]))->assertUnprocessable();
        $this->callApi('GET', "/{$s['id']}/progress?facility_id={$this->f['facility']}")->assertOk()->assertJsonPath('data.visit.id', $s['visit']['id']);
    }

    public function test_personal_data_stores_family_habits_and_displaced_home_address(): void
    {
        $s = $this->callApi('POST', '', $this->input([
            'displacement_status' => 'idp', 'permanent_address' => 'حمص، حي الإنشاءات',
            'marital_status' => 'married', 'occupation' => 'معلم', 'smoking_status' => 'former', 'alcohol_status' => 'no',
        ]))->assertCreated()->json('data');
        $this->assertSame('idp', $s['patient']['displacement_status']);
        $this->assertSame('حمص، حي الإنشاءات', $s['patient']['permanent_address']);
        $this->assertSame('married', $s['patient']['marital_status']);
        $this->assertSame('معلم', $s['patient']['occupation']);
        $this->assertSame('former', $s['patient']['smoking_status']);
        $this->assertSame('no', $s['patient']['alcohol_status']);
        $this->callApi('GET', "/{$s['id']}?facility_id={$this->f['facility']}")->assertOk()
            ->assertJsonPath('data.patient.permanent_address', 'حمص، حي الإنشاءات')
            ->assertJsonPath('data.patient.marital_status', 'married');
        $this->callApi('POST', '', $this->input(['marital_status' => 'engaged']))->assertUnprocessable()->assertJsonValidationErrors('marital_status');
        $update = array_diff_key($this->input([
            'code' => $s['code'], 'displacement_status' => 'resident', 'permanent_address' => 'يجب ألا تُحفظ',
            'marital_status' => 'widowed', 'occupation' => 'متقاعد', 'smoking_status' => 'yes', 'alcohol_status' => 'former',
        ]), array_flip(['person_mode', 'visit_date']));
        $update += ['lock_version' => $s['lock_version'], 'patient_lock_version' => $s['patient']['lock_version']];
        $saved = $this->callApi('PUT', "/{$s['id']}/personal", $update)->assertOk()->json('data');
        $this->assertSame('resident', $saved['patient']['displacement_status']);
        $this->assertNull($saved['patient']['permanent_address']);
        $this->assertSame('widowed', $saved['patient']['marital_status']);
        $this->assertSame('yes', $saved['patient']['smoking_status']);
        $this->assertSame('former', $saved['patient']['alcohol_status']);
    }

    public function test_medical_data_stores_weight_and_height(): void
    {
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        $saved = $this->callApi('PUT', "/{$s['id']}/medical", [
            'facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'lock_version' => $s['lock_version'],
            'is_oncology' => false, 'weight_kg' => 72.5, 'height_cm' => 168,
        ])->assertOk()->json('data');
        $this->assertEquals(72.5, (float) $saved['medical']['weight_kg']);
        $this->assertEquals(168, (float) $saved['medical']['height_cm']);
        $detail = $this->callApi('GET', "/{$s['id']}?facility_id={$this->f['facility']}")->assertOk()->json('data');
        $this->assertEquals(72.5, (float) $detail['weight_kg']);
        $this->assertEquals(168, (float) $detail['height_cm']);
        $this->callApi('PUT', "/{$s['id']}/medical", [
            'facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'lock_version' => $saved['lock_version'],
            'is_oncology' => false, 'weight_kg' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('weight_kg');
        $this->callApi('PUT', "/{$s['id']}/medical", [
            'facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'lock_version' => $saved['lock_version'],
            'is_oncology' => false, 'height_cm' => 300,
        ])->assertUnprocessable()->assertJsonValidationErrors('height_cm');
    }

    public function test_invalid_first_save_leaves_no_partial_records(): void
    {
        $counts = app(PatientCardInventory::class)->report();
        foreach ([['visit_date' => null], ['visit_date' => '2099-01-01'], ['first_name' => null]] as $bad) {
            $this->callApi('POST', '', $this->input($bad))->assertUnprocessable();
            $this->assertSame($counts, app(PatientCardInventory::class)->report());
        }
        // Failure after identity/context insertion must also roll the transaction back.
        DB::statement('SAVEPOINT before_injected_failure');
        $this->mock(DossierWrites::class, function ($mock) {
            $mock->makePartial()->shouldReceive('progress')->once()->andThrow(new \RuntimeException('synthetic failure'));
        });
        $this->callApi('POST', '', $this->input())->assertStatus(500);
        $this->assertSame($counts, app(PatientCardInventory::class)->report());
    }

    public function test_existing_identity_is_reused_across_facilities_with_isolated_medical_state(): void
    {
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        $other = $this->f['other'];
        $this->callApi('GET', "/{$s['id']}?facility_id=$other")->assertForbidden();
        DB::table('facility_user_roles')->insert(['user_id' => $this->f['user']->id, 'facility_id' => $other, 'role_id' => $this->f['dossier_role']]);
        $input = ['facility_id' => $other, 'person_mode' => 'existing', 'patient_id' => $s['patient']['id'], 'opening_date' => '2002-01-01', 'visit_date' => '2002-02-03', 'request_id' => (string) Str::uuid()];
        $before = DB::table('patients')->count();
        $b = $this->callApi('POST', '', $input)->assertCreated()->json('data');
        $this->assertSame($before, DB::table('patients')->count());
        $this->assertSame($s['card_id'], $b['card_id']);
        $this->assertSame($s['code'], $b['code']);
        $this->assertNotSame($s['id'], $b['id']); // local medical contexts, not two business cards
        $this->assertSame('draft', $b['status']);
        $this->assertNull($b['medical']['clinical_history']);
        $this->callApi('GET', "/{$s['id']}?facility_id=$other")->assertNotFound();
        $this->callApi('GET', "/{$b['id']}/visits/{$s['visit']['id']}?facility_id=$other")->assertNotFound();
        $this->callApi('POST', '', array_replace($input, ['request_id' => (string) Str::uuid()]))->assertConflict();
    }

    public function test_legacy_aliases_and_reports_use_one_canonical_code_without_rewriting_history(): void
    {
        $id = $this->f['dossiers'][0];
        $old = DB::table('patient_dossiers')->where('id', $id)->first();
        $canonical = DB::table('patients')->where('id', $old->patient_id)->value('patient_code');
        $this->callApi('GET', '?'.http_build_query(['facility_id' => $this->f['facility'], 'search' => $old->code]))->assertOk()->assertJsonPath('data.0.code', $canonical);
        $this->callApi('GET', "/$id?facility_id={$this->f['facility']}")->assertOk()->assertJsonPath('data.code', $canonical);
        $this->callApi('POST', '', $this->input(['code' => $old->code]))->assertUnprocessable();
        $r = Request::create('/');
        $r->setUserResolver(fn () => $this->f['user']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility']);
        foreach ([null, $id] as $reportId) {
            $doc = app(DossierReports::class)->document($r, $f, [], $reportId);
            $this->assertStringNotContainsString('إضبار', $doc['metadata']['title']);
            $this->assertStringNotContainsString($old->code, json_encode($doc, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame($old->code, DB::table('patient_dossiers')->where('id', $id)->value('code'));
    }

    public function test_new_registration_visit_cannot_be_deleted_or_detached(): void
    {
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        foreach (['delete', 'detach'] as $operation) {
            try {
                DB::transaction(function () use ($operation, $s) {
                    $q = DB::table('visits')->where('id', $s['visit']['id']);
                    $operation === 'delete' ? $q->delete() : $q->update(['dossier_id' => null]);
                });
                $this->fail('Registration visit protection missing');
            } catch (QueryException $e) {
                $this->assertContains($e->errorInfo[1], [1451, 4025]);
            }
        }
        $this->callApi('DELETE', "/{$s['id']}/visits/{$s['visit']['id']}", ['facility_id' => $this->f['facility']])->assertStatus(405);
    }

    public function test_reconciliation_is_read_only_and_reports_legacy_inconsistencies(): void
    {
        $before = app(PatientCardInventory::class)->report();
        $this->artisan('patients:reconcile-cards')->assertSuccessful();
        $this->assertSame($before, app(PatientCardInventory::class)->report());
        $this->assertGreaterThan(0, $before['contexts_without_visits']);
        $this->assertGreaterThan(0, $before['context_code_mismatches']);
    }

    public function test_ambiguous_alias_search_returns_explicit_candidates_without_merging_or_leaking_contexts(): void
    {
        $one = DB::table('patient_dossiers')->where('id', $this->f['dossiers'][0])->first();
        $two = $this->f['patients'][2];
        DB::table('patient_dossiers')->insert(['patient_id' => $two, 'facility_id' => $this->f['other'], 'code' => $one->code, 'opening_date' => '2000-01-01', 'entered_by' => $this->f['user']->id]);
        $before = app(PatientCardInventory::class)->report();
        $rows = $this->callApi('GET', '/options/patients?'.http_build_query(['facility_id' => $this->f['facility'], 'search' => $one->code]))->assertOk()->assertJsonPath('meta.total', 2)->json('data');
        $this->assertEqualsCanonicalizing([$one->patient_id, $two], array_column($rows, 'id'));
        foreach ($rows as $row) {
            $this->assertSame(DB::table('patients')->where('id', $row['id'])->value('patient_code'), $row['code']);
            $this->assertSame($this->f['facility'], DB::table('patient_dossiers')->where('id', $row['dossier_id'])->value('facility_id'));
        }
        $this->assertSame($before, app(PatientCardInventory::class)->report());
        $this->assertGreaterThan(0, $before['ambiguous_legacy_code_groups']);
    }

    public function test_registration_requires_visit_permission_and_personal_updates_cannot_change_code_or_visit(): void
    {
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        $update = array_diff_key($this->input(['code' => $s['code']]), array_flip(['person_mode', 'visit_date']));
        $update += ['lock_version' => $s['lock_version'], 'patient_lock_version' => $s['patient']['lock_version']];
        $this->callApi('PUT', "/{$s['id']}/personal", array_replace($update, ['code' => 'REPLACEMENT']))->assertUnprocessable();
        $this->callApi('PUT', "/{$s['id']}/personal", $update)->assertOk()->assertJsonPath('data.visit.id', $s['visit']['id'])->assertJsonPath('data.code', $s['code']);
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', DB::table('permissions')->where('code', 'dossiers.visits.create')->value('id'))->delete();
        $before = app(PatientCardInventory::class)->report();
        $this->callApi('POST', '', $this->input())->assertForbidden();
        $this->callApi('GET', '/options?facility_id='.$this->f['facility'])->assertOk()->assertJsonPath('data.creation.allowed', false);
        $this->assertSame($before, app(PatientCardInventory::class)->report());
    }

    public function test_additive_migration_preserves_legacy_and_refuses_unsafe_identity_or_rollback(): void
    {
        $migration = require database_path('migrations/2026_09_20_000001_protect_patient_card_registration_visit.php');
        $legacy = DB::table('patient_dossiers')->whereIn('id', $this->f['dossiers'])->get()->toJson();
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        try {
            $migration->down();
            $this->fail('Rollback must refuse protected registrations before DDL');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No data or schema was changed', $e->getMessage());
        }
        DB::table('patients')->where('id', $s['card_id'])->update(['patient_code' => ' ']);
        try {
            $migration->up();
            $this->fail('Invalid canonical identity must stop upgrade before DDL');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('operator review', $e->getMessage());
        }
        $this->assertSame($legacy, DB::table('patient_dossiers')->whereIn('id', $this->f['dossiers'])->get()->toJson());
        $this->assertSame($s['visit']['id'], DB::table('patient_dossiers')->where('id', $s['id'])->value('registration_visit_id'));
        $codes = require database_path('migrations/2026_09_20_000002_keep_context_codes_as_legacy_aliases.php');
        try {
            $codes->down();
            $this->fail('Rollback must not invent context codes');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('invented codes', $e->getMessage());
        }
    }

    public function test_all_workbooks_use_canonical_code_and_old_column_selection_has_one_text_column(): void
    {
        $s = $this->callApi('POST', '', $this->input(['code' => '=000123']))->assertCreated()->json('data');
        foreach (['/export/xlsx', "/{$s['id']}/report/xlsx", "/{$s['id']}/visits/{$s['visit']['id']}/report/xlsx"] as $path) {
            $bytes = $this->callApi('POST', $path, ['facility_id' => $this->f['facility'], 'search' => $s['code'], 'columns' => ['code', 'patient_code', 'name']])->assertOk()->getContent();
            $file = tempnam(storage_path('framework/testing'), 'card-xlsx-');
            file_put_contents($file, $bytes);
            try {
                $book = IOFactory::load($file);
                $found = 0;
                foreach ($book->getAllSheets() as $sheet) {
                    $this->assertTrue($sheet->getRightToLeft());
                    foreach ($sheet->getCoordinates() as $coordinate) {
                        $cell = $sheet->getCell($coordinate);
                        $this->assertNotSame('f', $cell->getDataType());
                        if ($cell->getValue() === $s['code']) {
                            $found++;
                            $this->assertSame('s', $cell->getDataType());
                        }
                    }
                }
                $this->assertGreaterThan(0, $found);
                $book->disconnectWorksheets();
            } finally {
                unlink($file);
            }
        }
    }

    public function test_patient_card_list_fields_and_selected_export_are_scoped_and_typed(): void
    {
        $s = $this->callApi('POST', '', $this->input(['code' => '000'.Str::random(10), 'mother_name' => '=SUM(1,1)', 'gender' => 'female', 'birth_date' => '1980-02-03', 'birth_date_accuracy' => 'exact', 'phone' => '00963900123456']))->assertCreated()->json('data');
        DB::table('patients')->where('id', $s['card_id'])->update(['paper_file_number' => '000072']);
        $query = '?'.http_build_query(['facility_id' => $this->f['facility'], 'search' => $s['code']]);
        $this->callApi('GET', $query)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.mother_name', '=SUM(1,1)')->assertJsonPath('data.0.gender', 'female')
            ->assertJsonPath('data.0.birth_date', '1980-02-03')->assertJsonPath('data.0.birth_date_accuracy', 'exact')
            ->assertJsonPath('data.0.phone', '00963900123456')->assertJsonPath('data.0.paper_file_number', '000072');
        $this->callApi('GET', "/{$s['id']}?facility_id={$this->f['facility']}")->assertOk()->assertJsonPath('data.patient.paper_file_number', '000072');
        $this->callApi('GET', "/{$s['id']}?facility_id={$this->f['other']}")->assertForbidden();
        // This fixture has card/export access, but no new treatment-view grant.
        $columns = array_keys(array_diff_key(DossierReports::COLUMNS, OncologyReports::COLUMNS));
        $bytes = $this->callApi('POST', '/export/xlsx', ['facility_id' => $this->f['facility'], 'search' => $s['code'], 'columns' => $columns])->assertOk()->getContent();
        $file = tempnam(storage_path('framework/testing'), 'card-columns-');
        file_put_contents($file, $bytes);
        try {
            $book = IOFactory::load($file);
            $sheet = $book->getSheet(0);
            $this->assertTrue($sheet->getRightToLeft());
            $this->assertSame('Cairo', $book->getDefaultStyle()->getFont()->getName());
            foreach (['code' => $s['code'], 'phone' => '00963900123456', 'paper_file_number' => '000072', 'mother_name' => '=SUM(1,1)'] as $key => $value) {
                $cell = $sheet->getCell([array_search($key, $columns) + 1, 9]);
                $this->assertSame($value, $cell->getValue());
                $this->assertSame('s', $cell->getDataType());
            }
            foreach (['birth_date', 'opening_date', 'latest_visit_date'] as $key) {
                $cell = $sheet->getCell([array_search($key, $columns) + 1, 9]);
                $this->assertSame('n', $cell->getDataType());
                $this->assertSame('yyyy-mm-dd', $cell->getStyle()->getNumberFormat()->getFormatCode());
            }
            $this->assertSame(1, $sheet->getPageSetup()->getFitToWidth());
            $this->assertSame(0, $sheet->getPageSetup()->getFitToHeight());
            $this->assertNotEmpty($sheet->getPageSetup()->getPrintArea());
            $this->assertNotEmpty($sheet->getFreezePane());
            $this->assertNotEmpty($sheet->getAutoFilter()->getRange());
            $book->disconnectWorksheets();
        } finally {
            unlink($file);
        }
        $this->callApi('POST', '/export/xlsx', ['facility_id' => $this->f['facility'], 'columns' => ['patient_code', 'code', 'paper_file_number']])->assertOk();
        $this->callApi('POST', '/export/xlsx', ['facility_id' => $this->f['facility'], 'columns' => ['password']])->assertUnprocessable();
    }

    public function test_birth_precision_is_not_upgraded_in_patient_card_reports(): void
    {
        $r = Request::create('/');
        $r->setUserResolver(fn () => $this->f['user']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility']);
        foreach (['year_only' => '1980 (السنة فقط)', 'estimated' => '1980-01-01 (تقديري)', 'unknown' => null] as $accuracy => $expected) {
            $s = $this->callApi('POST', '', $this->input(['birth_date' => $accuracy === 'unknown' ? null : '1980-01-01', 'birth_date_accuracy' => $accuracy]))->assertCreated()->json('data');
            $doc = app(DossierReports::class)->document($r, $f, ['search' => $s['code']]);
            $this->assertSame($expected, $doc['sections'][0]['rows'][0]['birth_date']);
            $this->assertSame([], $doc['sections'][0]['rows'][0]['_types']);
            $this->assertSame(DossierReports::DEFAULT_COLUMNS, $doc['columns']);
        }
    }

    public function test_individual_pdf_renders_one_canonical_code_and_the_new_title(): void
    {
        $s = $this->callApi('POST', '', $this->input())->assertCreated()->json('data');
        $this->mock(DirectoryReport::class, function ($mock) use ($s) {
            $mock->shouldReceive('pdf')->twice()->andReturnUsing(function ($doc, $view) use ($s) {
                $html = view($view, $doc)->render();
                $this->assertSame(1, substr_count($html, $s['code']));
                $this->assertStringNotContainsString('إضبارة', $html);
                $this->assertMatchesRegularExpression('/تقرير (بطاقة المريض|الزيارة)/u', $doc['metadata']['title']);

                // Inspect the actual HTML passed to the renderer, then generate a real PDF.
                return (new DirectoryReport)->pdf($doc, $view);
            });
        });
        foreach (["/{$s['id']}/report/pdf", "/{$s['id']}/visits/{$s['visit']['id']}/report/pdf"] as $path) {
            $response = $this->callApi('POST', $path, ['facility_id' => $this->f['facility']])->assertOk();
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }
}
