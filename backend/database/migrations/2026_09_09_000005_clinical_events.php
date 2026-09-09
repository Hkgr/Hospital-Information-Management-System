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
        Schema::create('visit_diagnoses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('diagnosed_on');
            $table->unsignedBigInteger('diagnosis_id');
            $table->unsignedBigInteger('diagnosing_staff_id');
            $table->string('diagnosis_status', 20)->default('final');
            $table->boolean('is_primary')->default(false);
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_visit_diagnoses_9e2441a782');
            $table->index(['visit_id', 'diagnosed_on'], 'index_visit_diagnoses_35cf5dfef7');
            $table->index(['facility_id', 'diagnosed_on'], 'index_visit_diagnoses_a718f7212b');
            $table->index(['reporting_period_id'], 'index_visit_diagnoses_273d2b68dc');
            $table->index(['diagnosis_id', 'diagnosed_on'], 'index_visit_diagnoses_423bf3c82e');
            $table->foreign(['facility_id'], 'fk_visit_diagnoses_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_id'], 'fk_visit_diagnoses_d5dbb1cc4e')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosing_staff_id'], 'fk_visit_diagnoses_f0d87f555b')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visit_diagnoses_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visit_diagnoses_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visit_diagnoses_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_visit_diagnoses_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visit_diagnoses_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_diagnoses` ADD CONSTRAINT `ck_visit_diagnoses_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_diagnoses` ADD CONSTRAINT `ck_visit_diagnoses_356a192b79` CHECK (diagnosis_status IN (\'provisional\',\'final\'))');
        }

        Schema::create('visit_services', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('performed_on');
            $table->unsignedBigInteger('service_id');
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('delivery_location', 20)->default('internal');
            $table->string('external_provider', 200)->nullable();
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_visit_services_9e2441a782');
            $table->index(['visit_id', 'performed_on'], 'index_visit_services_32a029eae0');
            $table->index(['facility_id', 'performed_on'], 'index_visit_services_20e9a25f07');
            $table->index(['reporting_period_id'], 'index_visit_services_273d2b68dc');
            $table->index(['service_id', 'performed_on'], 'index_visit_services_6d6c7d1eaf');
            $table->foreign(['facility_id'], 'fk_visit_services_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['service_id'], 'fk_visit_services_85a21558c0')->references(['id'])->on('services')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['performed_by'], 'fk_visit_services_084eb7ad1c')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visit_services_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visit_services_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visit_services_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_visit_services_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visit_services_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_services` ADD CONSTRAINT `ck_visit_services_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_services` ADD CONSTRAINT `ck_visit_services_356a192b79` CHECK (quantity > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_services` ADD CONSTRAINT `ck_visit_services_da4b9237ba` CHECK (delivery_location IN (\'internal\',\'external\'))');
        }

        Schema::create('visit_procedures', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('performed_on');
            $table->unsignedBigInteger('procedure_id');
            $table->unsignedBigInteger('specialist_id');
            $table->unsignedBigInteger('nurse_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_visit_procedures_9e2441a782');
            $table->index(['visit_id', 'performed_on'], 'index_visit_procedures_32a029eae0');
            $table->index(['facility_id', 'performed_on'], 'index_visit_procedures_20e9a25f07');
            $table->index(['reporting_period_id'], 'index_visit_procedures_273d2b68dc');
            $table->index(['procedure_id', 'performed_on'], 'index_visit_procedures_581c61ec73');
            $table->index(['specialist_id', 'performed_on'], 'index_visit_procedures_5199b1d315');
            $table->foreign(['facility_id'], 'fk_visit_procedures_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['procedure_id'], 'fk_visit_procedures_9888785b52')->references(['id'])->on('procedures')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['specialist_id'], 'fk_visit_procedures_461a4a90df')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['nurse_id'], 'fk_visit_procedures_f48bb9f67d')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visit_procedures_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visit_procedures_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visit_procedures_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_visit_procedures_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visit_procedures_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_procedures` ADD CONSTRAINT `ck_visit_procedures_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_procedures` ADD CONSTRAINT `ck_visit_procedures_356a192b79` CHECK (quantity > 0)');
        }

        Schema::create('dose_sessions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('administered_on');
            $table->unsignedBigInteger('supervising_staff_id')->nullable();
            $table->unsignedBigInteger('administered_by')->nullable();
            $table->string('session_label', 200)->nullable();
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_dose_sessions_9e2441a782');
            $table->index(['visit_id', 'administered_on'], 'index_dose_sessions_3ad29f052f');
            $table->index(['facility_id', 'administered_on'], 'index_dose_sessions_605144b895');
            $table->index(['reporting_period_id'], 'index_dose_sessions_273d2b68dc');
            $table->foreign(['facility_id'], 'fk_dose_sessions_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['supervising_staff_id'], 'fk_dose_sessions_391ddc646e')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['administered_by'], 'fk_dose_sessions_27983671d7')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_dose_sessions_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_dose_sessions_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_dose_sessions_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_dose_sessions_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_dose_sessions_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `dose_sessions` ADD CONSTRAINT `ck_dose_sessions_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('dose_session_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('dose_session_id');
            $table->unsignedBigInteger('medication_id')->nullable();
            $table->string('medication_name_snapshot', 200);
            $table->unsignedBigInteger('funding_source_id');
            $table->string('dose_text', 100)->nullable();
            $table->decimal('dose_value', 18, 4)->nullable();
            $table->string('dose_unit', 40)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('quantity_unit', 40)->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['dose_session_id'], 'index_dose_session_items_fd2b20d74a');
            $table->index(['medication_id', 'funding_source_id'], 'index_dose_session_items_6e1dbd19f0');
            $table->foreign(['dose_session_id'], 'fk_dose_session_items_fd2b20d74a')->references(['id'])->on('dose_sessions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['medication_id'], 'fk_dose_session_items_0682f5b737')->references(['id'])->on('medications')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['funding_source_id'], 'fk_dose_session_items_92519fb16d')->references(['id'])->on('funding_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_dose_session_items_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_dose_session_items_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `dose_session_items` ADD CONSTRAINT `ck_dose_session_items_b6589fc6ab` CHECK (dose_value IS NULL OR dose_value > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `dose_session_items` ADD CONSTRAINT `ck_dose_session_items_356a192b79` CHECK (quantity IS NULL OR quantity > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `dose_session_items` ADD CONSTRAINT `ck_dose_session_items_da4b9237ba` CHECK (dose_value IS NULL OR dose_unit IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `dose_session_items` ADD CONSTRAINT `ck_dose_session_items_77de68daec` CHECK (quantity IS NULL OR quantity_unit IS NOT NULL)');
        }

        Schema::create('visit_medications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('dispensed_on');
            $table->unsignedBigInteger('medication_id')->nullable();
            $table->string('medication_name_snapshot', 200);
            $table->unsignedBigInteger('funding_source_id')->nullable();
            $table->unsignedBigInteger('prescribing_staff_id')->nullable();
            $table->string('dose_text', 100)->nullable();
            $table->decimal('quantity', 18, 4)->nullable();
            $table->string('quantity_unit', 40)->nullable();
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_visit_medications_9e2441a782');
            $table->index(['visit_id', 'dispensed_on'], 'index_visit_medications_be831c4876');
            $table->index(['facility_id', 'dispensed_on'], 'index_visit_medications_f59705d505');
            $table->index(['reporting_period_id'], 'index_visit_medications_273d2b68dc');
            $table->index(['funding_source_id', 'dispensed_on'], 'index_visit_medications_cc56919f76');
            $table->foreign(['facility_id'], 'fk_visit_medications_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['medication_id'], 'fk_visit_medications_0682f5b737')->references(['id'])->on('medications')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['funding_source_id'], 'fk_visit_medications_92519fb16d')->references(['id'])->on('funding_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['prescribing_staff_id'], 'fk_visit_medications_df6683048a')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visit_medications_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visit_medications_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visit_medications_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_visit_medications_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visit_medications_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_medications` ADD CONSTRAINT `ck_visit_medications_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_medications` ADD CONSTRAINT `ck_visit_medications_356a192b79` CHECK (quantity IS NULL OR quantity > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_medications` ADD CONSTRAINT `ck_visit_medications_da4b9237ba` CHECK (quantity IS NULL OR quantity_unit IS NOT NULL)');
        }

        Schema::create('visit_outcomes', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('outcome_on');
            $table->unsignedBigInteger('result_id');
            $table->unsignedBigInteger('decided_by');
            $table->string('referral_target', 200)->nullable();
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_visit_outcomes_9e2441a782');
            $table->unique(['visit_id'], 'unique_visit_outcomes_c9919ef5a0');
            $table->index(['visit_id', 'outcome_on'], 'index_visit_outcomes_cb13f8abcd');
            $table->index(['facility_id', 'outcome_on'], 'index_visit_outcomes_3b259fc3f4');
            $table->index(['reporting_period_id'], 'index_visit_outcomes_273d2b68dc');
            $table->index(['result_id', 'outcome_on'], 'index_visit_outcomes_e5b7895e85');
            $table->foreign(['facility_id'], 'fk_visit_outcomes_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['result_id'], 'fk_visit_outcomes_c93b145f3c')->references(['id'])->on('visit_results')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['decided_by'], 'fk_visit_outcomes_8147d79214')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_visit_outcomes_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_visit_outcomes_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_visit_outcomes_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_visit_outcomes_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_visit_outcomes_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `visit_outcomes` ADD CONSTRAINT `ck_visit_outcomes_b6589fc6ab` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('case_reviews', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_outcome_id');
            $table->string('status', 20)->default('awaiting');
            $table->date('committee_date')->nullable();
            $table->text('requested_external_studies')->nullable();
            $table->text('committee_note')->nullable();
            $table->unsignedBigInteger('final_diagnosis_id')->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->unsignedBigInteger('followup_visit_id')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_outcome_id'], 'unique_case_reviews_aeb8dbaa1b');
            $table->foreign(['visit_outcome_id'], 'fk_case_reviews_aeb8dbaa1b')->references(['id'])->on('visit_outcomes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['final_diagnosis_id'], 'fk_case_reviews_f969180bf8')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['decided_by'], 'fk_case_reviews_8147d79214')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['followup_visit_id'], 'fk_case_reviews_800cabcc6a')->references(['id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_case_reviews_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `case_reviews` ADD CONSTRAINT `ck_case_reviews_b6589fc6ab` CHECK (status IN (\'awaiting\',\'reviewed\',\'cancelled\'))');
        }

        Schema::create('death_records', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->unsignedBigInteger('visit_outcome_id')->nullable();
            $table->date('death_date');
            $table->string('place', 20)->default('unknown');
            $table->unsignedBigInteger('cause_diagnosis_id')->nullable();
            $table->text('cause_note')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_outcome_id'], 'unique_death_records_aeb8dbaa1b');
            $table->index(['facility_id', 'death_date'], 'index_death_records_10f33dc1ac');
            $table->index(['patient_id', 'death_date'], 'index_death_records_a66d190f4b');
            $table->foreign(['facility_id'], 'fk_death_records_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_death_records_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_outcome_id'], 'fk_death_records_aeb8dbaa1b')->references(['id'])->on('visit_outcomes')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['cause_diagnosis_id'], 'fk_death_records_bfa883d6ff')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_death_records_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_death_records_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_death_records_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_death_records_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `death_records` ADD CONSTRAINT `ck_death_records_b6589fc6ab` CHECK (place IN (\'inside\',\'outside\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `death_records` ADD CONSTRAINT `ck_death_records_356a192b79` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('admissions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('visit_id');
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->unsignedBigInteger('patient_id');
            $table->date('admitted_on');
            $table->date('discharged_on')->nullable();
            $table->string('admission_type', 20)->default('routine');
            $table->text('note')->nullable();
            $table->string('client_request_id', 36);
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['visit_id', 'client_request_id'], 'unique_admissions_9e2441a782');
            $table->index(['facility_id', 'admitted_on'], 'index_admissions_79d8b02962');
            $table->index(['patient_id', 'admitted_on'], 'index_admissions_39dc7a5735');
            $table->foreign(['facility_id'], 'fk_admissions_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_admissions_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_admissions_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_admissions_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_admissions_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_admissions_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'patient_id'], 'fk_admissions_076554b0ec')->references(['id', 'patient_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_admissions_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `admissions` ADD CONSTRAINT `ck_admissions_b6589fc6ab` CHECK (discharged_on IS NULL OR discharged_on >= admitted_on)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `admissions` ADD CONSTRAINT `ck_admissions_356a192b79` CHECK (admission_type IN (\'routine\',\'emergency\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `admissions` ADD CONSTRAINT `ck_admissions_da4b9237ba` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('blood_donors', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('donor_code', 40);
            $table->string('full_name', 200);
            $table->string('national_id', 60)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('gender', 15)->default('unknown');
            $table->string('phone', 30)->nullable();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('rh', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('entered_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'donor_code'], 'unique_blood_donors_caaa55c8ff');
            $table->index(['full_name'], 'index_blood_donors_8510b934f7');
            $table->index(['national_id'], 'index_blood_donors_b52979b8a0');
            $table->foreign(['facility_id'], 'fk_blood_donors_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_blood_donors_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['governorate_id'], 'fk_blood_donors_852f1fafdf')->references(['id'])->on('governorates')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_blood_donors_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['city_id', 'governorate_id'], 'fk_blood_donors_8e225095bc')->references(['id', 'governorate_id'])->on('cities')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donors` ADD CONSTRAINT `ck_blood_donors_b6589fc6ab` CHECK (gender IN (\'male\',\'female\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donors` ADD CONSTRAINT `ck_blood_donors_356a192b79` CHECK (blood_group IS NULL OR blood_group IN (\'A\',\'B\',\'AB\',\'O\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donors` ADD CONSTRAINT `ck_blood_donors_da4b9237ba` CHECK (rh IS NULL OR rh IN (\'positive\',\'negative\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donors` ADD CONSTRAINT `ck_blood_donors_77de68daec` CHECK (city_id IS NULL OR governorate_id IS NOT NULL)');
        }

        Schema::create('blood_donations', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('donor_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->date('donated_on');
            $table->string('blood_group', 5);
            $table->string('rh', 10);
            $table->decimal('units', 18, 4)->default(1);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['facility_id', 'donated_on'], 'index_blood_donations_d53ff38e5b');
            $table->index(['donor_id', 'donated_on'], 'index_blood_donations_913d1d8ed5');
            $table->foreign(['facility_id'], 'fk_blood_donations_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['donor_id'], 'fk_blood_donations_51f7b00d11')->references(['id'])->on('blood_donors')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_blood_donations_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_blood_donations_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_blood_donations_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_blood_donations_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donations` ADD CONSTRAINT `ck_blood_donations_b6589fc6ab` CHECK (blood_group IN (\'A\',\'B\',\'AB\',\'O\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donations` ADD CONSTRAINT `ck_blood_donations_356a192b79` CHECK (rh IN (\'positive\',\'negative\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donations` ADD CONSTRAINT `ck_blood_donations_da4b9237ba` CHECK (units > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donations` ADD CONSTRAINT `ck_blood_donations_77de68daec` CHECK (status IN (\'pending\',\'accepted\',\'rejected\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donations` ADD CONSTRAINT `ck_blood_donations_1b64538924` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('blood_donation_screenings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('blood_donation_id');
            $table->unsignedBigInteger('screening_test_id');
            $table->string('result', 20);
            $table->date('tested_on');
            $table->unsignedBigInteger('entered_by');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['blood_donation_id', 'screening_test_id'], 'unique_blood_donation_screenings_feaa7afeef');
            $table->foreign(['blood_donation_id'], 'fk_blood_donation_screenings_53916e80e4')->references(['id'])->on('blood_donations')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['screening_test_id'], 'fk_blood_donation_screenings_8b71a8d6ea')->references(['id'])->on('screening_tests')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_blood_donation_screenings_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_donation_screenings` ADD CONSTRAINT `ck_blood_donation_screenings_b6589fc6ab` CHECK (result IN (\'negative\',\'positive\',\'indeterminate\',\'not_done\'))');
        }

        Schema::create('blood_transfusions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('reporting_period_id');
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->string('external_recipient_name', 200)->nullable();
            $table->string('beneficiary_entity', 200)->nullable();
            $table->string('address_line', 255)->nullable();
            $table->unsignedBigInteger('diagnosis_id')->nullable();
            $table->unsignedBigInteger('blood_component_id');
            $table->string('blood_group', 5)->nullable();
            $table->string('rh', 10)->nullable();
            $table->decimal('units', 18, 4)->default(1);
            $table->date('transfused_on');
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('voided_at')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->string('void_reason', 255)->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['facility_id', 'transfused_on'], 'index_blood_transfusions_1721c9312f');
            $table->index(['patient_id', 'transfused_on'], 'index_blood_transfusions_d6576b149f');
            $table->foreign(['facility_id'], 'fk_blood_transfusions_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_blood_transfusions_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_id'], 'fk_blood_transfusions_d5dbb1cc4e')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['blood_component_id'], 'fk_blood_transfusions_c23bec9a56')->references(['id'])->on('blood_components')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_blood_transfusions_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_blood_transfusions_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['voided_by'], 'fk_blood_transfusions_38e6c142a3')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reporting_period_id', 'facility_id'], 'fk_blood_transfusions_5fc8d62ae0')->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'facility_id'], 'fk_blood_transfusions_769a9cb1ee')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id', 'patient_id'], 'fk_blood_transfusions_076554b0ec')->references(['id', 'patient_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_b6589fc6ab` CHECK (patient_id IS NOT NULL OR external_recipient_name IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_356a192b79` CHECK (visit_id IS NULL OR patient_id IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_da4b9237ba` CHECK (blood_group IS NULL OR blood_group IN (\'A\',\'B\',\'AB\',\'O\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_77de68daec` CHECK (rh IS NULL OR rh IN (\'positive\',\'negative\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_1b64538924` CHECK (units > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_transfusions` ADD CONSTRAINT `ck_blood_transfusions_ac3478d69a` CHECK (((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL)))');
        }

        Schema::create('blood_recipient_procedures', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('blood_transfusion_id');
            $table->unsignedBigInteger('procedure_id');
            $table->unsignedBigInteger('specialist_id')->nullable();
            $table->date('performed_on');
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('entered_by');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['blood_transfusion_id', 'procedure_id', 'performed_on'], 'unique_blood_recipient_procedure_5d9ee11935');
            $table->foreign(['blood_transfusion_id'], 'fk_blood_recipient_procedure_4841b2afab')->references(['id'])->on('blood_transfusions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['procedure_id'], 'fk_blood_recipient_procedure_9888785b52')->references(['id'])->on('procedures')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['specialist_id'], 'fk_blood_recipient_procedure_461a4a90df')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_blood_recipient_procedure_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `blood_recipient_procedures` ADD CONSTRAINT `ck_blood_recipient_procedure_b6589fc6ab` CHECK (quantity > 0)');
        }

        Schema::create('cancer_cases', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('patient_id');
            $table->string('case_no', 40);
            $table->date('opened_on');
            $table->date('diagnosed_on')->nullable();
            $table->unsignedBigInteger('primary_diagnosis_id')->nullable();
            $table->text('disease_summary')->nullable();
            $table->text('medical_history')->nullable();
            $table->text('surgical_history')->nullable();
            $table->text('drug_history')->nullable();
            $table->text('family_history')->nullable();
            $table->text('prior_tests')->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('entered_by');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'case_no'], 'unique_cancer_cases_ccf58fd6eb');
            $table->index(['patient_id', 'opened_on'], 'index_cancer_cases_7f627ea0ac');
            $table->foreign(['facility_id'], 'fk_cancer_cases_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['patient_id'], 'fk_cancer_cases_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['primary_diagnosis_id'], 'fk_cancer_cases_070a58bc53')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_cancer_cases_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['updated_by'], 'fk_cancer_cases_a133791554')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `cancer_cases` ADD CONSTRAINT `ck_cancer_cases_b6589fc6ab` CHECK (status IN (\'active\',\'remission\',\'closed\'))');
        }

        Schema::create('cancer_case_diagnoses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('cancer_case_id');
            $table->unsignedBigInteger('diagnosis_id');
            $table->date('diagnosed_on');
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['cancer_case_id', 'diagnosis_id', 'diagnosed_on'], 'unique_cancer_case_diagnoses_2c6920573f');
            $table->foreign(['cancer_case_id'], 'fk_cancer_case_diagnoses_de32f30308')->references(['id'])->on('cancer_cases')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_id'], 'fk_cancer_case_diagnoses_d5dbb1cc4e')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['decided_by'], 'fk_cancer_case_diagnoses_8147d79214')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_cancer_case_diagnoses_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('cancer_treatments', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('cancer_case_id');
            $table->unsignedBigInteger('treatment_type_id');
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['cancer_case_id', 'started_on'], 'index_cancer_treatments_c73584ff62');
            $table->foreign(['cancer_case_id'], 'fk_cancer_treatments_de32f30308')->references(['id'])->on('cancer_cases')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['treatment_type_id'], 'fk_cancer_treatments_b7d7dcb549')->references(['id'])->on('cancer_treatment_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_cancer_treatments_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `cancer_treatments` ADD CONSTRAINT `ck_cancer_treatments_b6589fc6ab` CHECK (ended_on IS NULL OR started_on IS NULL OR ended_on >= started_on)');
        }

        Schema::create('cancer_case_related_people', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('cancer_case_id');
            $table->string('relation_type', 30);
            $table->string('full_name', 200);
            $table->string('phone', 30)->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['cancer_case_id'], 'index_cancer_case_related_peopl_de32f30308');
            $table->foreign(['cancer_case_id'], 'fk_cancer_case_related_peopl_de32f30308')->references(['id'])->on('cancer_cases')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_cancer_case_related_peopl_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `cancer_case_related_people` ADD CONSTRAINT `ck_cancer_case_related_peopl_b6589fc6ab` CHECK (relation_type IN (\'donor\',\'caregiver\',\'other\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cancer_case_related_people');
        Schema::dropIfExists('cancer_treatments');
        Schema::dropIfExists('cancer_case_diagnoses');
        Schema::dropIfExists('cancer_cases');
        Schema::dropIfExists('blood_recipient_procedures');
        Schema::dropIfExists('blood_transfusions');
        Schema::dropIfExists('blood_donation_screenings');
        Schema::dropIfExists('blood_donations');
        Schema::dropIfExists('blood_donors');
        Schema::dropIfExists('admissions');
        Schema::dropIfExists('death_records');
        Schema::dropIfExists('case_reviews');
        Schema::dropIfExists('visit_outcomes');
        Schema::dropIfExists('visit_medications');
        Schema::dropIfExists('dose_session_items');
        Schema::dropIfExists('dose_sessions');
        Schema::dropIfExists('visit_procedures');
        Schema::dropIfExists('visit_services');
        Schema::dropIfExists('visit_diagnoses');
    }
};
