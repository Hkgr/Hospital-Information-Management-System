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
        Schema::create('governorates', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 30);
            $table->string('name_ar', 120);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_governorates_e6fb06210f');
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('governorate_id');
            $table->string('name_ar', 120);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['governorate_id', 'name_ar'], 'unique_cities_a7c55a461d');
            $table->unique(['id', 'governorate_id'], 'unique_cities_25df84e96f');
            $table->foreign(['governorate_id'], 'fk_cities_852f1fafdf')->references(['id'])->on('governorates')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('facilities', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 30);
            $table->string('name_ar', 200);
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('timezone', 60)->default('Asia/Damascus');
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_facilities_e6fb06210f');
            $table->foreign(['governorate_id'], 'fk_facilities_852f1fafdf')->references(['id'])->on('governorates')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('staff_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_staff_types_e6fb06210f');
        });

        Schema::create('specialties', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_specialties_e6fb06210f');
        });

        Schema::create('funding_sources', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_funding_sources_e6fb06210f');
        });

        Schema::create('service_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_service_categories_e6fb06210f');
        });

        Schema::create('diagnosis_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_diagnosis_categories_e6fb06210f');
        });

        Schema::create('medication_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_medication_categories_e6fb06210f');
        });

        Schema::create('procedure_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_procedure_types_e6fb06210f');
        });

        Schema::create('disability_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_disability_types_e6fb06210f');
        });

        Schema::create('equipment_categories', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_equipment_categories_e6fb06210f');
        });

        Schema::create('blood_components', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_blood_components_e6fb06210f');
        });

        Schema::create('screening_tests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_screening_tests_e6fb06210f');
        });

        Schema::create('cancer_treatment_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_cancer_treatment_types_e6fb06210f');
        });

        Schema::create('visit_types', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_visit_types_e6fb06210f');
        });

        Schema::create('visit_results', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->string('result_group', 30);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_visit_results_e6fb06210f');
        });

        Schema::create('diagnoses', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('icd10_code', 20)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_diagnoses_e6fb06210f');
            $table->foreign(['category_id'], 'fk_diagnoses_9c4e4a89e3')->references(['id'])->on('diagnosis_categories')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('services', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->unsignedBigInteger('category_id');
            $table->boolean('allow_external')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_services_e6fb06210f');
            $table->foreign(['category_id'], 'fk_services_9c4e4a89e3')->references(['id'])->on('service_categories')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('procedures', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->unsignedBigInteger('procedure_type_id')->nullable();
            $table->boolean('requires_specialist')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_procedures_e6fb06210f');
            $table->foreign(['procedure_type_id'], 'fk_procedures_a93c3a4c82')->references(['id'])->on('procedure_types')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('default_unit', 40)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_medications_e6fb06210f');
            $table->foreign(['category_id'], 'fk_medications_9c4e4a89e3')->references(['id'])->on('medication_categories')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medications');
        Schema::dropIfExists('procedures');
        Schema::dropIfExists('services');
        Schema::dropIfExists('diagnoses');
        Schema::dropIfExists('visit_results');
        Schema::dropIfExists('visit_types');
        Schema::dropIfExists('cancer_treatment_types');
        Schema::dropIfExists('screening_tests');
        Schema::dropIfExists('blood_components');
        Schema::dropIfExists('equipment_categories');
        Schema::dropIfExists('disability_types');
        Schema::dropIfExists('procedure_types');
        Schema::dropIfExists('medication_categories');
        Schema::dropIfExists('diagnosis_categories');
        Schema::dropIfExists('service_categories');
        Schema::dropIfExists('funding_sources');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('staff_types');
        Schema::dropIfExists('facilities');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('governorates');
    }
};
