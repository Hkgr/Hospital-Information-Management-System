<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('oncology_plans')->where('status', '<>', 'active')->update(['status' => 'active']);
        DB::statement('ALTER TABLE dose_sessions DROP CONSTRAINT onc_dose_links');
        DB::statement('ALTER TABLE dose_sessions ADD CONSTRAINT onc_dose_links CHECK ((oncology_session_id IS NULL AND plan_revision_id IS NULL AND dossier_id IS NULL AND activation_basis IS NULL) OR (oncology_session_id IS NOT NULL AND plan_revision_id IS NOT NULL AND dossier_id IS NOT NULL))');
    }

    public function down(): void
    {
        if (DB::table('dose_sessions')->whereNotNull('oncology_session_id')->whereNull('activation_basis')->exists()) {
            throw new RuntimeException('Plan-status rollback is not retained: linked doses no longer store an activation basis.');
        }
        DB::statement('ALTER TABLE dose_sessions DROP CONSTRAINT onc_dose_links');
        DB::statement("ALTER TABLE dose_sessions ADD CONSTRAINT onc_dose_links CHECK ((oncology_session_id IS NULL AND plan_revision_id IS NULL AND dossier_id IS NULL AND activation_basis IS NULL) OR (oncology_session_id IS NOT NULL AND plan_revision_id IS NOT NULL AND dossier_id IS NOT NULL AND activation_basis IS NOT NULL))");
    }
};
