<?php

namespace App\Console\Commands;

use App\Services\Users\DataEntryCardPermissions;
use Illuminate\Console\Command;

class ReconcileDataEntryCardPermissions extends Command
{
    protected $signature = 'access:data-entry-card-permissions {--apply : Apply the reviewed role correction} {--facility= : Existing active facility for audit, required on apply} {--execution-reference= : Operator reference required on apply}';

    protected $description = 'Preview/correct data_entry card input permissions and remove directory creation; preserves other roles and assignments';

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
            $result = app(DataEntryCardPermissions::class)->reconcile((bool) $this->option('apply'), $facility ? (int) $facility : null, $reference);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->line($this->option('apply') ? ($result['applied'] ? 'Applied the data_entry role correction with operator audit.' : 'Already matches: no grants, version or audit changed.') : 'DRY RUN: no definitions, grants, version or audit changed.');
        $this->table(['Action', 'Permission'], array_merge(array_map(fn ($c) => ['ADD', $c], $result['added']), array_map(fn ($c) => ['REMOVE', $c], $result['removed']), array_map(fn ($c) => ['DEFINE MISSING', $c], $result['missing_definitions'])));

        return self::SUCCESS;
    }
}
