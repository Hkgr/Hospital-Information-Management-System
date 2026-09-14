<?php

namespace App\Console\Commands;

use App\Services\BloodBank\BloodBankReconcile;
use App\Support\TestDatabaseSafety;
use Illuminate\Console\Command;

class ReconcileBloodBank extends Command
{
    protected $signature = 'blood-bank:reconcile {--apply : Apply the reviewed identity/event mapping transaction}';

    protected $description = 'Inventory or reconcile verified legacy blood-bank links without inventing events';

    public function handle(BloodBankReconcile $service): int
    {
        if (app()->environment('testing')) {
            TestDatabaseSafety::assertAvailable(app());
        }
        $result = $this->option('apply') ? $service->apply() : ['inventory' => $service->inventory(), 'mode' => 'read-only; --apply maps confirmed patient links, keeps unconfirmed identities separate'];
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
