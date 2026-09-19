<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\StockFixture;
use Tests\TestCase;

class StockReceiptConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_confirmation_writes_one_set_of_transactions(): void
    {
        $f = StockFixture::make();
        $this->app['auth']->forgetGuards();
        $row = $this->json('POST', '/api/stock/receipts', [
            'facility_id' => $f['facility'], 'request_id' => (string) Str::uuid(),
            'store_id' => $f['store'], 'receipt_no' => 'CONCURRENT', 'supplier_id' => $f['supplier'],
            'funding_source_id' => $f['funding'], 'received_on' => $f['today'],
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
        $this->assertSame(1, DB::table('medication_batches')->where('batch_number', 'C1')->count());
    }
}
