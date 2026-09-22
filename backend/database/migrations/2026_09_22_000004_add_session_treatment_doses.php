<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oncology_sessions', function (Blueprint $t) {
            $t->unique(['id', 'dossier_id', 'facility_id'], 'onc_session_card');
        });
        Schema::create('oncology_session_doses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('session_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('facility_id');
            $t->foreign(['session_id', 'dossier_id', 'facility_id'], 'onc_course_session')->references(['id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
            $t->date('given_on');
            $t->string('dose_name', 200);
            $t->text('complaint');
            $t->text('recommendations');
            $t->foreignId('nurse_id')->constrained('staff')->restrictOnDelete();
            $t->uuid('client_request_id');
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['facility_id', 'client_request_id'], 'onc_course_request');
            $t->index(['session_id', 'given_on'], 'onc_course_due');
        });
        DB::statement('ALTER TABLE oncology_session_doses ADD CONSTRAINT onc_course_text CHECK (CHAR_LENGTH(TRIM(dose_name)) > 0 AND CHAR_LENGTH(TRIM(complaint)) > 0 AND CHAR_LENGTH(TRIM(recommendations)) > 0), ADD CONSTRAINT onc_course_version CHECK (lock_version > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('oncology_session_doses');
        Schema::table('oncology_sessions', function (Blueprint $t) {
            $t->dropUnique('onc_session_card');
        });
    }
};
