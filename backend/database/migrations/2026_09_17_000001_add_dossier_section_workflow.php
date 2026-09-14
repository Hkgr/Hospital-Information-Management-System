<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function periods(string $nullability): void
    {
        foreach (['visits', 'visit_diagnoses'] as $table) {
            DB::statement("ALTER TABLE `$table` MODIFY reporting_period_id BIGINT UNSIGNED $nullability");
        }
    }

    public function up(): void
    {
        $this->periods('NULL');
        Schema::table('visits', function (Blueprint $t) {
            $t->boolean('is_referred')->default(false);
            $t->string('referring_hospital', 200)->nullable();
            $t->date('referral_date')->nullable();
            $t->text('referral_reason')->nullable();
        });
        DB::statement('ALTER TABLE visits ADD CONSTRAINT visits_referral CHECK ((is_referred = 0 AND referring_hospital IS NULL AND referral_date IS NULL AND referral_reason IS NULL) OR (is_referred = 1 AND referring_hospital IS NOT NULL AND CHAR_LENGTH(TRIM(referring_hospital)) > 0 AND referral_date IS NOT NULL AND referral_date <= visit_date AND referral_reason IS NOT NULL AND CHAR_LENGTH(TRIM(referral_reason)) > 0))');
        Schema::table('dossier_oncology_selections', fn (Blueprint $t) => $t->boolean('is_active')->default(true));
        Schema::create('dossier_section_progress', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('facility_id');
            $t->string('section', 20);
            $t->string('state', 20)->default('not_started');
            $t->foreignId('last_saved_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->dateTime('last_saved_at')->nullable();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->unique(['dossier_id', 'section'], 'dossier_progress_section_unique');
            $t->foreign(['dossier_id', 'facility_id'], 'dossier_progress_scope_fk')->references(['id', 'facility_id'])->on('patient_dossiers')->restrictOnDelete();
            $t->foreign(['visit_id', 'facility_id'], 'dossier_progress_visit_fk')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE dossier_section_progress ADD CONSTRAINT dossier_progress_section CHECK (section IN ('personal','medical','visit')), ADD CONSTRAINT dossier_progress_state CHECK (state IN ('not_started','in_progress','saved','needs_review')), ADD CONSTRAINT dossier_progress_version CHECK (lock_version >= 1), ADD CONSTRAINT dossier_progress_visit_section CHECK (visit_id IS NULL OR section = 'visit')");
        Schema::create('dossier_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->dateTime('created_at');
            $t->unique(['facility_id', 'user_id', 'request_id'], 'dossier_request_unique');
        });
    }

    public function down(): void
    {
        // MariaDB DDL commits: refuse ALL data-loss conditions before the first ALTER/DROP.
        if (DB::table('visits')->whereNull('reporting_period_id')->orWhere('is_referred', true)->exists()
            || DB::table('visit_diagnoses')->whereNull('reporting_period_id')->exists()
            || DB::table('dossier_section_progress')->exists() || DB::table('dossier_requests')->exists()
            || DB::table('dossier_oncology_selections')->where('is_active', false)->exists()) {
            throw new RuntimeException('Dossier workflow rollback refused: saved progress, referrals, requests, inactive history or NULL periods must be preserved. No periods are invented and no records are deleted.');
        }
        $this->periods('NOT NULL');
        Schema::drop('dossier_requests');
        Schema::drop('dossier_section_progress');
        Schema::table('dossier_oncology_selections', fn (Blueprint $t) => $t->dropColumn('is_active'));
        DB::statement('ALTER TABLE visits DROP CONSTRAINT visits_referral');
        Schema::table('visits', fn (Blueprint $t) => $t->dropColumn(['is_referred', 'referring_hospital', 'referral_date', 'referral_reason']));
    }
};
