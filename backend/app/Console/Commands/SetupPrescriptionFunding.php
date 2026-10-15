<?php

namespace App\Console\Commands;

use App\Services\Dossiers\MedicationSources;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SetupPrescriptionFunding extends Command
{
    protected $signature = 'dossiers:setup-prescription-funding {--apply} {--facility= : Existing active facility for audit} {--execution-reference= : Operator/change reference required on apply}';

    protected $description = 'Preview/add approved general medication sources to funding_sources; never edit or reactivate existing definitions';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $reference = trim((string) $this->option('execution-reference'));
        $facility = (string) $this->option('facility');
        if ($apply && (! ctype_digit($facility) || (int) $facility < 1 || strlen($reference) < 3 || strlen($reference) > 255)) {
            $this->error('Apply requires an existing active --facility and a 3-255 character --execution-reference.');

            return self::FAILURE;
        }
        try {
            $rows = DB::transaction(function () use ($apply, $reference, $facility) {
                if ($apply && ! DB::table('facilities')->where('id', $facility)->where('is_active', true)->exists()) {
                    throw new \RuntimeException('The audit facility must exist and be active.');
                }
                $plan = app(MedicationSources::class)->bootstrap($apply);
                if ($apply) {
                    foreach ($plan as [$action, $code, $name]) {
                        if ($action !== 'ADD') {
                            continue;
                        }
                        $id = DB::table('funding_sources')->where('code', $code)->value('id');
                        DB::table('audit_logs')->insert(['facility_id' => (int) $facility, 'actor_id' => null, 'entity_type' => 'funding_source', 'entity_id' => $id, 'event' => 'created', 'new_values' => json_encode(['code' => $code, 'name_ar' => $name, 'source' => 'approved_general_medication_sources', 'execution_reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'reason' => $reference, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
                    }
                }

                return $plan;
            }, 3);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line($apply ? 'Applied missing approved sources only.' : 'DRY RUN: no definitions or audit written.');
        $this->table(['Action', 'Approved code', 'Approved name', 'Existing ID'], $rows);

        return self::SUCCESS;
    }
}
