<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function actors(Blueprint $t): void
    {
        $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
        $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        $t->unsignedBigInteger('lock_version')->default(1);
        $t->timestamps();
    }

    public function up(): void
    {
        Schema::create('oncology_plans', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('dossier_id');
            $t->foreign(['dossier_id', 'facility_id'], 'onc_plan_context')->references(['id', 'facility_id'])->on('patient_dossiers')->restrictOnDelete();
            $t->string('plan_number', 40)->nullable();
            $t->string('status', 24)->default('draft');
            $t->unsignedBigInteger('current_revision_id')->nullable();
            $t->string('basis_key', 200)->nullable();
            $t->string('basis_disposition', 32)->nullable();
            $t->json('activation_basis')->nullable();
            $t->text('override_reason')->nullable();
            $t->text('status_reason')->nullable();
            foreach (['activated', 'paused', 'reviewed', 'completed', 'cancelled'] as $event) {
                $t->dateTime($event.'_at')->nullable();
                $t->foreignId($event.'_by')->nullable()->constrained('users')->restrictOnDelete();
            }
            $t->uuid('client_request_id');
            $this->actors($t);
            $t->unique(['facility_id', 'plan_number'], 'onc_plan_number');
            $t->unique(['facility_id', 'client_request_id'], 'onc_plan_request');
            $t->unique(['id', 'dossier_id', 'facility_id'], 'onc_plan_scope');
            $t->index(['facility_id', 'dossier_id', 'status'], 'onc_plan_status');
        });
        Schema::create('oncology_plan_revisions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('facility_id');
            $t->foreign(['plan_id', 'dossier_id', 'facility_id'], 'onc_revision_plan')->references(['id', 'dossier_id', 'facility_id'])->on('oncology_plans')->restrictOnDelete();
            $t->unsignedInteger('revision_number');
            $t->string('modality', 32);
            $t->string('intent', 32);
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
            $t->uuid('client_request_id');
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['plan_id', 'revision_number'], 'onc_revision_number');
            $t->unique(['plan_id', 'client_request_id'], 'onc_revision_request');
            $t->unique(['id', 'plan_id'], 'onc_revision_owner');
            $t->unique(['id', 'plan_id', 'dossier_id', 'facility_id'], 'onc_revision_scope');
        });
        Schema::table('oncology_plans', fn (Blueprint $t) => $t->foreign(['current_revision_id', 'id'], 'onc_plan_current')->references(['id', 'plan_id'])->on('oncology_plan_revisions')->restrictOnDelete());
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
        Schema::create('oncology_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('plan_id');
            $t->unsignedBigInteger('revision_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('facility_id');
            $t->foreign(['revision_id', 'plan_id', 'dossier_id', 'facility_id'], 'onc_session_revision')->references(['id', 'plan_id', 'dossier_id', 'facility_id'])->on('oncology_plan_revisions')->restrictOnDelete();
            $t->unsignedInteger('cycle_number')->nullable();
            $t->unsignedInteger('session_number');
            $t->date('planned_on');
            $t->string('status', 24)->default('scheduled');
            $t->unsignedBigInteger('clinic_id');
            $t->foreignId('doctor_id')->constrained('staff')->restrictOnDelete();
            $t->foreign(['clinic_id', 'facility_id'], 'onc_session_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
            $t->text('reason')->nullable();
            $t->text('note')->nullable();
            $t->uuid('client_request_id');
            $this->actors($t);
            $t->unique(['plan_id', 'session_number'], 'onc_session_number');
            $t->unique(['facility_id', 'client_request_id'], 'onc_session_request');
            $t->unique(['id', 'revision_id', 'dossier_id', 'facility_id'], 'onc_session_scope');
            $t->index(['facility_id', 'dossier_id', 'status', 'planned_on'], 'onc_session_due');
        });
        Schema::table('dose_sessions', function (Blueprint $t) {
            $t->unsignedBigInteger('oncology_session_id')->nullable()->unique('onc_dose_one_session');
            $t->unsignedBigInteger('plan_revision_id')->nullable();
            $t->unsignedBigInteger('dossier_id')->nullable();
            $t->json('activation_basis')->nullable();
            $t->foreign(['oncology_session_id', 'plan_revision_id', 'dossier_id', 'facility_id'], 'onc_dose_schedule')->references(['id', 'revision_id', 'dossier_id', 'facility_id'])->on('oncology_sessions')->restrictOnDelete();
            $t->foreign(['visit_id', 'dossier_id', 'facility_id'], 'onc_dose_visit')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
            $t->unique(['id', 'visit_id', 'facility_id'], 'onc_dose_visit_scope');
        });
        Schema::table('dose_session_items', function (Blueprint $t) {
            $t->string('medication_code_snapshot', 100)->nullable();
            $t->string('route', 100)->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->dateTime('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason', 255)->nullable();
        });
        Schema::table('visit_medications', function (Blueprint $t) {
            $t->unsignedBigInteger('dose_session_id')->nullable();
            $t->string('medication_code_snapshot', 100)->nullable();
            $t->string('dispensing_purpose', 24)->nullable();
            $t->foreign(['dose_session_id', 'visit_id', 'facility_id'], 'onc_dispense_dose')->references(['id', 'visit_id', 'facility_id'])->on('dose_sessions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE oncology_plans ADD CONSTRAINT onc_plan_state CHECK (status IN ('draft','active','paused','needs_review','completed','cancelled')), ADD CONSTRAINT onc_plan_version CHECK(lock_version > 0)");
        DB::statement('ALTER TABLE oncology_plan_revisions ADD CONSTRAINT onc_revision_dates CHECK(ends_on IS NULL OR ends_on >= starts_on), ADD CONSTRAINT onc_revision_positive CHECK(revision_number > 0 AND (planned_cycles IS NULL OR planned_cycles > 0) AND (planned_sessions IS NULL OR planned_sessions > 0) AND (interval_days IS NULL OR interval_days > 0))');
        DB::statement('ALTER TABLE oncology_regimen_items ADD CONSTRAINT onc_item_dose CHECK(dose_value > 0 AND CHAR_LENGTH(TRIM(dose_unit)) > 0)');
        DB::statement("ALTER TABLE oncology_sessions ADD CONSTRAINT onc_session_state CHECK(status IN ('scheduled','rescheduled','completed','missed','cancelled','referred')), ADD CONSTRAINT onc_session_numbers CHECK(session_number > 0 AND (cycle_number IS NULL OR cycle_number > 0) AND lock_version > 0)");
        DB::statement('ALTER TABLE dose_sessions ADD CONSTRAINT onc_dose_links CHECK((oncology_session_id IS NULL AND plan_revision_id IS NULL AND dossier_id IS NULL AND activation_basis IS NULL) OR (oncology_session_id IS NOT NULL AND plan_revision_id IS NOT NULL AND dossier_id IS NOT NULL AND activation_basis IS NOT NULL))');
        DB::statement('ALTER TABLE dose_session_items ADD CONSTRAINT onc_item_void CHECK((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0)), ADD CONSTRAINT onc_item_version CHECK(lock_version > 0)');
        DB::statement("ALTER TABLE visit_medications ADD CONSTRAINT onc_dispense_purpose CHECK(dispensing_purpose IS NULL OR dispensing_purpose IN ('take_home','supportive'))");
    }

    public function down(): void
    {
        if ((Schema::hasTable('oncology_plans') && DB::table('oncology_plans')->exists()) || (Schema::hasTable('oncology_sessions') && DB::table('oncology_sessions')->exists())
            || (Schema::hasColumn('dose_sessions', 'oncology_session_id') && DB::table('dose_sessions')->whereNotNull('oncology_session_id')->exists())
            || (Schema::hasColumn('dose_session_items', 'medication_code_snapshot') && DB::table('dose_session_items')->where(fn ($q) => $q->whereNotNull('medication_code_snapshot')->orWhereNotNull('route')->orWhereNotNull('note')->orWhereNotNull('voided_at')->orWhere('lock_version', '<>', 1))->exists())
            || (Schema::hasColumn('visit_medications', 'dose_session_id') && DB::table('visit_medications')->where(fn ($q) => $q->whereNotNull('dose_session_id')->orWhereNotNull('medication_code_snapshot')->orWhereNotNull('dispensing_purpose'))->exists())) {
            throw new RuntimeException('Oncology rollback refused: recorded medical facts and amendments must be preserved.');
        }
        // Explicitly remove only indexes owned by this migration. MariaDB keeps
        // automatically created FK indexes after dropping a FK or one of its columns.
        // It may also replace a redundant legacy auto-index with the new wider
        // index: restore that legacy FK's own supporting index before removing ours.
        foreach (['dose_sessions', 'visit_medications'] as $table) {
            $name = 'fk_'.$table.'_769a9cb1ee';
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $t) => $t->index(['visit_id', 'facility_id'], $name));
            }
        }
        foreach ([
            'visit_medications' => [['onc_dispense_purpose', 'onc_dispense_dose'], ['onc_dispense_dose'], ['dose_session_id', 'medication_code_snapshot', 'dispensing_purpose']],
            'dose_session_items' => [['onc_item_void', 'onc_item_version', 'dose_session_items_voided_by_foreign'], ['dose_session_items_voided_by_foreign'], ['medication_code_snapshot', 'route', 'note', 'lock_version', 'voided_at', 'voided_by', 'void_reason']],
            'dose_sessions' => [['onc_dose_links', 'onc_dose_schedule', 'onc_dose_visit'], ['onc_dose_schedule', 'onc_dose_visit', 'onc_dose_one_session', 'onc_dose_visit_scope'], ['oncology_session_id', 'plan_revision_id', 'dossier_id', 'activation_basis']],
        ] as $table => [$constraints, $indexes, $columns]) {
            foreach ($constraints as $name) {
                $this->dropConstraint($table, $name);
            }
            foreach ($indexes as $name) {
                if (Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
                }
            }
            $present = array_values(array_filter($columns, fn ($column) => Schema::hasColumn($table, $column)));
            if ($present) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn($present));
            }
        }
        Schema::dropIfExists('oncology_sessions');
        Schema::dropIfExists('oncology_regimen_items');
        $this->dropConstraint('oncology_plans', 'onc_plan_current');
        Schema::dropIfExists('oncology_plan_revisions');
        Schema::dropIfExists('oncology_plans');
    }

    private function dropConstraint(string $table, string $name): void
    {
        $type = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', $table)->where('CONSTRAINT_NAME', $name)->value('CONSTRAINT_TYPE');
        if ($type) {
            DB::statement('ALTER TABLE '.$table.' DROP '.($type === 'FOREIGN KEY' ? 'FOREIGN KEY ' : 'CONSTRAINT ').$name);
        }
    }
};
