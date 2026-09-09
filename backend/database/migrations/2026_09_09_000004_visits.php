<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_periods', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('locked_by')->nullable();
            $table->dateTime('locked_at')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'starts_on'], 'unique_reporting_periods_7b7afeb036');
            $table->unique(['id', 'facility_id'], 'unique_reporting_periods_62fab0bea7');
            $table->foreign(['facility_id'], 'fk_reporting_periods_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['submitted_by'], 'fk_reporting_periods_4da5f901f2')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['locked_by'], 'fk_reporting_periods_d3241209a9')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `reporting_periods` ADD CONSTRAINT `ck_reporting_periods_b6589fc6ab` CHECK (ends_on >= starts_on)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `reporting_periods` ADD CONSTRAINT `ck_reporting_periods_356a192b79` CHECK (status IN (\'open\',\'submitted\',\'locked\'))');
        }

        Schema::create('visits', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('visit_no', 40);
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('visit_date');
            $table->unsignedBigInteger('visit_type_id');
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('attending_staff_id')->nullable();
            $table->unsignedBigInteger('resident_staff_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('newness_override', 20)->nullable();
            $table->string('newness_reason', 255)->nullable();
            $table->string('paper_reference', 100)->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_no'], 'unique_visits_11de3ad4e8');
            $table->unique(['facility_id', 'client_request_id'], 'unique_visits_5a7185a1b9');
            $table->unique(['id', 'facility_id'], 'unique_visits_62fab0bea7');
            $table->unique(['id', 'patient_id'], 'unique_visits_839d66e6c3');
            $table->index(['patient_id', 'visit_date', 'id'], 'index_visits_c0ac1c440f');
            $table->index(['facility_id', 'visit_date', 'status'], 'index_visits_750c8b13d5');
            $table->index(['attending_staff_id', 'visit_date'], 'index_visits_7141c11a30');
            $table->index(['clinic_id', 'visit_date'], 'index_visits_c41b18e29d');
            $table->index(['reporting_period_id', 'status'], 'index_visits_d10fd4b2eb');
            $table->foreign(['facility_id'], 'fk_visits_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_visits_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_type_id'], 'fk_visits_edf5049a91')->references(['id'])->on('visit_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['attending_staff_id'], 'fk_visits_028dabba3f')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['resident_staff_id'], 'fk_visits_692496148d')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visits_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visits_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visits_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visits_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['clinic_id', 'facility_id'], 'fk_visits_7c0a1e0767')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_b6589fc6ab` CHECK (status IN (\'draft\',\'complete\',\'void\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_356a192b79` CHECK (status <> \'complete\' OR attending_staff_id IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_da4b9237ba` CHECK ((status = \'void\' AND voided_at IS NOT NULL) OR (status <> \'void\' AND voided_at IS NULL))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_77de68daec` CHECK (newness_override IS NULL OR newness_override IN (\'new\',\'returning\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_1b64538924` CHECK (newness_override IS NULL OR newness_reason IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visits` ADD CONSTRAINT `ck_visits_ac3478d69a` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('visit_demographics', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->string('gender', 15);
            $table->date('birth_date')->nullable();
            $table->string('birth_date_accuracy', 20);
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('displacement_status', 20);
            $table->json('disability_codes');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id'], 'unique_visit_demographics_c9919ef5a0');
            $table->foreign(['visit_id'], 'fk_visit_demographics_c9919ef5a0')->references(['id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['governorate_id'], 'fk_visit_demographics_852f1fafdf')->references(['id'])->on('governorates')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_demographics` ADD CONSTRAINT `ck_visit_demographics_b6589fc6ab` CHECK (gender IN (\'male\',\'female\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_demographics` ADD CONSTRAINT `ck_visit_demographics_356a192b79` CHECK (birth_date_accuracy IN (\'exact\',\'year_only\',\'estimated\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_demographics` ADD CONSTRAINT `ck_visit_demographics_da4b9237ba` CHECK (displacement_status IN (\'resident\',\'idp\',\'unknown\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_demographics');
        Schema::dropIfExists('visits');
        Schema::dropIfExists('reporting_periods');
    }
};
