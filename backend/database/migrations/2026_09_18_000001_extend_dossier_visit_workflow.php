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
        $t->dateTime('voided_at')->nullable();
        $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete();
        $t->string('void_reason', 255)->nullable();
        $t->timestamps();
    }

    private function checks(string $table): void
    {
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_version` CHECK (lock_version >= 1), ADD CONSTRAINT `{$table}_void` CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0))");
    }

    public function up(): void
    {
        foreach (['visit_services', 'visit_procedures', 'visit_outcomes'] as $table) {
            DB::statement("ALTER TABLE `$table` MODIFY reporting_period_id BIGINT UNSIGNED NULL");
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('clinic_id')->nullable();
                $t->boolean('dossier_managed')->default(false);
                $t->foreign(['clinic_id', 'facility_id'], $table.'_clinic_scope')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
            });
        }
        Schema::table('visits', function (Blueprint $t) {
            $t->string('dossier_visit_kind', 12)->nullable();
            $t->boolean('phase_three')->default(false);
            $t->unique(['id', 'dossier_id', 'facility_id'], 'visits_dossier_workflow_scope');
        });
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_workflow_kind CHECK (dossier_visit_kind IS NULL OR (dossier_id IS NOT NULL AND dossier_visit_kind IN ('initial','subsequent')))");
        // Existing initial selection is unchanged. Do not infer links for legacy visits.
        DB::statement("UPDATE visits v JOIN dossier_section_progress p ON p.visit_id=v.id AND p.dossier_id=v.dossier_id AND p.facility_id=v.facility_id AND p.section='visit' SET v.dossier_visit_kind='initial'");
        DB::statement('ALTER TABLE dossier_section_progress DROP CONSTRAINT dossier_progress_section, DROP CONSTRAINT dossier_progress_visit_section');
        Schema::table('dossier_section_progress', function (Blueprint $t) {
            $t->unsignedBigInteger('visit_scope')->storedAs('COALESCE(visit_id, 0)');
            $t->unique(['dossier_id', 'section', 'visit_scope'], 'dossier_progress_visit_unique');
            $t->foreign(['visit_id', 'dossier_id', 'facility_id'], 'dossier_progress_full_scope')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
        });
        Schema::table('dossier_section_progress', fn (Blueprint $t) => $t->dropUnique('dossier_progress_section_unique'));
        DB::statement("ALTER TABLE dossier_section_progress ADD CONSTRAINT dossier_progress_section CHECK (section IN ('personal','medical','visit','clinical','medications','attachments')), ADD CONSTRAINT dossier_progress_visit_section CHECK ((section IN ('personal','medical') AND visit_id IS NULL) OR section='visit' OR (section IN ('clinical','medications','attachments') AND visit_id IS NOT NULL))");
        Schema::table('visit_outcomes', function (Blueprint $t) {
            $t->date('outgoing_referral_date')->nullable();
            $t->text('outgoing_referral_reason')->nullable();
            $t->unsignedBigInteger('active_visit_id')->nullable()->storedAs('CASE WHEN voided_at IS NULL THEN visit_id ELSE NULL END');
            $t->unique('active_visit_id', 'visit_outcomes_one_active');
        });
        Schema::table('visit_outcomes', fn (Blueprint $t) => $t->dropUnique('unique_visit_outcomes_c9919ef5a0'));
        Schema::create('visit_prescriptions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('visit_id');
            $t->unsignedBigInteger('prescribing_clinic_id');
            $t->foreignId('prescribing_staff_id')->constrained('staff')->restrictOnDelete();
            $t->date('prescribed_on');
            $t->text('note')->nullable();
            $t->uuid('client_request_id');
            $this->actors($t);
            $t->unsignedBigInteger('active_visit_id')->nullable()->storedAs('CASE WHEN voided_at IS NULL THEN visit_id ELSE NULL END');
            $t->unique('active_visit_id', 'prescriptions_one_active');
            $t->unique(['visit_id', 'client_request_id'], 'prescriptions_request');
            $t->unique(['id', 'facility_id'], 'prescriptions_scope');
            $t->foreign(['visit_id', 'facility_id'], 'prescriptions_visit_scope')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete();
            $t->foreign(['prescribing_clinic_id', 'facility_id'], 'prescriptions_clinic_scope')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
        });
        $this->checks('visit_prescriptions');
        Schema::create('visit_prescription_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('prescription_id');
            $t->unsignedBigInteger('facility_id');
            $t->foreignId('medication_id')->constrained('medications')->restrictOnDelete();
            $t->string('medication_code_snapshot', 50);
            $t->string('medication_name_snapshot', 200);
            $t->text('note')->nullable();
            $t->unsignedInteger('display_order')->default(0);
            $this->actors($t);
            $t->foreign(['prescription_id', 'facility_id'], 'prescription_items_scope')->references(['id', 'facility_id'])->on('visit_prescriptions')->restrictOnDelete();
        });
        $this->checks('visit_prescription_items');
        Schema::create('visit_attachments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('visit_id');
            $t->string('title', 200);
            $t->string('original_filename', 200);
            $t->string('storage_key', 150)->unique();
            $t->string('mime_type', 100);
            $t->string('extension', 5);
            $t->unsignedBigInteger('size');
            $t->string('sha256', 64);
            $t->uuid('client_request_id');
            $this->actors($t);
            $t->unique(['visit_id', 'client_request_id'], 'attachments_request');
            $t->index(['dossier_id', 'created_at', 'id'], 'attachments_history');
            $t->foreign(['visit_id', 'dossier_id', 'facility_id'], 'attachments_full_scope')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
        });
        $this->checks('visit_attachments');
        DB::statement("ALTER TABLE visit_attachments ADD CONSTRAINT attachments_file CHECK (size > 0 AND extension IN ('pdf','xls','xlsx','jpg','jpeg','png','webp'))");
    }

    public function down(): void
    {
        // MariaDB DDL commits implicitly: complete every refusal check first.
        $used = DB::table('visit_prescriptions')->exists() || DB::table('visit_prescription_items')->exists() || DB::table('visit_attachments')->exists()
            || DB::table('visits')->where('phase_three', true)->orWhere('dossier_visit_kind', 'subsequent')->exists()
            || DB::table('dossier_section_progress')->whereIn('section', ['clinical', 'medications', 'attachments'])->exists();
        foreach (['visit_services', 'visit_procedures', 'visit_outcomes'] as $table) {
            $used = $used || DB::table($table)->whereNull('reporting_period_id')->orWhereNotNull('clinic_id')->orWhere('dossier_managed', true)->exists();
        }
        $used = $used || DB::table('visit_outcomes')->whereNotNull('outgoing_referral_date')->orWhereNotNull('outgoing_referral_reason')->exists()
            || DB::table('visit_outcomes')->select('visit_id')->groupBy('visit_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($used) {
            throw new RuntimeException('Phase 3 rollback refused before DDL: clinical/progress/attachment data must be preserved. No period or clinical fact is invented or deleted.');
        }
        Schema::drop('visit_attachments');
        Schema::drop('visit_prescription_items');
        Schema::drop('visit_prescriptions');
        Schema::table('visit_outcomes', function (Blueprint $t) {
            $t->unique('visit_id', 'unique_visit_outcomes_c9919ef5a0');
            $t->dropUnique('visit_outcomes_one_active');
            $t->dropColumn(['active_visit_id', 'outgoing_referral_date', 'outgoing_referral_reason']);
        });
        DB::statement('ALTER TABLE dossier_section_progress DROP CONSTRAINT dossier_progress_section, DROP CONSTRAINT dossier_progress_visit_section');
        Schema::table('dossier_section_progress', function (Blueprint $t) {
            $t->dropForeign('dossier_progress_full_scope');
            $t->unique(['dossier_id', 'section'], 'dossier_progress_section_unique');
            $t->dropUnique('dossier_progress_visit_unique');
            $t->dropColumn('visit_scope');
        });
        DB::statement("ALTER TABLE dossier_section_progress ADD CONSTRAINT dossier_progress_section CHECK (section IN ('personal','medical','visit')), ADD CONSTRAINT dossier_progress_visit_section CHECK (visit_id IS NULL OR section='visit')");
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_workflow_kind');
        Schema::table('visits', function (Blueprint $t) {
            $t->dropUnique('visits_dossier_workflow_scope');
            $t->dropColumn(['dossier_visit_kind', 'phase_three']);
        });
        foreach (['visit_services', 'visit_procedures', 'visit_outcomes'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign($table.'_clinic_scope');
                $t->dropColumn(['clinic_id', 'dossier_managed']);
            });
            DB::statement("ALTER TABLE `$table` MODIFY reporting_period_id BIGINT UNSIGNED NOT NULL");
        }
    }
};
