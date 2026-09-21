<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dossier_import_batches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->string('original_filename', 255);
            $t->string('private_path', 255)->nullable();
            $t->char('file_hash', 64);
            $t->string('template_version', 30);
            $t->date('cutover_date');
            $t->string('purpose', 30);
            $t->string('status', 30)->default('uploaded');
            $t->unsignedInteger('total_rows')->default(0);
            $t->unsignedInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('validated_at')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamp('file_expires_at');
            $t->timestamp('file_deleted_at')->nullable();
            $t->string('failure_code', 60)->nullable();
            $t->timestamps();
            $t->unique(['facility_id', 'file_hash'], 'dossier_import_file_unique');
            $t->unique(['id', 'facility_id'], 'dossier_import_batch_scope');
            $t->index(['facility_id', 'id']);
        });
        Schema::create('dossier_import_rows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('batch_id');
            $t->unsignedBigInteger('facility_id');
            $t->string('sheet', 30);
            $t->unsignedInteger('row_number');
            $t->string('source_record_id', 100)->collation('utf8mb4_bin');
            $t->string('local_patient_ref', 100)->collation('utf8mb4_bin');
            $t->string('local_visit_ref', 100)->nullable()->collation('utf8mb4_bin');
            $t->char('fingerprint', 64);
            // Encrypted via Laravel's application key; never serialize to an API response.
            $t->longText('encrypted_payload')->nullable();
            $t->string('status', 30)->default('pending');
            $t->string('action', 30)->nullable();
            $t->json('errors')->nullable();
            $t->json('resolution')->nullable();
            $t->unsignedBigInteger('dossier_id')->nullable();
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
            $t->foreign(['batch_id', 'facility_id'], 'dossier_import_rows_batch_fk')->references(['id', 'facility_id'])->on('dossier_import_batches')->restrictOnDelete();
            $t->foreign(['dossier_id', 'facility_id'], 'dossier_import_rows_dossier_fk')->references(['id', 'facility_id'])->on('patient_dossiers')->restrictOnDelete();
            $t->foreign(['visit_id', 'dossier_id', 'facility_id'], 'dossier_import_rows_visit_fk')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
            $t->unique(['id', 'facility_id'], 'dossier_import_row_scope');
            $t->unique(['batch_id', 'sheet', 'source_record_id'], 'dossier_import_row_source_unique');
            $t->index(['batch_id', 'local_patient_ref', 'status'], 'dossier_import_bundle_index');
        });
        Schema::create('dossier_import_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->string('sheet', 30);
            $t->string('source_record_id', 100)->collation('utf8mb4_bin');
            $t->char('fingerprint', 64);
            $t->unsignedBigInteger('row_id');
            $t->foreign(['row_id', 'facility_id'], 'dossier_import_source_row_fk')->references(['id', 'facility_id'])->on('dossier_import_rows')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['facility_id', 'sheet', 'source_record_id'], 'dossier_import_committed_source_unique');
        });
    }

    public function down(): void
    {
        // Preflight before any DDL: even uncommitted uploads are retained evidence.
        if (DB::table('dossier_import_batches')->exists()) {
            throw new RuntimeException('Import history exists. Rollback refused before changing schema; retain its provenance.');
        }
        Schema::dropIfExists('dossier_import_sources');
        Schema::dropIfExists('dossier_import_rows');
        Schema::dropIfExists('dossier_import_batches');
    }
};
