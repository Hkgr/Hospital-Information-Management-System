<?php

namespace App\Console\Commands;

use App\Services\Directory\ClinicalDirectorySetup;
use Illuminate\Console\Command;
use Throwable;

class ClinicalDirectorySetupCommand extends Command
{
    protected $signature = 'directory:clinical-setup {--facility=} {--starts-on=} {--clinic-map= : Reviewed JSON mapping classification keys to existing clinic IDs} {--apply} {--expect= : Fingerprint of reviewed preview} {--operator= : Deployment ticket/operator reference, not beneficiary user ID}';

    protected $description = 'Preview explicit clinical directory corrections; writes only with --apply and the reviewed fingerprint.';

    public function handle(ClinicalDirectorySetup $setup): int
    {
        try {
            $mapping = $this->option('clinic-map') ? json_decode(file_get_contents($this->option('clinic-map')), true, 512, JSON_THROW_ON_ERROR) : [];
            if ($this->option('apply') && (! $this->option('expect') || ! trim((string) $this->option('operator')))) {
                $this->error('التطبيق يحتاج بصمة معاينة --expect ومرجع منفذ --operator.');

                return self::FAILURE;
            }
            $args = [(int) $this->option('facility'), (string) $this->option('starts-on'), $mapping];
            $plan = $this->option('apply') ? $setup->apply(...[...$args, $this->option('expect'), $this->option('operator')]) : $setup->preview(...$args);
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return $plan['errors'] ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
