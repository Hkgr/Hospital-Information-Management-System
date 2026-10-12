<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oncology_session_doses', function (Blueprint $t) {
            $t->unsignedBigInteger('nurse_id')->nullable()->change();
            $t->string('nurse_name', 200)->nullable()->after('nurse_id');
            $t->foreignId('medication_id')->nullable()->after('dose_name')->constrained('medications')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE oncology_session_doses ADD CONSTRAINT onc_course_nurse_identity CHECK ((nurse_name IS NOT NULL AND CHAR_LENGTH(TRIM(nurse_name)) > 0) OR (nurse_name IS NULL AND nurse_id IS NOT NULL))');
    }

    public function down(): void
    {
        if (DB::table('oncology_session_doses')->whereNull('nurse_id')->orWhereNotNull('nurse_name')->orWhereNotNull('medication_id')->exists()) {
            throw new RuntimeException('Cannot roll back session-dose identity: free nurse names or medication links are in use. No records changed.');
        }
        DB::statement('ALTER TABLE oncology_session_doses DROP CONSTRAINT onc_course_nurse_identity');
        Schema::table('oncology_session_doses', function (Blueprint $t) {
            $t->dropForeign(['medication_id']);
            $t->dropColumn(['nurse_name', 'medication_id']);
            $t->unsignedBigInteger('nurse_id')->nullable(false)->change();
        });
    }
};
