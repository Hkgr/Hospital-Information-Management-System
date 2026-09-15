<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use App\Services\Dossiers\DossierWrites;
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
            'code' => 'CARD-'.Str::random(12), 'opening_date' => '2000-01-01', 'visit_date' => '2001-03-02', 'visit_type_id' => $this->f['visit_type'],
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

    public function test_invalid_first_save_leaves_no_partial_records(): void
    {
        $counts = app(PatientCardInventory::class)->report();
        foreach ([['visit_date' => null], ['visit_date' => '2099-01-01'], ['visit_type_id' => 999999999], ['first_name' => null]] as $bad) {
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
        $input = ['facility_id' => $other, 'person_mode' => 'existing', 'patient_id' => $s['patient']['id'], 'opening_date' => '2002-01-01', 'visit_date' => '2002-02-03', 'visit_type_id' => $this->f['visit_type'], 'request_id' => (string) Str::uuid()];
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
        $update = array_diff_key($this->input(['code' => $s['code']]), array_flip(['person_mode', 'visit_date', 'visit_type_id']));
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
}
