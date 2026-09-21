<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('oncology_regimen_items');
        DB::statement('ALTER TABLE oncology_plan_revisions DROP CONSTRAINT onc_revision_dates');
        DB::statement('ALTER TABLE oncology_plan_revisions DROP CONSTRAINT onc_revision_positive');
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->text('protocol_text')->nullable();
            $t->unsignedBigInteger('protocol_clinic_id')->nullable();
            $t->unsignedBigInteger('protocol_doctor_id')->nullable();
            $t->unsignedBigInteger('treating_clinic_id')->nullable();
            $t->unsignedBigInteger('treating_doctor_id')->nullable();
        });
        // Dropped columns are not archived. The surviving protocol text and both
        // doctors are filled once so an existing plan row can stay referentially valid.
        DB::statement("UPDATE oncology_plan_revisions SET protocol_text = CASE WHEN CHAR_LENGTH(TRIM(protocol_name)) > 0 THEN protocol_name ELSE '-' END, protocol_clinic_id = clinic_id, protocol_doctor_id = doctor_id, treating_clinic_id = clinic_id, treating_doctor_id = doctor_id");
        DB::statement('ALTER TABLE oncology_plan_revisions MODIFY protocol_text TEXT NOT NULL, MODIFY protocol_clinic_id BIGINT UNSIGNED NOT NULL, MODIFY protocol_doctor_id BIGINT UNSIGNED NOT NULL, MODIFY treating_clinic_id BIGINT UNSIGNED NOT NULL, MODIFY treating_doctor_id BIGINT UNSIGNED NOT NULL');
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->dropForeign(['doctor_id']);
            $t->dropForeign('onc_revision_clinic');
            $t->dropForeign(['diagnosis_id']);
        });
        foreach (['onc_revision_clinic', 'oncology_plan_revisions_doctor_id_foreign', 'oncology_plan_revisions_diagnosis_id_foreign'] as $index) {
            if (Schema::hasIndex('oncology_plan_revisions', $index)) {
                Schema::table('oncology_plan_revisions', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->dropColumn(['protocol_name', 'protocol_code', 'planned_cycles', 'planned_sessions', 'interval_days', 'starts_on', 'ends_on', 'diagnosis_id', 'diagnosis_snapshot', 'note', 'amendment_reason', 'clinic_id', 'doctor_id']);
            $t->foreign('protocol_doctor_id', 'onc_protocol_doctor')->references('id')->on('staff')->restrictOnDelete();
            $t->foreign('treating_doctor_id', 'onc_treating_doctor')->references('id')->on('staff')->restrictOnDelete();
            $t->foreign(['protocol_clinic_id', 'facility_id'], 'onc_protocol_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
            $t->foreign(['treating_clinic_id', 'facility_id'], 'onc_treating_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE oncology_plan_revisions ADD CONSTRAINT onc_revision_positive CHECK (revision_number > 0), ADD CONSTRAINT onc_protocol_text CHECK (CHAR_LENGTH(TRIM(protocol_text)) > 0)');
    }

    public function down(): void
    {
        if (DB::table('oncology_plan_revisions')->exists()) {
            throw new RuntimeException('Rollback refused: simplified plans discarded extra columns and those values were not retained.');
        }
        DB::statement('ALTER TABLE oncology_plan_revisions DROP CONSTRAINT onc_protocol_text');
        DB::statement('ALTER TABLE oncology_plan_revisions DROP CONSTRAINT onc_revision_positive');
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->dropForeign('onc_protocol_doctor');
            $t->dropForeign('onc_treating_doctor');
            $t->dropForeign('onc_protocol_clinic');
            $t->dropForeign('onc_treating_clinic');
        });
        foreach (['onc_protocol_doctor', 'onc_treating_doctor', 'onc_protocol_clinic', 'onc_treating_clinic'] as $index) {
            if (Schema::hasIndex('oncology_plan_revisions', $index)) {
                Schema::table('oncology_plan_revisions', fn (Blueprint $t) => $t->dropIndex($index));
            }
        }
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->dropColumn(['protocol_text', 'protocol_clinic_id', 'protocol_doctor_id', 'treating_clinic_id', 'treating_doctor_id']);
        });
        Schema::table('oncology_plan_revisions', function (Blueprint $t) {
            $t->string('protocol_name', 200);
            $t->string('protocol_code', 100)->nullable();
            $t->unsignedBigInteger('clinic_id');
            $t->foreignId('doctor_id')->constrained('staff')->restrictOnDelete();
            $t->foreign(['clinic_id', 'facility_id'], 'onc_revision_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
            $t->foreignId('diagnosis_id')->nullable()->constrained('diagnoses')->restrictOnDelete();
            $t->string('diagnosis_snapshot', 255)->nullable();
            $t->unsignedInteger('planned_cycles')->nullable();
            $t->unsignedInteger('planned_sessions')->nullable();
            $t->unsignedInteger('interval_days')->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->text('note')->nullable();
            $t->text('amendment_reason')->nullable();
        });
        Schema::create('oncology_regimen_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('revision_id')->constrained('oncology_plan_revisions')->restrictOnDelete();
            $t->foreignId('medication_id')->nullable()->constrained('medications')->restrictOnDelete();
            $t->string('medication_name_snapshot', 200);
            $t->string('medication_code_snapshot', 100)->nullable();
            $t->decimal('dose_value', 18, 4);
            $t->string('dose_unit', 40);
            $t->string('route', 100);
            $t->string('instructions', 1000)->nullable();
            $t->foreignId('funding_source_id')->nullable()->constrained('funding_sources')->restrictOnDelete();
            $t->text('note')->nullable();
            $t->unsignedInteger('display_order');
            $t->unique(['revision_id', 'display_order'], 'onc_item_order');
        });
        DB::statement('ALTER TABLE oncology_plan_revisions ADD CONSTRAINT onc_revision_dates CHECK(ends_on IS NULL OR ends_on >= starts_on), ADD CONSTRAINT onc_revision_positive CHECK(revision_number > 0 AND (planned_cycles IS NULL OR planned_cycles > 0) AND (planned_sessions IS NULL OR planned_sessions > 0) AND (interval_days IS NULL OR interval_days > 0))');
        DB::statement('ALTER TABLE oncology_regimen_items ADD CONSTRAINT onc_item_dose CHECK(dose_value > 0 AND CHAR_LENGTH(TRIM(dose_unit)) > 0)');
    }
};
