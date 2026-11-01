<?php

namespace App\Console\Commands;

use App\Services\Users\AdminCardEditingPermissions;
use Illuminate\Console\Command;

class ReconcileAdminCardEditingPermissions extends Command
{
    protected $signature = 'access:admin-card-editing {--apply : Apply the reviewed role grant} {--facility= : Existing active facility for audit, required on apply} {--execution-reference= : Operator reference required on apply}';

    protected $description = 'Preview/grant hospital_admin patient-card medical and treatment editing; additive only, preserves other roles and assignments';

    public function handle(): int
    {
        $facility = $this->option('facility');
        $reference = trim((string) $this->option('execution-reference'));
        if (($facility !== null && (! ctype_digit($facility) || (int) $facility < 1))
            || ($this->option('apply') && (strlen($reference) < 3 || strlen($reference) > 255))) {
            $this->error('Use a positive numeric --facility and a 3-255 character --execution-reference for apply.');

            return self::FAILURE;
        }
        try {
            $result = app(AdminCardEditingPermissions::class)->reconcile((bool) $this->option('apply'), $facility ? (int) $facility : null, $reference);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line($this->option('apply') ? ($result['applied'] ? 'Applied the hospital_admin card editing grant with operator audit.' : 'Already matches: no grants, version or audit changed.') : 'DRY RUN: no grants, version or audit changed.');
        $this->table(['Action', 'Permission'], array_map(fn ($c) => ['ADD', $c], $result['added']));

        return self::SUCCESS;
    }
}
