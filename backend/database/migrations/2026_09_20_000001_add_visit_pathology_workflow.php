<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function stateConstraints(): array
    {
        return [
            'visit_pathologies' => [
                'pathology_internal' => "source = 'external' OR external_organization IS NULL",
                'pathology_unavailable_reason' => "(status IN ('unavailable','cancelled') AND unavailable_reason IS NOT NULL AND CHAR_LENGTH(TRIM(unavailable_reason)) > 0) OR (status NOT IN ('unavailable','cancelled') AND unavailable_reason IS NULL)",
                'pathology_final_fields' => "status = 'completed' OR (result_on IS NULL AND conclusion IS NULL)",
                'pathology_date_order' => '(requested_on IS NULL OR collected_on IS NULL OR requested_on <= collected_on) AND (collected_on IS NULL OR result_on IS NULL OR collected_on <= result_on) AND (requested_on IS NULL OR result_on IS NULL OR requested_on <= result_on)',
                'pathology_responsibility' => '(clinic_id IS NULL AND doctor_id IS NULL) OR (clinic_id IS NOT NULL AND doctor_id IS NOT NULL)',
            ],
            'visit_diagnostic_assessments' => [
                'assessment_exclusive_reason' => "disposition = 'pathology_not_required' OR not_required_reason IS NULL",
                'assessment_required_reason' => "(disposition NOT IN ('not_assessed','pathology_not_required') OR required_reason IS NULL) AND (disposition <> 'pathology_required' OR (required_reason IS NOT NULL AND CHAR_LENGTH(TRIM(required_reason)) > 0))",
                'assessment_exclusive_evidence' => "disposition = 'pathology_confirmed' OR evidence_pathology_id IS NULL",
                'assessment_responsibility' => '(clinic_id IS NULL AND doctor_id IS NULL) OR (clinic_id IS NOT NULL AND doctor_id IS NOT NULL)',
            ],
        ];
    }

    private function scope(Blueprint $t, string $prefix): void
    {
        $t->id();
        $t->unsignedBigInteger('facility_id');
        $t->unsignedBigInteger('patient_id');
        $t->unsignedBigInteger('dossier_id');
        $t->unsignedBigInteger('visit_id');
        $t->unsignedBigInteger('clinic_id')->nullable();
        $t->foreignId('doctor_id')->nullable()->constrained('staff')->restrictOnDelete();
        $t->foreign(['visit_id', 'dossier_id', 'facility_id'], $prefix.'_visit')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
        $t->foreign(['dossier_id', 'facility_id', 'patient_id'], $prefix.'_patient')->references(['id', 'facility_id', 'patient_id'])->on('patient_dossiers')->restrictOnDelete();
        $t->foreign(['clinic_id', 'facility_id'], $prefix.'_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
        $t->uuid('client_request_id');
        $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
        $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
        $t->unsignedBigInteger('lock_version')->default(1);
        $t->timestamps();
        $t->unique(['visit_id', 'client_request_id'], $prefix.'_request');
        $t->index(['dossier_id', 'facility_id', 'visit_id'], $prefix.'_history');
    }

    public function up(): void
    {
        Schema::table('visit_procedures', fn (Blueprint $t) => $t->unique(['id', 'visit_id', 'facility_id'], 'procedure_pathology_scope'));
        Schema::table('visit_attachments', fn (Blueprint $t) => $t->unique(['id', 'visit_id', 'facility_id'], 'attachment_pathology_scope'));
        Schema::create('visit_pathologies', function (Blueprint $t) {
            $this->scope($t, 'pathology');
            $t->string('source', 12);
            $t->string('status', 24);
            $t->string('report_number', 100)->nullable();
            $t->string('external_organization', 200)->nullable();
            $t->string('specimen_type', 200)->nullable();
            $t->string('anatomical_site', 200)->nullable();
            $t->unsignedBigInteger('procedure_event_id')->nullable();
            $t->foreign(['procedure_event_id', 'visit_id', 'facility_id'], 'pathology_procedure')->references(['id', 'visit_id', 'facility_id'])->on('visit_procedures')->restrictOnDelete();
            $t->date('requested_on')->nullable();
            $t->date('collected_on')->nullable();
            $t->date('result_on')->nullable();
            $t->text('conclusion')->nullable();
            $t->text('note')->nullable();
            $t->text('unavailable_reason')->nullable();
            $t->dateTime('voided_at')->nullable();
            $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('void_reason', 255)->nullable();
            $t->unique(['id', 'visit_id', 'facility_id'], 'pathology_full_scope');
            $t->unique(['id', 'dossier_id', 'facility_id'], 'pathology_context_scope');
        });
        DB::statement("ALTER TABLE visit_pathologies ADD CONSTRAINT pathology_status CHECK (status IN ('requested','specimen_collected','pending_result','completed','unavailable','cancelled')), ADD CONSTRAINT pathology_source CHECK (source IN ('internal','external') AND (source <> 'external' OR (external_organization IS NOT NULL AND CHAR_LENGTH(TRIM(external_organization)) > 0))), ADD CONSTRAINT pathology_completed CHECK (status <> 'completed' OR (result_on IS NOT NULL AND conclusion IS NOT NULL AND CHAR_LENGTH(TRIM(conclusion)) > 0)), ADD CONSTRAINT pathology_void CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0)), ADD CONSTRAINT pathology_version CHECK (lock_version > 0)");
        Schema::create('visit_diagnostic_assessments', function (Blueprint $t) {
            $this->scope($t, 'assessment');
            $t->unique('visit_id', 'assessment_one_per_visit');
            $t->string('disposition', 32);
            $t->date('assessed_on')->nullable();
            $t->text('note')->nullable();
            $t->text('required_reason')->nullable();
            $t->text('not_required_reason')->nullable();
            $t->text('follow_up')->nullable();
            $t->unsignedBigInteger('evidence_pathology_id')->nullable();
            $t->foreign(['evidence_pathology_id', 'dossier_id', 'facility_id'], 'assessment_evidence')->references(['id', 'dossier_id', 'facility_id'])->on('visit_pathologies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE visit_diagnostic_assessments ADD CONSTRAINT assessment_disposition CHECK (disposition IN ('not_assessed','pathology_required','pathology_pending','pathology_confirmed','pathology_not_required','referred_out')), ADD CONSTRAINT assessment_version CHECK (lock_version > 0), ADD CONSTRAINT assessment_reason CHECK (disposition <> 'pathology_not_required' OR (not_required_reason IS NOT NULL AND CHAR_LENGTH(TRIM(not_required_reason)) > 0)), ADD CONSTRAINT assessment_confirmation CHECK (disposition <> 'pathology_confirmed' OR evidence_pathology_id IS NOT NULL)");
        Schema::create('pathology_attachments', function (Blueprint $t) {
            $t->unsignedBigInteger('pathology_id');
            $t->unsignedBigInteger('attachment_id');
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('facility_id');
            $t->primary(['pathology_id', 'attachment_id']);
            $t->foreign(['pathology_id', 'visit_id', 'facility_id'], 'pathology_attachment_case')->references(['id', 'visit_id', 'facility_id'])->on('visit_pathologies')->restrictOnDelete();
            $t->foreign(['attachment_id', 'visit_id', 'facility_id'], 'pathology_attachment_file')->references(['id', 'visit_id', 'facility_id'])->on('visit_attachments')->restrictOnDelete();
        });
        foreach ($this->stateConstraints() as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($expression)");
            }
        }
    }

    public function down(): void
    {
        // Refuse before MariaDB's implicit DDL commits; never erase recorded medical history.
        if (DB::table('visit_pathologies')->exists() || DB::table('visit_diagnostic_assessments')->exists()) {
            throw new RuntimeException('Pathology rollback refused: recorded medical facts must be preserved.');
        }
        Schema::drop('pathology_attachments');
        Schema::drop('visit_diagnostic_assessments');
        Schema::drop('visit_pathologies');
        Schema::table('visit_attachments', fn (Blueprint $t) => $t->dropUnique('attachment_pathology_scope'));
        Schema::table('visit_procedures', fn (Blueprint $t) => $t->dropUnique('procedure_pathology_scope'));
    }
};
