<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function nullable(bool $nullable): void
    {
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->dropForeign('onc_dose_session_plan');
            $t->dropForeign('onc_dose_active_revision');
        });
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->dropForeign('onc_session_revision'));
        Schema::table('oncology_sessions', function (Blueprint $t) use ($nullable) {
            $t->unsignedBigInteger('plan_id')->nullable($nullable)->change();
            $t->unsignedBigInteger('revision_id')->nullable($nullable)->change();
            $t->unsignedInteger('session_number')->nullable($nullable)->change();
        });
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->foreign(['revision_id', 'plan_id', 'dossier_id', 'facility_id'], 'onc_session_revision')->references(['id', 'plan_id', 'dossier_id', 'facility_id'])->on('oncology_plan_revisions')->restrictOnDelete());
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->foreign(['oncology_session_id', 'oncology_plan_id', 'dossier_id', 'facility_id'], 'onc_dose_session_plan')->references(['id', 'plan_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
            $t->foreign(['active_oncology_session_id', 'active_plan_revision_id', 'dossier_id', 'facility_id'], 'onc_dose_active_revision')->references(['id', 'revision_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
        });
    }

    public function up(): void
    {
        $this->nullable(true);
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->foreign(['dossier_id', 'facility_id'], 'onc_session_dossier')->references(['id', 'facility_id'])->on('patient_dossiers')->restrictOnDelete());
        DB::statement('ALTER TABLE oncology_sessions ADD CONSTRAINT onc_session_plan_pair CHECK ((plan_id IS NULL AND revision_id IS NULL AND session_number IS NULL AND cycle_number IS NULL) OR (plan_id IS NOT NULL AND revision_id IS NOT NULL AND session_number IS NOT NULL))');
    }

    public function down(): void
    {
        if (DB::table('oncology_sessions')->whereNull('plan_id')->exists()) {
            throw new RuntimeException('Rollback refused: independent appointments must not be deleted or assigned a fabricated plan.');
        }
        DB::statement('ALTER TABLE oncology_sessions DROP CONSTRAINT onc_session_plan_pair');
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->dropForeign('onc_session_dossier'));
        $this->nullable(false);
    }
};
