<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CleanupDossierUploads extends Command
{
    protected $signature = 'dossiers:cleanup-uploads {--apply : Remove abandoned files; default is a dry run}';

    protected $description = 'Review or remove abandoned private upload files older than 24 hours; never remove recorded attachments';

    public function handle(): int
    {
        $disk = Storage::disk(config('dossiers.attachment_disk'));
        $cutoff = now()->subDay()->getTimestamp();
        $count = 0;
        foreach (['staging', 'files'] as $directory) {
            foreach ($disk->files($directory) as $key) {
                if (! preg_match('#^(staging|files)/[0-9a-f-]{36}(\.(pdf|xls|xlsx|jpg|jpeg|png|webp))?$#i', $key) || $disk->lastModified($key) >= $cutoff) {
                    continue;
                }
                if (DB::table('visit_attachments')->where('storage_key', $key)->exists()) {
                    continue;
                }
                // Authoritative files, including voided history, are never retention targets.
                $count++;
                if ($this->option('apply')) {
                    $disk->delete($key);
                }
            }
        }
        $this->info(($this->option('apply') ? 'Removed ' : 'Would remove ').$count.' abandoned upload files. Recorded attachments were retained.');

        return self::SUCCESS;
    }
}
