<?php

namespace App\Console\Commands;

use App\Services\Dossiers\PatientCardInventory;
use Illuminate\Console\Command;

class ReconcilePatientCards extends Command
{
    protected $signature = 'patients:reconcile-cards {--details : Include internal IDs of unresolved records; protect this operator output}';

    protected $description = 'Read-only patient/card inventory; no records are linked, merged, created or deleted';

    public function handle(PatientCardInventory $inventory): int
    {
        $this->line(json_encode($inventory->report() + ($this->option('details') ? ['details' => $inventory->details()] : []), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
