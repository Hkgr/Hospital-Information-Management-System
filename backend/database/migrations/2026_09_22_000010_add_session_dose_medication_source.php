<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SOURCE = "medication_source IS NULL OR medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none')";

    public function up(): void
    {
        Schema::table('oncology_session_doses', function (Blueprint $t) {
            $t->string('medication_source', 30)->nullable()->after('nurse_id');
        });
        DB::statement('ALTER TABLE oncology_session_doses ADD CONSTRAINT onc_course_medication_source CHECK ('.self::SOURCE.')');
    }

    public function down(): void
    {
        if (DB::table('oncology_session_doses')->whereNotNull('medication_source')->exists()) {
            throw new RuntimeException('rollback refused: session-dose medication_source values must be preserved');
        }
        DB::statement('ALTER TABLE oncology_session_doses DROP CONSTRAINT onc_course_medication_source');
        Schema::table('oncology_session_doses', function (Blueprint $t) {
            $t->dropColumn('medication_source');
        });
    }
};
