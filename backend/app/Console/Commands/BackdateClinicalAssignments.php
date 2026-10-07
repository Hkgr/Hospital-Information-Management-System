<?php

namespace App\Console\Commands;

use App\Services\Directory\ClinicalAssignmentDates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackdateClinicalAssignments extends Command
{
    protected $signature = 'clinical-assignments:backdate {--facility= : Optional existing facility ID} {--apply : Apply the reviewed correction} {--execution-reference= : Operator change-ticket/reference, required for apply}';

    protected $description = 'Preview/backdate clinic_staff clinical assignment starts to 2022-01-01, retaining ended periods and conflict groups';

    public function handle(): int
    {
        $facility = $this->option('facility');
        $reference = trim((string) $this->option('execution-reference'));
        if (($facility !== null && (! ctype_digit($facility) || (int) $facility < 1 || ! DB::table('facilities')->where('id', $facility)->exists())) || ($this->option('apply') && (strlen($reference) < 3 || strlen($reference) > 255))) {
            $this->error('Provide an existing numeric --facility and a 3-255 character execution reference for --apply.');

            return self::FAILURE;
        }
        $result = app(ClinicalAssignmentDates::class)->backdate($facility ? (int) $facility : null, (bool) $this->option('apply'), $reference);
        $this->line($this->option('apply') ? 'Applied conflict-free groups only; conflicting groups retained unchanged.' : 'DRY RUN: no records or audit entries changed.');
        $this->table(array_keys($result['counts']), [array_values($result['counts'])]);
        $this->table(['clinic_id', 'staff_id (ambiguous group unchanged)'], $result['conflicts']);

        return $result['conflicts'] ? self::FAILURE : self::SUCCESS;
    }
}
