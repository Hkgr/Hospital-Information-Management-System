<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsOpenApi;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class CatalogWorkflowTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = CatalogFixture::make();
        $this->token = $this->f['user']->createToken('workflow', ['api'])->plainTextToken;
        config(['catalog.facility_code' => 'CAT-'.$this->f['tag']]);
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/service-catalog'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    public function test_designated_facility_fails_closed_without_any_fallback(): void
    {
        $this->api('GET', '/context')->assertOk()->assertJsonPath('data.facility.id', $this->f['facility'])->assertHeader('Cache-Control', 'no-store, private');
        config(['catalog.facility_code' => null]);
        $this->api('GET', '/context')->assertUnprocessable()->assertJsonPath('error.code', 'CATALOG_FACILITY_UNCONFIGURED');
        config(['catalog.facility_code' => 'OTHER-'.$this->f['tag']]);
        $this->api('GET', '/context')->assertForbidden();
        config(['catalog.facility_code' => 'CAT-'.$this->f['tag']]);
        DB::table('facilities')->where('id', $this->f['facility'])->update(['is_active' => false]);
        $this->api('GET', '/context')->assertForbidden();
    }

    public function test_category_creation_requires_global_authority_and_validates_unique_code(): void
    {
        $data = ['code' => 'NEW-CATEGORY', 'name_ar' => 'فئة جديدة', 'is_active' => true];
        $viewer = $this->f['viewer']->createToken('workflow', ['api'])->plainTextToken;
        $this->api('POST', '/categories', $data, $viewer)->assertForbidden();
        $role = DB::table('facility_user_roles')->where('user_id', $this->f['viewer']->id)->value('role_id');
        DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'catalog.directory.create')->value('id')]);
        $this->api('POST', '/categories', $data, $viewer)->assertForbidden()->assertJsonPath('error.code', 'CATALOG_DIRECTORY_ACCESS_DENIED');
        $this->api('POST', '/categories', $data + ['facility_id' => $this->f['other']])->assertForbidden();
        $id = $this->api('POST', '/categories', $data)->assertCreated()->assertJsonPath('data.is_active', true)->json('data.id');
        $this->assertDatabaseHas('service_categories', ['id' => $id] + $data);
        $this->assertContains($id, array_column($this->api('GET', '/classifications')->json('data.categories'), 'id'));
        $this->api('POST', '/categories', ['code' => 'new-category'] + $data)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->api('POST', '/categories', ['code' => '', 'name_ar' => '', 'is_active' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['code', 'name_ar', 'is_active']);
        $inactive = $this->api('POST', '/categories', ['code' => 'INACTIVE', 'is_active' => false] + $data)->assertCreated()->json('data.id');
        $this->assertNotContains($inactive, array_column($this->api('GET', '/classifications')->json('data.categories'), 'id'));
        $this->api('POST', '', ['kind' => 'service', 'category_id' => $id, 'code' => 'WITH-CATEGORY', 'name_ar' => 'خدمة جديدة', 'is_active' => true])->assertCreated()->assertJsonPath('data.classification_name_ar', 'فئة جديدة');
    }

    public function test_event_identity_eligibility_actual_dates_filters_and_full_totals(): void
    {
        foreach (['service' => 3, 'procedure' => 5] as $kind => $count) {
            $id = $this->f['items'][$kind][1];
            $path = "/$kind/$id/events";
            $row = DB::table('visit_'.$kind.'s')->where($kind.'_id', $id)->orderBy('id')->first();
            $yesterday = now('Asia/Damascus')->subDay()->toDateString();
            DB::table('visit_'.$kind.'s')->where('id', $row->id)->update(['performed_on' => $yesterday]);
            $result = $this->api('GET', $path)->assertOk()->assertJsonPath('totals.presentations', $count)->assertJsonPath('totals.unique_patients', $kind === 'service' ? 2 : 3)->json('data');
            $this->assertCount($count, array_unique(array_column($result, 'key')));
            $this->assertSame($yesterday, collect($result)->firstWhere('key', 'visit_'.$kind.':'.$row->id)['performed_on']);
            $this->assertCount($kind === 'service' ? 2 : 3, array_filter($result, fn ($event) => $event['patient_code'] === $this->f['tag'].'-P1'));
            $this->api('GET', $path, ['from' => $yesterday, 'to' => $yesterday])->assertJsonPath('totals.presentations', 1)->assertJsonPath('totals.unique_patients', 1);
            $this->api('GET', $path, ['search' => $this->f['tag'].'-P2'])->assertJsonPath('totals.presentations', 1);
            $this->api('GET', $path, ['search' => 'مستفيد'])->assertJsonPath('totals.presentations', $count);
            $this->api('GET', $path, ['search' => 'no-match'])->assertJsonPath('totals.presentations', 0)->assertJsonPath('data', []);
            $this->api('GET', $path, ['from' => $this->f['today'], 'to' => $yesterday])->assertUnprocessable()->assertJsonValidationErrors('to');
            $this->api('GET', $path, ['sort' => 'sql', 'per_page' => 500])->assertUnprocessable();
            for ($n = 0; $n < 23; $n++) {
                $copy = (array) $row;
                unset($copy['id']);
                $copy['client_request_id'] = (string) Str::uuid();
                DB::table('visit_'.$kind.'s')->insert($copy);
            }
            $first = $this->api('GET', $path, ['per_page' => 10, 'sort' => 'patient_name', 'direction' => 'asc'])->assertJsonPath('totals.presentations', $count + 23)->assertJsonPath('totals.unique_patients', $kind === 'service' ? 2 : 3)->json('data');
            $second = $this->api('GET', $path, ['per_page' => 10, 'page' => 2, 'sort' => 'patient_name', 'direction' => 'asc'])->assertJsonPath('totals.presentations', $count + 23)->json('data');
            $this->assertCount(10, $first);
            $this->assertCount(10, $second);
            $this->assertSame([], array_intersect(array_column($first, 'key'), array_column($second, 'key')));
            $this->api('GET', "/$kind/$id")->assertJsonPath('data.patient_count', $kind === 'service' ? 2 : 3);
            $this->api('GET', $path, ['facility_id' => $this->f['other']])->assertForbidden();
            $viewer = $this->f['viewer']->createToken('workflow', ['api'])->plainTextToken;
            $this->api('GET', $path, [], $viewer)->assertForbidden()->assertJsonMissingPath('data');
        }
    }

    public function test_added_routes_match_the_published_openapi_contract(): void
    {
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['/context' => '/context', '/service/'.$this->f['items']['service'][1].'/events' => '/{kind}/{item}/events'] as $suffix => $schemaPath) {
            $operation = $document['paths']['/api/service-catalog'.$schemaPath]['get'];
            $this->assertMatchesSchema($document, $operation['responses'][200]['content']['application/json']['schema'], $this->api('GET', $suffix)->assertOk()->json());
        }
        $body = $this->api('POST', '/categories', ['code' => 'DOC', 'name_ar' => 'فئة', 'is_active' => true])->assertCreated()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/service-catalog/categories']['post']['responses'][201]['content']['application/json']['schema'], $body);
    }
}
