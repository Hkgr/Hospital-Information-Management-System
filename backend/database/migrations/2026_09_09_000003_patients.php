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
        Schema::create('patients', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('patient_code', 40);
            $table->string('first_name', 80);
            $table->string('father_name', 80)->nullable();
            $table->string('family_name', 80);
            $table->string('mother_name', 120)->nullable();
            $table->string('search_name', 350);
            $table->date('birth_date')->nullable();
            $table->string('birth_date_accuracy', 20)->default('unknown');
            $table->string('gender', 15)->default('unknown');
            $table->string('phone', 30)->nullable();
            $table->string('alt_phone', 30)->nullable();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->string('address_line', 255)->nullable();
            $table->string('displacement_status', 20)->default('unknown');
            $table->string('identity_document_type', 40);
            $table->string('identity_check_status', 20)->default('pending');
            $table->string('verified_by_name', 200)->nullable();
            $table->dateTime('identity_verified_at')->nullable();
            $table->string('paper_file_number', 60)->nullable();
            $table->boolean('history_complete')->default(false);
            $table->date('first_known_visit_date')->nullable();
            $table->unsignedBigInteger('created_by');
            $table->string('status', 15)->default('active');
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['patient_code'], 'unique_patients_362551a5f4');
            $table->index(['search_name'], 'index_patients_8858365cc0');
            $table->index(['phone'], 'index_patients_f6be6ca910');
            $table->index(['birth_date', 'gender'], 'index_patients_c1a0ddff72');
            $table->index(['paper_file_number'], 'index_patients_aa4722d6de');
            $table->foreign(['governorate_id'], 'fk_patients_852f1fafdf')->references(['id'])->on('governorates')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['created_by'], 'fk_patients_71a8aeb311')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['city_id', 'governorate_id'], 'fk_patients_8e225095bc')->references(['id', 'governorate_id'])->on('cities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['merged_into_id'], 'fk_patients_9fd879e03a')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_b6589fc6ab` CHECK (gender IN (\'male\',\'female\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_356a192b79` CHECK (birth_date_accuracy IN (\'exact\',\'year_only\',\'estimated\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_da4b9237ba` CHECK (displacement_status IN (\'resident\',\'idp\',\'unknown\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_77de68daec` CHECK (identity_check_status IN (\'pending\',\'verified\'))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_1b64538924` CHECK (city_id IS NULL OR governorate_id IS NOT NULL)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_ac3478d69a` CHECK ((status = \'active\' AND merged_into_id IS NULL) OR (status = \'merged\' AND merged_into_id IS NOT NULL))');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patients` ADD CONSTRAINT `ck_patients_c1dfd96eea` CHECK (birth_date IS NOT NULL OR birth_date_accuracy = \'unknown\')');
        }

        Schema::create('patient_identifiers', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->string('identifier_type', 40);
            $table->string('issuer', 80);
            $table->string('value', 100);
            $table->string('normalized_value', 100);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['identifier_type', 'issuer', 'normalized_value'], 'unique_patient_identifiers_4861917e24');
            $table->index(['patient_id'], 'index_patient_identifiers_8dfa510bb2');
            $table->foreign(['patient_id'], 'fk_patient_identifiers_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('patient_disabilities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->unsignedBigInteger('disability_type_id');
            $table->date('documented_on');
            $table->string('card_reference', 80)->nullable();
            $table->unsignedBigInteger('entered_by');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['patient_id', 'disability_type_id'], 'unique_patient_disabilities_aebee8d7eb');
            $table->foreign(['patient_id'], 'fk_patient_disabilities_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['disability_type_id'], 'fk_patient_disabilities_bbe5f03cf0')->references(['id'])->on('disability_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_patient_disabilities_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('patient_search_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('patient_id');
            $table->string('token', 100);
            $table->unique(['patient_id', 'token'], 'unique_patient_search_tokens_419dfcf6bb');
            $table->index(['token', 'patient_id'], 'index_patient_search_tokens_3299e0578f');
            $table->foreign(['patient_id'], 'fk_patient_search_tokens_8dfa510bb2')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('number_sequences', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('sequence_key', 40);
            $table->string('scope_key', 60);
            $table->string('period_key', 20)->default('all');
            $table->string('prefix', 20)->nullable();
            $table->unsignedBigInteger('current_value')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['sequence_key', 'scope_key', 'period_key'], 'unique_number_sequences_8d2edb8730');
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `number_sequences` ADD CONSTRAINT `ck_number_sequences_b6589fc6ab` CHECK (current_value >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
        Schema::dropIfExists('patient_search_tokens');
        Schema::dropIfExists('patient_disabilities');
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('patients');
    }
};
