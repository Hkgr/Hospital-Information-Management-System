<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('dose_sessions as d')->join('oncology_sessions as s', 's.id', '=', 'd.oncology_session_id')->whereNull('d.voided_at')->whereColumn('d.plan_revision_id', '<>', 's.revision_id')->exists()) {
            throw new RuntimeException('Active oncology revision constraint refused: conflicting active clinical revisions require explicit review. No facts were changed.');
        }
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->unsignedBigInteger('active_plan_revision_id')->nullable()->storedAs('CASE WHEN voided_at IS NULL THEN plan_revision_id ELSE NULL END');
            $t->foreign(['active_oncology_session_id', 'active_plan_revision_id', 'dossier_id', 'facility_id'], 'onc_dose_active_revision')->references(['id', 'revision_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->dropForeign('onc_dose_active_revision');
            $t->dropIndex('onc_dose_active_revision');
            $t->dropColumn('active_plan_revision_id');
        });
    }
};
