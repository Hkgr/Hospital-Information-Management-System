<?php

namespace App\Console\Commands;

use App\Services\Users\AccessConsolidation;
use Illuminate\Console\Command;

class ConsolidateAccess extends Command
{
    protected $signature = 'access:consolidate-admin {--apply} {--fingerprint=} {--execution-reference=} {--reason=}';

    protected $description = 'Preview or explicitly consolidate user 1 access; no account or clinical data changes.';

    public function handle(AccessConsolidation $service): int
    {
        try {
            $result = $this->option('apply')
                ? $service->apply((string) $this->option('fingerprint'), (string) $this->option('execution-reference'), (string) $this->option('reason'))
                : $service->preview();
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $result['conflicts'] ? self::FAILURE : self::SUCCESS;
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
