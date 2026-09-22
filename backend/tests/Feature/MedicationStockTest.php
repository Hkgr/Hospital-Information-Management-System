<?php

namespace Tests\Feature;

use App\Services\MedicationStock\StockReceipts;
use Database\Seeders\MedicationStockPermissionsSeeder;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AssertsOpenApi;
use Tests\Support\StockFixture;
use Tests\TestCase;

class MedicationStockTest extends TestCase
{
    use AssertsOpenApi, RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = StockFixture::make();
    }

    private function api(string $method, string $path, array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/stock'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.($token ?? $this->f['token'])]);
    }

    private function receipt(array $extra = [], ?array $items = null): array
    {
        $payload = $extra + [
            'request_id' => (string) Str::uuid(), 'store_id' => $this->f['store'], 'receipt_no' => 'R-'.Str::upper(Str::random(6)),
            'supplier_id' => $this->f['supplier'], 'medication_source' => 'ministry_of_health',
            'received_on' => $this->f['today'],
            'items' => $items ?? [[
                'medication_id' => $this->f['items']['medication'][1], 'batch_number' => 'B1',
                'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10, 'free_quantity' => 2,
            ]],
        ];

        return $this->api('POST', '/receipts', $payload)->assertCreated()->json('data');
    }

    public function test_permissions_seeder_never_grants_or_reactivates(): void
    {
        DB::table('permissions')->where('code', 'stock.receive')->update(['is_active' => false]);
        $count = DB::table('role_permissions')->count();
        $this->seed(MedicationStockPermissionsSeeder::class);
        $this->seed(MedicationStockPermissionsSeeder::class);
        $this->assertSame($count, DB::table('role_permissions')->count());
        $this->assertDatabaseHas('permissions', ['code' => 'stock.receive', 'is_active' => false]);
    }

    public function test_suppliers_and_stores_crud_uniqueness_lifecycle_and_facility_scope(): void
    {
        foreach (['suppliers' => ['contact_person' => 'أحمد', 'phone' => '011', 'address_line' => 'حلب', 'note' => 'ملاحظة'], 'stores' => ['location' => 'الصيدلية']] as $directory => $fields) {
            $created = $this->api('POST', '/'.$directory, ['code' => 'NEW-'.$directory, 'name_ar' => 'سجل '.$directory, 'is_active' => true] + $fields)
                ->assertCreated()->assertJsonPath('data.is_active', true)->json('data');
            $this->api('GET', '/'.$directory.'/'.$created['id'])->assertOk()->assertJsonPath('data.code', 'NEW-'.$directory);
            $this->api('PUT', '/'.$directory.'/'.$created['id'], ['code' => $created['code'], 'name_ar' => 'تعديل', 'is_active' => true, 'lock_version' => 1] + $fields)
                ->assertOk()->assertJsonPath('data.name_ar', 'تعديل')->assertJsonPath('data.lock_version', 2);
            $this->api('POST', '/'.$directory, ['code' => 'NEW-'.$directory, 'name_ar' => 'مكرر', 'is_active' => true] + $fields)
                ->assertUnprocessable()->assertJsonValidationErrors('code');
            $this->api('GET', '/'.$directory.'/'.$created['id'], ['facility_id' => $this->f['other']])->assertForbidden();
            $this->api('POST', '/'.$directory.'/'.$created['id'].'/deactivate', ['lock_version' => 2])->assertOk()->assertJsonPath('data.is_active', false);
            $this->api('POST', '/'.$directory.'/'.$created['id'].'/reactivate', ['lock_version' => 3])->assertOk()->assertJsonPath('data.is_active', true);
            $this->api('DELETE', '/'.$directory.'/'.$created['id'], ['lock_version' => 4])->assertNoContent();
            $this->assertDatabaseMissing($directory === 'stores' ? 'medication_stores' : 'medication_suppliers', ['id' => $created['id']]);
        }
        $this->api('POST', '/receipts', [
            'request_id' => (string) Str::uuid(), 'store_id' => $this->f['store'], 'receipt_no' => 'KEEP',
            'medication_source' => 'ministry_of_health', 'received_on' => $this->f['today'],
        ])->assertCreated();
        $this->api('DELETE', '/stores/'.$this->f['store'], ['lock_version' => 1])->assertConflict()->assertJsonPath('error.code', 'STOCK_REFERENCED');
        $this->api('POST', '/stores/'.$this->f['store'].'/archive', ['lock_version' => 1])->assertOk()->assertJsonPath('data.archived_at', fn ($v) => $v !== null);
    }

    public function test_receipt_confirm_writes_batches_and_in_transactions_including_free_quantity(): void
    {
        $row = $this->receipt();
        $this->assertSame('draft', $row['status']);
        $confirmed = $this->api('POST', '/receipts/'.$row['id'].'/confirm', ['lock_version' => $row['lock_version']])->assertOk()->json('data');
        $this->assertSame('confirmed', $confirmed['status']);
        $this->assertNotNull($confirmed['confirmed_at']);
        $this->assertNotNull($confirmed['items'][0]['batch_id']);
        $batch = DB::table('medication_batches')->where('id', $confirmed['items'][0]['batch_id'])->first();
        $this->assertSame('12.0000', $batch->received_quantity);
        $this->assertSame('ministry_of_health', $batch->medication_source);
        $this->assertSame((int) $this->f['supplier'], (int) $batch->supplier_id);
        $txn = DB::table('inventory_transactions')->where('reference_type', 'medication_receipt')->where('reference_id', $row['id'])->get();
        $this->assertCount(1, $txn);
        $this->assertSame('receipt', $txn[0]->transaction_type);
        $this->assertSame('in', $txn[0]->direction);
        $this->assertSame('12.0000', $txn[0]->quantity);
        $this->api('POST', '/receipts/'.$row['id'].'/confirm', ['lock_version' => 1])->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->assertSame(1, DB::table('inventory_transactions')->where('reference_id', $row['id'])->count());
        $this->assertSame(1, DB::table('medication_batches')->where('id', $batch->id)->count());
    }

    public function test_expired_or_same_day_item_keeps_receipt_draft_and_empty_confirm_is_refused(): void
    {
        $bad = $this->receipt([], [[
            'medication_id' => $this->f['items']['medication'][1], 'batch_number' => 'EXP',
            'expiry_date' => $this->f['today'], 'quantity' => 1,
        ]]);
        $this->api('POST', '/receipts/'.$bad['id'].'/confirm', ['lock_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('medication_receipts', ['id' => $bad['id'], 'status' => 'draft']);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $empty = $this->receipt(['receipt_no' => 'EMPTY-'.$this->f['tag']], []);
        $this->api('POST', '/receipts/'.$empty['id'].'/confirm', ['lock_version' => 1])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertDatabaseHas('medication_receipts', ['id' => $empty['id'], 'status' => 'draft']);
    }

    public function test_confirm_refuses_a_batch_funded_by_a_different_medication_source(): void
    {
        $expiry = now()->addYear()->toDateString();
        $item = [[
            'medication_id' => $this->f['items']['medication'][1], 'batch_number' => 'SHARED',
            'expiry_date' => $expiry, 'quantity' => 1,
        ]];
        $first = $this->receipt(['receipt_no' => 'SRC-A-'.$this->f['tag']], $item);
        $this->api('POST', '/receipts/'.$first['id'].'/confirm', ['lock_version' => 1])->assertOk();
        $second = $this->receipt(['receipt_no' => 'SRC-B-'.$this->f['tag'], 'medication_source' => 'al_rowad'], $item);
        $this->api('POST', '/receipts/'.$second['id'].'/confirm', ['lock_version' => 1])
            ->assertConflict()->assertJsonPath('error.code', 'STOCK_BATCH_FUNDING_CONFLICT');
        $this->assertDatabaseHas('medication_receipts', ['id' => $second['id'], 'status' => 'draft']);
        $this->api('POST', '/receipts', [
            'request_id' => (string) Str::uuid(), 'store_id' => $this->f['store'], 'receipt_no' => 'BAD-SOURCE',
            'medication_source' => 'catalog-row', 'received_on' => $this->f['today'],
        ])->assertUnprocessable()->assertJsonValidationErrors('medication_source');
    }

    public function test_replayed_create_returns_original_and_different_content_conflicts(): void
    {
        $request = (string) Str::uuid();
        $first = $this->receipt(['request_id' => $request, 'receipt_no' => 'ONCE-1']);
        $again = $this->api('POST', '/receipts', [
            'request_id' => $request, 'store_id' => $this->f['store'], 'receipt_no' => 'ONCE-1',
            'supplier_id' => $this->f['supplier'], 'medication_source' => 'ministry_of_health', 'received_on' => $this->f['today'],
            'items' => [['medication_id' => $this->f['items']['medication'][1], 'batch_number' => 'B1', 'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 10, 'free_quantity' => 2]],
        ])->assertCreated()->json('data');
        $this->assertSame($first['id'], $again['id']);
        $this->api('POST', '/receipts', [
            'request_id' => $request, 'store_id' => $this->f['store'], 'receipt_no' => 'ONCE-2',
            'medication_source' => 'ministry_of_health', 'received_on' => $this->f['today'],
        ])->assertConflict()->assertJsonPath('error.code', 'STOCK_REQUEST_CONFLICT');
    }

    public function test_authentication_facility_scope_and_permission_pair(): void
    {
        $row = $this->receipt(['receipt_no' => 'AUTH-'.$this->f['tag']]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/stock/receipts')->assertUnauthorized()->assertHeader('Cache-Control', 'no-store, private');
        $this->api('GET', '/receipts', [], 'invalid')->assertUnauthorized();
        StockFixture::viewerStock($this->f, ['stock.view']);
        $this->api('GET', '/receipts', [], $this->f['viewer_token'])->assertOk();
        $this->api('POST', '/receipts', [
            'request_id' => (string) Str::uuid(), 'store_id' => $this->f['store'], 'receipt_no' => 'DENIED',
            'medication_source' => 'ministry_of_health', 'received_on' => $this->f['today'],
        ], $this->f['viewer_token'])->assertForbidden()->assertJsonPath('error.code', 'STOCK_ACCESS_DENIED');
        $this->api('POST', '/suppliers', ['code' => 'X', 'name_ar' => 'م', 'is_active' => true], $this->f['viewer_token'])->assertForbidden();
        $this->api('GET', '/receipts/'.$row['id'], ['facility_id' => $this->f['other']])->assertForbidden();
        $this->api('GET', '/receipts/'.($row['id'] + 99999))->assertNotFound()->assertJsonPath('error.code', 'STOCK_NOT_FOUND');
    }

    public function test_openapi_matches_directory_and_receipt_contracts(): void
    {
        $row = $this->receipt(['receipt_no' => 'OA-'.$this->f['tag']]);
        $document = $this->getJson('/docs/api.json')->assertOk()->json();
        $list = $this->api('GET', '/suppliers')->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/stock/suppliers']['get']['responses'][200]['content']['application/json']['schema'], $list);
        $stores = $this->api('GET', '/stores')->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/stock/stores']['get']['responses'][200]['content']['application/json']['schema'], $stores);
        $receipts = $this->api('GET', '/receipts')->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/stock/receipts']['get']['responses'][200]['content']['application/json']['schema'], $receipts);
        $detail = $this->api('GET', '/receipts/'.$row['id'])->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/stock/receipts/{receipt}']['get']['responses'][200]['content']['application/json']['schema'], $detail);
        $options = $this->api('GET', '/options')->assertOk()->json();
        $this->assertMatchesSchema($document, $document['paths']['/api/stock/options']['get']['responses'][200]['content']['application/json']['schema'], $options);
        $this->assertArrayHasKey(401, $document['paths']['/api/stock/receipts']['post']['responses']);
        $this->assertSame([['bearerAuth' => []]], $document['paths']['/api/stock/receipts']['post']['security']);
    }

    public function test_unexpected_exceptions_are_private(): void
    {
        $reported = [];
        $this->app->make(ExceptionHandler::class)->reportable(function (\Throwable $error) use (&$reported) {
            $reported[] = $error;

            return false;
        });
        $this->mock(StockReceipts::class, fn ($mock) => $mock->shouldReceive('listing')->once()->andThrow(new \RuntimeException('SQL secret')));
        $this->api('GET', '/receipts')->assertStatus(500)->assertJsonPath('error.code', 'STOCK_UNAVAILABLE')->assertDontSee('secret')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertNotEmpty($reported);
    }
}
