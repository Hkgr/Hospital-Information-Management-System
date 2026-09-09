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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->string('event', 30);
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 255)->nullable();
            $table->string('request_id', 36);
            $table->string('ip_address', 45)->nullable();
            $table->dateTime('occurred_at');
            $table->index(['entity_type', 'entity_id', 'occurred_at'], 'index_audit_logs_94e2f15739');
            $table->index(['facility_id', 'occurred_at'], 'index_audit_logs_cce921b94e');
            $table->index(['actor_id', 'occurred_at'], 'index_audit_logs_4049e5e14a');
            $table->foreign(['facility_id'], 'fk_audit_logs_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['actor_id'], 'fk_audit_logs_05b325494f')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('correction_requests', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('visit_id');
            $table->string('entity_type', 80);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('reason', 255);
            $table->json('proposed_changes');
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->foreign(['facility_id'], 'fk_correction_requests_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id'], 'fk_correction_requests_c9919ef5a0')->references(['id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['requested_by'], 'fk_correction_requests_7448af99fc')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['reviewed_by'], 'fk_correction_requests_c1366b3862')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `correction_requests` ADD CONSTRAINT `ck_correction_requests_b6589fc6ab` CHECK (status IN (\'pending\',\'approved\',\'rejected\'))');
        }

        Schema::create('entry_drafts', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('visit_id')->nullable();
            $table->string('client_draft_id', 36);
            $table->json('payload');
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['user_id', 'client_draft_id'], 'unique_entry_drafts_8eb22dba27');
            $table->index(['facility_id', 'user_id'], 'index_entry_drafts_c43488ccf0');
            $table->foreign(['facility_id'], 'fk_entry_drafts_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['user_id'], 'fk_entry_drafts_cace4a159f')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['visit_id'], 'fk_entry_drafts_c9919ef5a0')->references(['id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('patient_merges', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('source_patient_id');
            $table->unsignedBigInteger('target_patient_id');
            $table->unsignedBigInteger('merged_by');
            $table->string('reason', 255);
            $table->json('source_snapshot');
            $table->json('target_snapshot');
            $table->json('moved_record_manifest');
            $table->dateTime('merged_at');
            $table->dateTime('reverted_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['source_patient_id'], 'index_patient_merges_10a40976e6');
            $table->index(['target_patient_id'], 'index_patient_merges_98f729cab0');
            $table->foreign(['source_patient_id'], 'fk_patient_merges_10a40976e6')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['target_patient_id'], 'fk_patient_merges_98f729cab0')->references(['id'])->on('patients')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['merged_by'], 'fk_patient_merges_402d733850')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `patient_merges` ADD CONSTRAINT `ck_patient_merges_b6589fc6ab` CHECK (source_patient_id <> target_patient_id)');
        }

        Schema::create('import_batches', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->string('source_name', 200);
            $table->string('file_sha256', 64);
            $table->string('mapping_version', 40);
            $table->string('status', 20)->default('staged');
            $table->unsignedBigInteger('created_by');
            $table->json('summary')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'file_sha256', 'mapping_version'], 'unique_import_batches_2d5cbee889');
            $table->foreign(['facility_id'], 'fk_import_batches_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['created_by'], 'fk_import_batches_71a8aeb311')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `import_batches` ADD CONSTRAINT `ck_import_batches_b6589fc6ab` CHECK (status IN (\'staged\',\'validated\',\'imported\',\'failed\'))');
        }

        Schema::create('import_rows', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('import_batch_id');
            $table->unsignedBigInteger('row_number');
            $table->string('source_key', 120);
            $table->json('payload');
            $table->json('errors')->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('target_type', 80)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['import_batch_id', 'row_number'], 'unique_import_rows_9a374b7917');
            $table->unique(['import_batch_id', 'source_key'], 'unique_import_rows_c5be609403');
            $table->foreign(['import_batch_id'], 'fk_import_rows_f408daa3bb')->references(['id'])->on('import_batches')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `import_rows` ADD CONSTRAINT `ck_import_rows_b6589fc6ab` CHECK (status IN (\'pending\',\'valid\',\'invalid\',\'imported\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('patient_merges');
        Schema::dropIfExists('entry_drafts');
        Schema::dropIfExists('correction_requests');
        Schema::dropIfExists('audit_logs');
    }
};
