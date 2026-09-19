<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Catalog\CatalogBeneficiaries;
use App\Services\Catalog\CatalogQueries;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsOpenApi;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CatalogFixture::make();
        $this->token = CatalogFixture::token($this->f['user']);
    }

    private function api(string $method, string $path = '', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/service-catalog'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_openapi_responses_match_live_catalog_contracts(): void
    {
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        $create = $document['paths']['/api/service-catalog']['post']['requestBody']['content']['application/json']['schema']['anyOf'];
        $this->assertContains('category_id', $create[0]['required']);
        $this->assertNotContains('category_id', $create[1]['required']);
        foreach ($create as $schema) {
            $this->assertContains('kind', $schema['required']);
            $this->assertArrayNotHasKey('lock_version', $schema['properties']);
        }
        $update = $document['components']['schemas']['UpdateCatalogRequest'];
        $this->assertContains('lock_version', $update['required']);
        $this->assertArrayNotHasKey('kind', $update['properties']);
        $id = $this->f['items']['service'][1];
        foreach (['' => '/api/service-catalog', '/options' => '/api/service-catalog/options', '/classifications' => '/api/service-catalog/classifications',
            "/service/$id" => '/api/service-catalog/{kind}/{item}', "/service/$id/beneficiaries" => '/api/service-catalog/{kind}/{item}/beneficiaries',
            "/service/$id/history" => '/api/service-catalog/{kind}/{item}/history', "/service/$id/deletion-preview" => '/api/service-catalog/{kind}/{item}/deletion-preview'] as $suffix => $path) {
            $operation = $document['paths'][$path]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $body = $this->api('GET', $suffix)->assertOk()->json();
            $this->assertMatchesSchema($document, $operation['responses'][200]['content']['application/json']['schema'], $body);
            foreach ([401, 403, 404, 422, 500] as $status) {
                $this->assertArrayHasKey($status, $operation['responses']);
            }
        }
    }

    public static function kinds(): array
    {
        return [['service'], ['procedure'], ['medication']];
    }

    private function payload(string $kind, array $extra = []): array
    {
        return $extra + ['kind' => $kind, 'code' => '0009', 'name_ar' => 'تعريف جديد', 'description' => 'وصف', 'is_active' => true, ...($kind === 'service' ? ['category_id' => $this->f['category']] : ($kind === 'medication' ? ['strength' => '500mg', 'dosage_form' => 'tablet', 'default_unit' => 'قرص', 'reorder_level' => '10'] : []))];
    }

    #[DataProvider('kinds')]
    public function test_crud_unique_code_validation_versions_and_history(string $kind): void
    {
        $id = $this->api('POST', '', $this->payload($kind))->assertCreated()->assertJsonPath('data.patient_count', 0)->json('data.id');
        $this->api('POST', '', $this->payload($kind))->assertUnprocessable()->assertJsonValidationErrors('code');
        $excluded = $this->api('POST', '', $this->payload($kind, ['code' => 'ABC', 'name_ar' => 'غير مطابق']))->assertCreated()->json('data.id');
        $matches = $this->api('GET', '', ['kind' => $kind, 'search' => '0'])->assertOk()->json('data');
        $this->assertContains($id, array_column($matches, 'id'));
        $this->assertNotContains($excluded, array_column($matches, 'id'));
        $this->api('POST', '', $this->payload($kind, ['code' => str_repeat('a', 51), 'name_ar' => '']))->assertUnprocessable()->assertJsonValidationErrors(['code', 'name_ar']);
        $data = $this->payload($kind, ['lock_version' => 1, 'name_ar' => 'اسم معدل']);
        unset($data['kind']);
        $this->api('PUT', "/$kind/$id", $data)->assertOk()->assertJsonPath('data.lock_version', 2);
        $this->api('PUT', "/$kind/$id", $data)->assertConflict()->assertJsonPath('error.code', 'CATALOG_VERSION_CONFLICT');
        $this->api('PUT', "/$kind/$id", $data + ['kind' => 'procedure'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->api('GET', "/$kind/$id/history")->assertOk()->assertJsonPath('meta.total', 2);
        $this->api('GET', "/$kind/$id/deletion-preview")->assertOk()->assertJsonPath('data.action', 'delete');
        $this->api('DELETE', "/$kind/$id", ['lock_version' => 2])->assertNoContent();
        $this->assertDatabaseMissing(CatalogQueries::table($kind), ['id' => $id]);
    }

    public function test_distinct_beneficiaries_match_lists_and_exports_across_both_sources(): void
    {
        foreach (['service' => [2, [1, 2], '-001'], 'procedure' => [3, [1, 2, 8], '-001'], 'medication' => [3, [1, 2, 8], '-M01']] as $kind => [$count, $patientKeys, $suffix]) {
            $id = $this->f['items'][$kind][1];
            $this->api('GET', "/$kind/$id")->assertOk()->assertJsonPath('data.patient_count', $count)->assertJsonPath('data.patient_count_definition', CatalogBeneficiaries::DEFINITION);
            $patients = $this->api('GET', "/$kind/$id/beneficiaries")->assertOk()->assertJsonPath('meta.total', $count)->json('data');
            $this->assertEqualsCanonicalizing(array_values(array_intersect_key($this->f['patients'], array_flip($patientKeys))), array_column($patients, 'id'));
            $this->api('GET', "/$kind/$id/beneficiaries", ['search' => $this->f['tag'].'-P2'])->assertJsonPath('meta.total', 1);
            $this->api('GET', '/export/xlsx', ['kind' => $kind, 'search' => $this->f['tag'].$suffix, 'page' => 2, 'columns' => ['code', 'kind', 'patient_count']])->assertOk();
            $bytes = $this->api('GET', '/export/xlsx', ['kind' => $kind, 'search' => $this->f['tag'].$suffix, 'page' => 2, 'columns' => ['code', 'kind', 'patient_count']])->getContent();
            $path = tempnam(sys_get_temp_dir(), 'catalog-xlsx-');
            file_put_contents($path, $bytes);
            try {
                $sheet = IOFactory::load($path)->getActiveSheet();
                $this->assertSame($this->f['tag'].$suffix, $sheet->getCell('A9')->getValue());
                $this->assertSame('s', $sheet->getCell('A9')->getDataType());
                $this->assertSame($count, $sheet->getCell('C9')->getValue());
                $this->assertSame('n', $sheet->getCell('C9')->getDataType());
            } finally {
                unlink($path);
            }
            $this->api('GET', "/$kind/".$this->f['items'][$kind][2])->assertJsonPath('data.patient_count', 1);
            $this->api('GET', "/$kind/$id/report")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_transfusions_without_periods_keep_catalog_presentations_and_totals(): void
    {
        $before = [];
        foreach (['service', 'procedure'] as $kind) {
            $id = $this->f['items'][$kind][1];
            foreach (["/$kind/$id", "/$kind/$id/events", "/$kind/$id/beneficiaries"] as $path) {
                $before[$path] = $this->api('GET', $path)->assertOk()->json();
            }
        }
        $this->assertGreaterThan(0, DB::table('blood_transfusions')->where('facility_id', $this->f['facility'])->update(['reporting_period_id' => null]));
        foreach ($before as $path => $body) {
            $this->assertSame($body, $this->api('GET', $path)->assertOk()->json(), $path);
        }
    }

    #[DataProvider('kinds')]
    public function test_lifecycle_preserves_events_and_excludes_ineligible_new_choices(string $kind): void
    {
        $id = $this->f['items'][$kind][1];
        $before = DB::table('visit_'.$kind.'s')->where($kind.'_id', $id)->get()->all();
        $this->api('GET', "/$kind/$id/deletion-preview")->assertJsonPath('data.action', 'archive');
        $this->api('DELETE', "/$kind/$id", ['lock_version' => 1])->assertConflict()->assertJsonPath('error.code', 'CATALOG_REFERENCED');
        foreach (['deactivate', 'reactivate', 'archive', 'restore', 'reactivate'] as $i => $action) {
            $r = $this->api('POST', "/$kind/$id/$action", ['lock_version' => $i + 1])->assertOk()->assertJsonPath('data.lock_version', $i + 2);
            $r->assertJsonPath('data.is_active', $action === 'reactivate');
            $this->api('POST', "/$kind/$id/$action", ['lock_version' => $i + 1])->assertConflict();
            if ($action !== 'reactivate') {
                $choices = $this->api('GET', '/options', ['kind' => $kind, 'status' => 'archived'])->assertOk()->json('data');
                $this->assertNotContains($id, array_column($choices, 'id'));
            }
        }
        $this->assertEquals($before, DB::table('visit_'.$kind.'s')->where($kind.'_id', $id)->get()->all());
    }

    public function test_facility_privacy_capabilities_and_dynamic_permissions(): void
    {
        $viewer = CatalogFixture::token($this->f['viewer'], 'viewer');
        $id = $this->f['items']['service'][1];
        $this->api('GET', '', [], $viewer)->assertOk()->assertJsonPath('capabilities.update', false)->assertJsonPath('capabilities.beneficiaries', false);
        foreach (["/service/$id/beneficiaries", "/service/$id/history", "/service/$id/deletion-preview"] as $path) {
            $this->api('GET', $path, [], $viewer)->assertForbidden();
        }
        $this->api('POST', '', $this->payload('service'), $viewer)->assertForbidden();
        foreach (["/service/$id", "/service/$id/beneficiaries", '/export/xlsx'] as $path) {
            $this->api('GET', $path, ['facility_id' => $this->f['other']])->assertForbidden();
        }
        $this->api('POST', "/service/$id/archive", ['lock_version' => 1], $viewer)->assertForbidden();
        $role = DB::table('facility_user_roles')->where('user_id', $this->f['viewer']->id)->value('role_id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'catalog.directory.create')->value('id')]);
        $this->api('POST', '', $this->payload('service'), $viewer)->assertForbidden()->assertJsonPath('error.code', 'CATALOG_DIRECTORY_ACCESS_DENIED');
        DB::table('permissions')->where('code', 'catalog.view')->update(['is_active' => false]);
        $this->api('GET')->assertForbidden();
    }

    public function test_classifications_are_validated_but_existing_inactive_classification_is_retained(): void
    {
        $this->api('POST', '', $this->payload('service', ['category_id' => null]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        DB::table('service_categories')->where('id', $this->f['category'])->update(['is_active' => false]);
        $this->api('POST', '', $this->payload('service'))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        $data = $this->payload('service', ['code' => $this->f['tag'].'-001', 'lock_version' => 1]);
        unset($data['kind']);
        $this->api('PUT', '/service/'.$this->f['items']['service'][1], $data)->assertOk()->assertJsonPath('data.category_id', $this->f['category']);
        $this->api('POST', '', $this->payload('procedure', ['procedure_type_id' => PHP_INT_MAX]))->assertUnprocessable()->assertJsonValidationErrors('procedure_type_id');
    }

    public function test_authentication_validation_no_store_and_query_budget(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/service-catalog')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED')->assertHeader('Cache-Control', 'no-store, private');
        $bad = User::factory()->create()->createToken('no-ability', [])->plainTextToken;
        $this->api('GET', '', [], $bad)->assertForbidden();
        $this->api('GET', '', ['sort' => 'SQL', 'per_page' => 999])->assertUnprocessable();
        $this->f['user']->tokens()->update(['last_used_at' => now()]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api('GET')->assertOk();
        $small = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($n = 0; $n < 30; $n++) {
            DB::table('procedures')->insert(['code' => 'EXTRA-'.$n, 'name_ar' => 'إجراء إضافي']);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->api('GET', '', ['per_page' => 100])->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame($small, $large);
    }
}
