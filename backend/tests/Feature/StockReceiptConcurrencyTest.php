<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TestDatabaseSafety;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\StockFixture;
use Tests\TestCase;

class StockReceiptConcurrencyTest extends TestCase
{
    private ?User $fixtureUser = null;

    protected function tearDown(): void
    {
        $this->fixtureUser?->tokens()->delete();
        $this->fixtureUser?->forceFill(['is_active' => false])->save();
        parent::tearDown();
    }

    public function test_concurrent_confirmation_writes_one_set_of_transactions(): void
    {
        // Workers need committed synthetic records, not a destructive schema refresh.
        TestDatabaseSafety::assertAvailable($this->app);
        $f = StockFixture::make();
        $this->fixtureUser = $f['user'];
        $f['viewer']->tokens()->delete();
        $this->app['auth']->forgetGuards();
        $row = $this->json('POST', '/api/stock/receipts', [
            'facility_id' => $f['facility'], 'request_id' => (string) Str::uuid(),
            'store_id' => $f['store'], 'supplier_id' => $f['supplier'],
            'medication_source' => 'ministry_of_health', 'received_on' => $f['today'],
            'items' => [[
                'medication_id' => $f['items']['medication'][1], 'batch_number' => 'C1',
                'expiry_date' => now()->addYear()->toDateString(), 'quantity' => 4, 'free_quantity' => 1,
            ]],
        ], ['Authorization' => 'Bearer '.$f['token']])->assertCreated()->json('data');
        $workers = [];
        foreach ([1, 2] as $i) {
            $process = new Process([PHP_BINARY, 'tests/Support/stock-confirm-worker.php', $f['token'], (string) $row['id'], (string) $f['facility'], (string) $row['lock_version']], base_path(), ['APP_ENV' => 'testing']);
            $process->setTimeout(30);
            $process->start();
            $workers[] = $process;
        }
        foreach ($workers as $process) {
            $this->assertSame(0, $process->wait(), $process->getErrorOutput().$process->getOutput());
        }
        $this->assertDatabaseHas('medication_receipts', ['id' => $row['id'], 'status' => 'confirmed']);
        $this->assertSame(1, DB::table('inventory_transactions')->where('reference_type', 'medication_receipt')->where('reference_id', $row['id'])->count());
        $this->assertSame(1, DB::table('medication_batches')->where('facility_id', $f['facility'])->where('batch_number', 'C1')->count());
    }
}
