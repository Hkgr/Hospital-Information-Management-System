<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CleanupDossierImports extends Command
{
    protected $signature = 'dossiers:cleanup-imports {--actor= : Existing operator user ID for an applied audit} {--apply : Expire source workbooks and uncommitted normalized payloads; default dry run}';

    protected $description = 'Expire private import source files after 30 days without deleting clinical facts or provenance';

    public function handle(): int
    {
        $actor = filter_var($this->option('actor'), FILTER_VALIDATE_INT);
        if ($this->option('apply') && (! $actor || ! DB::table('users')->where('id', $actor)->exists())) {
            $this->error('Applying retention requires --actor with an existing operator user ID.');

            return self::FAILURE;
        }
        $count = 0;
        foreach (DB::table('dossier_import_batches')->whereNull('file_deleted_at')->where('file_expires_at', '<=', now())->orderBy('id')->lazyById(100) as $candidate) {
            $count++;
            if (! $this->option('apply')) {
                continue;
            }
            DB::transaction(function () use ($candidate, $actor) {
                $batch = DB::table('dossier_import_batches')->where('id', $candidate->id)->lockForUpdate()->first();
                if ($batch->file_deleted_at) {
                    return;
                }
                if ($batch->private_path) {
                    Storage::disk('dossier_private')->delete($batch->private_path);
                }
                // Committed normalized source facts (including explicit import-only notes)
                // remain encrypted historical provenance. Discard abandoned drafts only.
                DB::table('dossier_import_rows')->where('batch_id', $batch->id)->whereNotIn('status', ['committed', 'skipped'])->update(['encrypted_payload' => null, 'updated_at' => now()]);
                DB::table('dossier_import_batches')->where('id', $batch->id)->update(['file_deleted_at' => now(), 'private_path' => null, 'lock_version' => $batch->lock_version + 1, 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['facility_id' => $batch->facility_id, 'actor_id' => $actor, 'entity_type' => 'dossier_import_batch', 'entity_id' => $batch->id, 'event' => 'source_expired', 'new_values' => json_encode(['file_deleted' => true]), 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
            });
        }
        $this->info(($this->option('apply') ? 'Expired ' : 'Would expire ').$count.' private import sources. Clinical records and provenance retained.');

        return self::SUCCESS;
    }
}
