<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('dose_sessions')->whereNotNull('oncology_session_id')->whereNull('voided_at')->groupBy('oncology_session_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Oncology upgrade refused: conflicting active administrations require explicit medical review. No facts were changed.');
        }
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->unique(['id', 'plan_id', 'dossier_id', 'facility_id'], 'onc_session_plan_scope'));
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->unsignedBigInteger('oncology_plan_id')->nullable();
            $t->unsignedBigInteger('active_oncology_session_id')->nullable()->storedAs('CASE WHEN voided_at IS NULL THEN oncology_session_id ELSE NULL END');
            $t->unique('active_oncology_session_id', 'onc_dose_one_active_session');
        });
        // Only derive the existing relational identity; do not rewrite clinical facts.
        DB::statement('UPDATE dose_sessions d JOIN oncology_sessions s ON s.id = d.oncology_session_id SET d.oncology_plan_id = s.plan_id WHERE d.oncology_session_id IS NOT NULL');
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->foreign(['oncology_session_id', 'oncology_plan_id', 'dossier_id', 'facility_id'], 'onc_dose_session_plan')->references(['id', 'plan_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
            $t->foreign(['plan_revision_id', 'oncology_plan_id', 'dossier_id', 'facility_id'], 'onc_dose_historical_revision')->references(['id', 'plan_id', 'dossier_id', 'facility_id'])->on('oncology_plan_revisions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE dose_sessions ADD CONSTRAINT onc_dose_plan_link CHECK ((oncology_session_id IS NULL AND oncology_plan_id IS NULL) OR (oncology_session_id IS NOT NULL AND oncology_plan_id IS NOT NULL))');
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->dropForeign('onc_dose_schedule');
            $t->dropUnique('onc_dose_one_session');
        });
    }

    public function down(): void
    {
        if (DB::table('dose_sessions')->whereNotNull('oncology_session_id')->groupBy('oncology_session_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('dose_sessions as d')->join('oncology_sessions as s', 's.id', '=', 'd.oncology_session_id')->whereColumn('d.plan_revision_id', '<>', 's.revision_id')->exists()) {
            throw new RuntimeException('Oncology rollback refused: retained administration attempts or historical revisions cannot satisfy the former constraints. No medical facts were changed.');
        }
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->unique('oncology_session_id', 'onc_dose_one_session');
            $t->foreign(['oncology_session_id', 'plan_revision_id', 'dossier_id', 'facility_id'], 'onc_dose_schedule')->references(['id', 'revision_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE dose_sessions DROP CONSTRAINT onc_dose_plan_link');
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->dropForeign('onc_dose_session_plan');
            $t->dropForeign('onc_dose_historical_revision');
            $t->dropIndex('onc_dose_session_plan');
            $t->dropIndex('onc_dose_historical_revision');
            $t->dropUnique('onc_dose_one_active_session');
            $t->dropColumn(['active_oncology_session_id', 'oncology_plan_id']);
        });
        Schema::table('oncology_sessions', fn (Blueprint $t) => $t->dropUnique('onc_session_plan_scope'));
    }
};
