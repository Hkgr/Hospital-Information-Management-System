<?php

namespace Tests\Feature;

use App\Services\Directory\DirectoryReferences;
use App\Services\Dossiers\DossierQueries;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\DossierFixture;
use Tests\TestCase;

class DossierApiTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierFixture::make();
        $this->token = $this->f['user']->createToken('dossier-test', ['api'])->plainTextToken;
    }

    private function api(string $path = '', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/dossiers'.$path.'?'.http_build_query($data + ['facility_id' => $this->f['facility']]), ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_latest_actual_date_ties_and_batched_diagnoses_do_not_multiply_rows(): void
    {
        DB::enableQueryLog();
        $rows = $this->api('', ['sort' => 'code', 'direction' => 'asc'])->assertOk()->assertJsonPath('meta.total', 10)->assertJsonPath('totals.dossiers', 10)->json('data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(15, $queries);
        $this->assertCount(10, array_unique(array_column($rows, 'id')));
        $this->assertSame($this->f['latest_visit'], $rows[0]['latest_visit_id']);
        $this->assertSame(3, $rows[0]['visit_count']);
        $this->assertCount(2, $rows[0]['diagnoses']);
        $this->assertNull($rows[0]['diagnoses'][0]['diagnosed_on']);
        $this->assertNotSame($rows[0]['diagnoses'][0]['clinic'], $rows[0]['diagnoses'][1]['clinic']);
        $id = $this->f['dossiers'][0];
        $d = $this->api('/'.$id)->assertOk()->json('data');
        $this->assertSame($this->f['latest_visit'], $d['latest_visit']['id']);
        $this->assertCount(1, $d['latest_visit']['services']);
        $this->assertCount(1, $d['latest_visit']['procedures']);
        $this->assertCount(1, $d['latest_visit']['medications']);
        $this->assertCount(1, $d['latest_visit']['administered_medications']);
        $this->assertCount(1, $d['latest_visit']['outcomes']);
        $this->assertCount(4, $d['oncology']['selections']);
        $this->api('/'.$id.'/visits')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.id', $this->f['latest_visit']);
        $this->api('/'.$id.'/visits/'.$this->f['old_visit'])->assertOk()->assertJsonPath('data.diagnoses', []);
    }

    public function test_search_names_codes_spacing_and_literal_wildcards_before_pagination(): void
    {
        foreach (['أحمد', 'محمد', 'أحمد محمد', '  أحمد    محمد  ', 'الطويل', $this->f['tag'].'-P1', 'DOS-'.$this->f['tag'].'-001'] as $search) {
            $this->assertContains($this->f['dossiers'][0], array_column($this->api('', ['search' => $search])->assertOk()->json('data'), 'id'));
        }
        $this->api('', ['search' => 'غير موجود أبدا'])->assertOk()->assertJsonPath('meta.total', 0);
        DB::table('patient_dossiers')->where('id', $this->f['dossiers'][1])->update(['code' => 'LIT%_\\!']);
        foreach (['%', '_', '\\', '!'] as $search) {
            $this->api('', ['search' => $search])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $this->f['dossiers'][1]);
        }
        $base = (array) DB::table('patients')->where('id', $this->f['patients'][10])->first();
        unset($base['id']);
        $base['patient_code'] .= '-extra';
        $p = DB::table('patients')->insertGetId($base);
        DB::table('patient_dossiers')->insert(['patient_id' => $p, 'facility_id' => $this->f['facility'], 'code' => 'DOS-extra', 'opening_date' => '1990-01-01', 'entered_by' => $this->f['user']->id, 'status' => 'active']);
        foreach ([1 => 10, 2 => 1] as $page => $count) {
            $this->assertCount($count, $this->api('', ['page' => $page, 'per_page' => 10])->assertOk()->assertJsonPath('meta.total', 11)->assertJsonPath('totals.dossiers', 11)->json('data'));
        }
        $this->api('', ['oncology' => 'yes'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('', ['visits' => 'without'])->assertOk()->assertJsonPath('meta.total', 10);
        $this->api('', ['to' => '2000-01-01'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('', ['from' => '2000-01-01'])->assertOk()->assertJsonPath('meta.total', 10);
        $this->api('', ['from' => '2010-01-01', 'to' => '2010-12-31'])->assertOk()->assertJsonPath('meta.total', 10);
        $this->api('', ['from' => '2010-01-01', 'to' => '2000-01-01'])->assertUnprocessable();
    }

    public function test_authorization_no_store_and_read_only_boundaries(): void
    {
        $id = $this->f['dossiers'][0];
        foreach (['', '/'.$id, '/'.$id.'/visits', '/'.$id.'/visits/'.$this->f['latest_visit']] as $path) {
            $this->api($path, [], '')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
            $this->api($path, ['facility_id' => $this->f['other']])->assertForbidden();
            $this->api($path, [], $this->f['viewer']->createToken('no-dossiers', ['api'])->plainTextToken)->assertForbidden();
        }
        $this->api('/'.$this->f['dossiers'][1].'/visits/'.$this->f['latest_visit'])->assertNotFound();
        $this->api('/abc')->assertNotFound();
        $this->api('/9999999')->assertNotFound();
        $this->api('', ['sort' => 'password'])->assertUnprocessable();
        $this->api('', [], $this->f['user']->createToken('no-ability', [])->plainTextToken)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/dossiers', ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token])->assertStatus(405);
        DB::table('facilities')->where('id', $this->f['facility'])->update(['is_active' => false]);
        $this->api()->assertForbidden();
    }

    public function test_current_patient_data_and_unlinked_history_are_preserved_without_inference(): void
    {
        $id = $this->f['dossiers'][1];
        $this->api('/'.$id)->assertOk()->assertJsonPath('data.latest_visit', null)->assertJsonPath('data.visit_count', 0);
        DB::table('patients')->where('id', $this->f['patients'][2])->update(['first_name' => 'اسم حالي جديد']);
        $this->api('/'.$id)->assertOk()->assertJsonPath('data.patient.first_name', 'اسم حالي جديد');
        foreach (['first_name', 'family_name', 'phone', 'birth_date', 'paper_file_number'] as $column) {
            $this->assertFalse(Schema::hasColumn('patient_dossiers', $column));
        }
        $this->assertGreaterThan(0, DB::table('blood_transfusions')->whereNotNull('patient_id')->count());
        $this->assertSame('archive', app(DirectoryReferences::class)->summary(false, $this->f['clinics'][0], $this->f['facility'])['action']);
    }

    public function test_constraints_scope_uniqueness_and_complete_dates(): void
    {
        $row = (array) DB::table('patient_dossiers')->where('id', $this->f['dossiers'][0])->first();
        unset($row['id']);
        foreach ([$row, array_replace($row, ['code' => 'another']), array_replace($row, ['patient_id' => $this->f['patients'][2]]), array_replace($row, ['patient_id' => $this->f['patients'][2], 'code' => 'invalid', 'opening_date' => '2020-00-00'])] as $bad) {
            try {
                DB::table('patient_dossiers')->insert($bad);
                $this->fail('Constraint must reject');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo);
            }
        }
        $other = DB::table('patient_dossiers')->insertGetId(array_replace($row, ['facility_id' => $this->f['other']]));
        $this->assertGreaterThan(0, $other);
        try {
            DB::table('visits')->where('id', $this->f['latest_visit'])->update(['dossier_id' => $other]);
            $this->fail('Cross-facility link accepted');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        try {
            DB::table('visits')->where('id', $this->f['latest_visit'])->update(['dossier_id' => $this->f['dossiers'][1]]);
            $this->fail('Cross-patient link accepted');
        } catch (QueryException $e) {
            $this->assertSame('23000', $e->getCode());
        }
        foreach (['2020-00-00', '2020-01-00', '2020-02-31'] as $date) {
            try {
                DB::table('patient_dossiers')->where('id', $this->f['dossiers'][0])->update(['opening_date' => $date]);
                $this->fail('Incomplete/invalid date accepted');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo);
            }
        }
        foreach ([['medication_source' => null, 'other_organization' => 'unexplained'], ['medication_source' => 'other_organization', 'other_organization' => null]] as $bad) {
            try {
                DB::table('patient_dossiers')->where('id', $this->f['dossiers'][0])->update($bad);
                $this->fail('Conditional organization constraint accepted');
            } catch (QueryException $e) {
                $this->assertNotEmpty($e->errorInfo);
            }
        }
    }

    public function test_draft_future_and_void_records_do_not_claim_actual_completed_care(): void
    {
        $id = $this->f['dossiers'][0];
        $row = (array) DB::table('visits')->where('id', $this->f['latest_visit'])->first();
        unset($row['id']);
        foreach (['draft', 'future', 'void'] as $kind) {
            $copy = array_replace($row, ['visit_no' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid()]);
            if ($kind === 'draft') {
                $copy['status'] = 'draft';
            }
            if ($kind === 'future') {
                $copy['visit_date'] = now()->addMonth()->toDateString();
            }
            if ($kind === 'void') {
                $copy = array_replace($copy, ['status' => 'void', 'voided_at' => now(), 'voided_by' => $this->f['user']->id, 'void_reason' => 'اختبار']);
            }
            DB::table('visits')->insert($copy);
        }
        $this->api('/'.$id)->assertOk()->assertJsonPath('data.visit_count', 3)->assertJsonPath('data.latest_visit.id', $this->f['latest_visit']);
        DB::table('patient_dossiers')->where('id', $id)->update(['status' => 'draft']);
        $this->api('/'.$id)->assertNotFound();
    }

    public function test_openapi_read_contracts_and_safe_internal_failure(): void
    {
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['/api/dossiers', '/api/dossiers/{dossier}', '/api/dossiers/{dossier}/visits', '/api/dossiers/{dossier}/visits/{visit}'] as $path) {
            $op = $doc['paths'][$path]['get'];
            $this->assertSame([['bearerAuth' => []]], $op['security']);
            foreach ([200, 401, 403, 404, 422, 500] as $status) {
                $this->assertArrayHasKey($status, $op['responses']);
            }
            $this->assertArrayNotHasKey('post', $doc['paths'][$path]);
        }
        $this->mock(DossierQueries::class)->shouldReceive('listing')->once()->andThrow(new \RuntimeException('SQL secret internal exception'));
        $r = $this->api()->assertStatus(500)->assertJsonPath('error.code', 'DOSSIERS_UNAVAILABLE')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('SQL secret', $r->getContent());
    }
}
