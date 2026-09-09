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
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_report_definitions_e6fb06210f');
        });

        Schema::create('report_versions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_definition_id');
            $table->integer('version_number');
            $table->string('status', 20)->default('draft');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('source_reference', 255)->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_definition_id', 'version_number'], 'unique_report_versions_4fd09e663d');
            $table->unique(['id', 'report_definition_id'], 'unique_report_versions_82e8d38163');
            $table->foreign(['report_definition_id'], 'fk_report_versions_6adead064b')->references(['id'])->on('report_definitions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['created_by'], 'fk_report_versions_71a8aeb311')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `report_versions` ADD CONSTRAINT `ck_report_versions_b6589fc6ab` CHECK (status IN (\'draft\',\'published\',\'retired\'))');
        DB::statement('ALTER TABLE `report_versions` ADD CONSTRAINT `ck_report_versions_356a192b79` CHECK (effective_to IS NULL OR effective_to >= effective_from)');

        Schema::create('age_bands', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_version_id');
            $table->string('code', 40);
            $table->string('label_ar', 100);
            $table->integer('min_months');
            $table->integer('max_months_exclusive')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_version_id', 'code'], 'unique_age_bands_9b9385d7f6');
            $table->unique(['id', 'report_version_id'], 'unique_age_bands_6915b5d33f');
            $table->foreign(['report_version_id'], 'fk_age_bands_8004087a0a')->references(['id'])->on('report_versions')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `age_bands` ADD CONSTRAINT `ck_age_bands_b6589fc6ab` CHECK (min_months >= 0)');
        DB::statement('ALTER TABLE `age_bands` ADD CONSTRAINT `ck_age_bands_356a192b79` CHECK (max_months_exclusive IS NULL OR max_months_exclusive > min_months)');

        Schema::create('report_metrics', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_version_id');
            $table->string('code', 60);
            $table->string('label_ar', 200);
            $table->string('group_label', 120)->nullable();
            $table->string('source', 40);
            $table->string('aggregation', 40);
            $table->string('date_basis', 30)->default('event_date');
            $table->string('newness_basis', 30)->default('hospital');
            $table->string('split_by', 30)->default('none');
            $table->unsignedBigInteger('age_band_id')->nullable();
            $table->json('filters')->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->string('validation_status', 20)->default('pending');
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_version_id', 'code'], 'unique_report_metrics_9b9385d7f6');
            $table->unique(['id', 'report_version_id'], 'unique_report_metrics_6915b5d33f');
            $table->foreign(['report_version_id'], 'fk_report_metrics_8004087a0a')->references(['id'])->on('report_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['age_band_id', 'report_version_id'], 'fk_report_metrics_1375b1a48b')->references(['id', 'report_version_id'])->on('age_bands')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_b6589fc6ab` CHECK (source IN (\'visits\',\'diagnoses\',\'services\',\'procedures\',\'dose_sessions\',\'dose_items\',\'medications\',\'outcomes\',\'disabilities\',\'staff_work_days\',\'blood_donations\',\'blood_transfusions\',\'admissions\',\'deaths\',\'cancer_cases\'))');
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_356a192b79` CHECK (aggregation IN (\'count_events\',\'distinct_patients\',\'distinct_visits\',\'sum_quantity\',\'daily_average\'))');
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_da4b9237ba` CHECK (date_basis IN (\'event_date\',\'visit_date\'))');
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_77de68daec` CHECK (newness_basis IN (\'hospital\',\'clinic\'))');
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_1b64538924` CHECK (split_by IN (\'none\',\'gender\'))');
        DB::statement('ALTER TABLE `report_metrics` ADD CONSTRAINT `ck_report_metrics_ac3478d69a` CHECK (validation_status IN (\'pending\',\'verified\',\'rejected\'))');

        Schema::create('diagnosis_report_classifications', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_version_id');
            $table->unsignedBigInteger('diagnosis_id');
            $table->unsignedBigInteger('report_metric_id');
            $table->string('status', 20)->default('proposed');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_version_id', 'diagnosis_id'], 'unique_diagnosis_report_classifi_b83d2e605d');
            $table->foreign(['report_version_id'], 'fk_diagnosis_report_classifi_8004087a0a')->references(['id'])->on('report_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_id'], 'fk_diagnosis_report_classifi_d5dbb1cc4e')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['verified_by'], 'fk_diagnosis_report_classifi_59b6a5cf1a')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_metric_id', 'report_version_id'], 'fk_diagnosis_report_classifi_3c784e8bfb')->references(['id', 'report_version_id'])->on('report_metrics')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `diagnosis_report_classifications` ADD CONSTRAINT `ck_diagnosis_report_classifi_b6589fc6ab` CHECK (status IN (\'proposed\',\'verified\',\'rejected\'))');
        DB::statement('ALTER TABLE `diagnosis_report_classifications` ADD CONSTRAINT `ck_diagnosis_report_classifi_356a192b79` CHECK ((status = \'verified\' AND verified_by IS NOT NULL AND verified_at IS NOT NULL) OR (status <> \'verified\' AND verified_by IS NULL AND verified_at IS NULL))');

        Schema::create('report_metric_catalog_items', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_metric_id');
            $table->unsignedBigInteger('diagnosis_id')->nullable();
            $table->unsignedBigInteger('diagnosis_category_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedBigInteger('service_category_id')->nullable();
            $table->unsignedBigInteger('procedure_id')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->unsignedBigInteger('medication_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->unsignedBigInteger('funding_source_id')->nullable();
            $table->unsignedBigInteger('disability_type_id')->nullable();
            $table->string('mapping_status', 20)->default('pending');
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_metric_id', 'diagnosis_id'], 'unique_report_metric_catalog_ite_162c963b98');
            $table->unique(['report_metric_id', 'diagnosis_category_id'], 'unique_report_metric_catalog_ite_b9ea7b24fb');
            $table->unique(['report_metric_id', 'service_id'], 'unique_report_metric_catalog_ite_8d39d9e6dc');
            $table->unique(['report_metric_id', 'service_category_id'], 'unique_report_metric_catalog_ite_395e780565');
            $table->unique(['report_metric_id', 'procedure_id'], 'unique_report_metric_catalog_ite_b684b2d0ba');
            $table->unique(['report_metric_id', 'result_id'], 'unique_report_metric_catalog_ite_62f6ccfcc9');
            $table->unique(['report_metric_id', 'medication_id'], 'unique_report_metric_catalog_ite_f7a73681c9');
            $table->unique(['report_metric_id', 'staff_id'], 'unique_report_metric_catalog_ite_d6fb24deeb');
            $table->unique(['report_metric_id', 'clinic_id'], 'unique_report_metric_catalog_ite_864fe2ccc6');
            $table->unique(['report_metric_id', 'funding_source_id'], 'unique_report_metric_catalog_ite_9569a0e619');
            $table->unique(['report_metric_id', 'disability_type_id'], 'unique_report_metric_catalog_ite_27f1688e6c');
            $table->foreign(['report_metric_id'], 'fk_report_metric_catalog_ite_541f4802f7')->references(['id'])->on('report_metrics')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_id'], 'fk_report_metric_catalog_ite_d5dbb1cc4e')->references(['id'])->on('diagnoses')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['diagnosis_category_id'], 'fk_report_metric_catalog_ite_29ce7b68d0')->references(['id'])->on('diagnosis_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['service_id'], 'fk_report_metric_catalog_ite_85a21558c0')->references(['id'])->on('services')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['service_category_id'], 'fk_report_metric_catalog_ite_9d513b39d2')->references(['id'])->on('service_categories')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['procedure_id'], 'fk_report_metric_catalog_ite_9888785b52')->references(['id'])->on('procedures')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['result_id'], 'fk_report_metric_catalog_ite_c93b145f3c')->references(['id'])->on('visit_results')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['medication_id'], 'fk_report_metric_catalog_ite_0682f5b737')->references(['id'])->on('medications')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['staff_id'], 'fk_report_metric_catalog_ite_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['clinic_id'], 'fk_report_metric_catalog_ite_8e97c18deb')->references(['id'])->on('clinics')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['funding_source_id'], 'fk_report_metric_catalog_ite_92519fb16d')->references(['id'])->on('funding_sources')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['disability_type_id'], 'fk_report_metric_catalog_ite_bbe5f03cf0')->references(['id'])->on('disability_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['verified_by'], 'fk_report_metric_catalog_ite_59b6a5cf1a')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `report_metric_catalog_items` ADD CONSTRAINT `ck_report_metric_catalog_ite_b6589fc6ab` CHECK ((CASE WHEN diagnosis_id IS NULL THEN 0 ELSE 1 END + CASE WHEN diagnosis_category_id IS NULL THEN 0 ELSE 1 END + CASE WHEN service_id IS NULL THEN 0 ELSE 1 END + CASE WHEN service_category_id IS NULL THEN 0 ELSE 1 END + CASE WHEN procedure_id IS NULL THEN 0 ELSE 1 END + CASE WHEN result_id IS NULL THEN 0 ELSE 1 END + CASE WHEN medication_id IS NULL THEN 0 ELSE 1 END + CASE WHEN staff_id IS NULL THEN 0 ELSE 1 END + CASE WHEN clinic_id IS NULL THEN 0 ELSE 1 END + CASE WHEN funding_source_id IS NULL THEN 0 ELSE 1 END + CASE WHEN disability_type_id IS NULL THEN 0 ELSE 1 END) = 1)');
        DB::statement('ALTER TABLE `report_metric_catalog_items` ADD CONSTRAINT `ck_report_metric_catalog_ite_356a192b79` CHECK (mapping_status IN (\'pending\',\'verified\',\'rejected\'))');
        DB::statement('ALTER TABLE `report_metric_catalog_items` ADD CONSTRAINT `ck_report_metric_catalog_ite_da4b9237ba` CHECK ((mapping_status = \'verified\' AND verified_by IS NOT NULL AND verified_at IS NOT NULL) OR (mapping_status <> \'verified\' AND verified_by IS NULL AND verified_at IS NULL))');

        Schema::create('report_fields', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_version_id');
            $table->string('code', 60);
            $table->string('field_type', 20);
            $table->string('label_level_1', 255)->nullable();
            $table->string('label_level_2', 255)->nullable();
            $table->string('label_level_3', 255)->nullable();
            $table->unsignedBigInteger('report_metric_id')->nullable();
            $table->integer('display_order');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_version_id', 'code'], 'unique_report_fields_9b9385d7f6');
            $table->unique(['id', 'report_version_id'], 'unique_report_fields_6915b5d33f');
            $table->foreign(['report_version_id'], 'fk_report_fields_8004087a0a')->references(['id'])->on('report_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_metric_id', 'report_version_id'], 'fk_report_fields_3c784e8bfb')->references(['id', 'report_version_id'])->on('report_metrics')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `report_fields` ADD CONSTRAINT `ck_report_fields_b6589fc6ab` CHECK (field_type IN (\'metadata\',\'metric\',\'note\'))');
        DB::statement('ALTER TABLE `report_fields` ADD CONSTRAINT `ck_report_fields_356a192b79` CHECK ((field_type = \'metric\' AND report_metric_id IS NOT NULL) OR (field_type <> \'metric\' AND report_metric_id IS NULL))');

        Schema::create('report_runs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('report_version_id');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->integer('revision')->default(1);
            $table->json('filters');
            $table->json('definition_snapshot');
            $table->string('source_cutoff', 80);
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('generated_by');
            $table->dateTime('generated_at');
            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unsignedBigInteger('supersedes_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['id', 'report_version_id'], 'unique_report_runs_6915b5d33f');
            $table->index(['facility_id', 'starts_on', 'ends_on', 'report_version_id'], 'index_report_runs_54679267c9');
            $table->foreign(['facility_id'], 'fk_report_runs_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_version_id'], 'fk_report_runs_8004087a0a')->references(['id'])->on('report_versions')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['generated_by'], 'fk_report_runs_a2206c16a4')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['submitted_by'], 'fk_report_runs_4da5f901f2')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['supersedes_id'], 'fk_report_runs_ac77267208')->references(['id'])->on('report_runs')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `report_runs` ADD CONSTRAINT `ck_report_runs_b6589fc6ab` CHECK (ends_on >= starts_on)');
        DB::statement('ALTER TABLE `report_runs` ADD CONSTRAINT `ck_report_runs_356a192b79` CHECK (revision > 0)');

        Schema::create('report_values', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_run_id');
            $table->unsignedBigInteger('report_version_id');
            $table->unsignedBigInteger('report_metric_id');
            $table->string('dimension_key', 120)->default('all');
            $table->decimal('value', 18, 4);
            $table->json('dimensions');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_run_id', 'report_metric_id', 'dimension_key'], 'unique_report_values_c49158b062');
            $table->foreign(['report_run_id', 'report_version_id'], 'fk_report_values_42e41e0559')->references(['id', 'report_version_id'])->on('report_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_metric_id', 'report_version_id'], 'fk_report_values_3c784e8bfb')->references(['id', 'report_version_id'])->on('report_metrics')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('report_text_values', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('report_run_id');
            $table->unsignedBigInteger('report_version_id');
            $table->unsignedBigInteger('report_field_id');
            $table->text('value');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['report_run_id', 'report_field_id'], 'unique_report_text_values_fea78a08b8');
            $table->foreign(['report_run_id', 'report_version_id'], 'fk_report_text_values_42e41e0559')->references(['id', 'report_version_id'])->on('report_runs')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_field_id', 'report_version_id'], 'fk_report_text_values_c301bd6e09')->references(['id', 'report_version_id'])->on('report_fields')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('saved_searches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('user_id');
            $table->string('name', 150);
            $table->string('entity_type', 40);
            $table->json('filters');
            $table->json('columns');
            $table->json('sort');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'facility_id', 'name'], 'unique_saved_searches_3dbe618a56');
            $table->foreign(['facility_id'], 'fk_saved_searches_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['user_id'], 'fk_saved_searches_cace4a159f')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('export_jobs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('report_run_id')->nullable();
            $table->json('filters');
            $table->string('status', 20)->default('queued');
            $table->string('storage_key', 255)->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['requested_by', 'created_at'], 'index_export_jobs_ebaeae4b0c');
            $table->foreign(['facility_id'], 'fk_export_jobs_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['requested_by'], 'fk_export_jobs_7448af99fc')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['report_run_id'], 'fk_export_jobs_feeec78304')->references(['id'])->on('report_runs')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE `export_jobs` ADD CONSTRAINT `ck_export_jobs_b6589fc6ab` CHECK (status IN (\'queued\',\'running\',\'complete\',\'failed\',\'expired\'))');
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('report_text_values');
        Schema::dropIfExists('report_values');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_fields');
        Schema::dropIfExists('report_metric_catalog_items');
        Schema::dropIfExists('diagnosis_report_classifications');
        Schema::dropIfExists('report_metrics');
        Schema::dropIfExists('age_bands');
        Schema::dropIfExists('report_versions');
        Schema::dropIfExists('report_definitions');
    }
};
