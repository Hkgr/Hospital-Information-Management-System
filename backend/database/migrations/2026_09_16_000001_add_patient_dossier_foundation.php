<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_dossiers', function (Blueprint $t) {
            $t->engine = 'InnoDB';
            $t->charset = 'utf8mb4';
            $t->collation = 'utf8mb4_unicode_ci';
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('patient_id')->constrained()->restrictOnDelete();
            $t->string('code', 60);
            $t->date('opening_date');
            $t->text('disability_text')->nullable();
            $t->text('clinical_history')->nullable();
            $t->boolean('is_oncology')->default(false);
            $t->text('previous_examinations')->nullable();
            $t->string('medication_source', 30)->nullable();
            $t->string('other_organization', 200)->nullable();
            $t->string('status', 15)->default('draft');
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['facility_id', 'patient_id'], 'dossiers_facility_patient_unique');
            $t->unique(['facility_id', 'code'], 'dossiers_facility_code_unique');
            $t->unique(['id', 'facility_id', 'patient_id'], 'dossiers_scoped_patient_unique');
            $t->unique(['id', 'facility_id'], 'dossiers_scoped_unique');
            $t->index(['facility_id', 'status', 'opening_date', 'id'], 'dossiers_listing_index');
        });
        DB::statement("ALTER TABLE patient_dossiers ADD CONSTRAINT dossiers_valid_date CHECK (opening_date >= '1000-01-01' AND MONTH(opening_date) BETWEEN 1 AND 12 AND DAY(opening_date) BETWEEN 1 AND 31), ADD CONSTRAINT dossiers_nonblank_code CHECK (CHAR_LENGTH(TRIM(code)) > 0), ADD CONSTRAINT dossiers_status CHECK (status IN ('draft','active')), ADD CONSTRAINT dossiers_version CHECK (lock_version >= 1), ADD CONSTRAINT dossiers_medication_source CHECK (medication_source IS NULL OR medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none')), ADD CONSTRAINT dossiers_other_organization CHECK ((medication_source IS NOT NULL AND medication_source = 'other_organization' AND other_organization IS NOT NULL AND CHAR_LENGTH(TRIM(other_organization)) > 0) OR ((medication_source IS NULL OR medication_source <> 'other_organization') AND other_organization IS NULL))");
        Schema::create('dossier_oncology_selections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('facility_id');
            $t->string('selection_group', 20);
            $t->string('code', 30);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['dossier_id', 'selection_group', 'code'], 'dossier_oncology_selection_unique');
            $t->foreign(['dossier_id', 'facility_id'], 'dossier_oncology_scope_fk')->references(['id', 'facility_id'])->on('patient_dossiers')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE dossier_oncology_selections ADD CONSTRAINT dossier_oncology_codes CHECK ((selection_group = 'history' AND code IN ('medical','surgical','medication','family')) OR (selection_group = 'treatment' AND code IN ('surgical','chemotherapy','radiotherapy','other'))), ADD CONSTRAINT dossier_oncology_version CHECK (lock_version >= 1)");
        Schema::table('visits', function (Blueprint $t) {
            $t->unsignedBigInteger('dossier_id')->nullable();
            $t->index(['dossier_id', 'facility_id', 'patient_id'], 'visits_dossier_scope_index');
            $t->index(['dossier_id', 'visit_date', 'id'], 'visits_dossier_chronology_index');
            $t->foreign(['dossier_id', 'facility_id', 'patient_id'], 'visits_dossier_scope_fk')->references(['id', 'facility_id', 'patient_id'])->on('patient_dossiers')->restrictOnDelete();
        });
        Schema::table('visit_diagnoses', function (Blueprint $t) {
            $t->unsignedBigInteger('clinic_id')->nullable();
            $t->index(['clinic_id', 'facility_id'], 'diagnoses_clinic_scope_index');
            $t->foreign(['clinic_id', 'facility_id'], 'diagnoses_clinic_scope_fk')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
            $t->date('diagnosed_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        // DDL is not transactional on MariaDB. Preflight every data-loss condition first.
        if (DB::table('patient_dossiers')->exists() || DB::table('dossier_oncology_selections')->exists()
            || DB::table('visits')->whereNotNull('dossier_id')->exists()
            || DB::table('visit_diagnoses')->whereNotNull('clinic_id')->orWhereNull('diagnosed_on')->exists()) {
            throw new RuntimeException('Dossier rollback refused: populated dossiers, explicit links or unknown diagnosis dates must be preserved. Keep the additive schema or restore a reviewed backup.');
        }
        Schema::table('visit_diagnoses', function (Blueprint $t) {
            $t->dropForeign('diagnoses_clinic_scope_fk');
            $t->dropIndex('diagnoses_clinic_scope_index');
            $t->dropColumn('clinic_id');
            $t->date('diagnosed_on')->nullable(false)->change();
        });
        Schema::table('visits', function (Blueprint $t) {
            $t->dropForeign('visits_dossier_scope_fk');
            $t->dropIndex('visits_dossier_scope_index');
            $t->dropIndex('visits_dossier_chronology_index');
            $t->dropColumn('dossier_id');
        });
        Schema::drop('dossier_oncology_selections');
        Schema::drop('patient_dossiers');
    }
};
