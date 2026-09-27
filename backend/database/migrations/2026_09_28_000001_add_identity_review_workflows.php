<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('lock_version')->default(1));
        Schema::create('reception_identity_windows', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('dossier_id')->unique();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('patient_id');
            $t->foreign(['dossier_id', 'facility_id', 'patient_id'], 'reception_window_scope')->references(['id', 'facility_id', 'patient_id'])->on('patient_dossiers')->restrictOnDelete();
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->json('fields');
            $t->unsignedBigInteger('patient_version');
            $t->dateTime('expires_at');
            $t->timestamps();
        });
        Schema::create('patient_identity_corrections', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('patient_id');
            $t->foreign(['dossier_id', 'facility_id', 'patient_id'], 'identity_correction_scope')->references(['id', 'facility_id', 'patient_id'])->on('patient_dossiers')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->json('baseline');
            $t->json('proposed');
            $t->unsignedBigInteger('patient_version');
            $t->unsignedBigInteger('dossier_version');
            $t->string('scope_fingerprint', 64);
            $t->string('reason', 255);
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decision_reason', 255)->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['facility_id', 'status', 'id']);
        });
        Schema::create('patient_duplicate_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('canonical_patient_id')->constrained('patients')->restrictOnDelete();
            $t->foreignId('duplicate_patient_id')->constrained('patients')->restrictOnDelete();
            $t->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $t->json('preview');
            $t->string('reason', 255);
            $t->string('status', 20)->default('pending');
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('decision_reason', 255)->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['facility_id', 'status', 'id']);
        });
        foreach (['patient_identity_corrections', 'patient_duplicate_reviews'] as $table) {
            DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_status` CHECK (status IN ('pending','approved','rejected')), ADD CONSTRAINT `{$table}_version` CHECK (lock_version >= 1)");
        }
    }

    public function down(): void
    {
        foreach (['reception_identity_windows', 'patient_identity_corrections', 'patient_duplicate_reviews'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Review workflow data exists; rollback will not delete identity history.');
            }
        }
        Schema::dropIfExists('patient_duplicate_reviews');
        Schema::dropIfExists('patient_identity_corrections');
        Schema::dropIfExists('reception_identity_windows');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('lock_version'));
    }
};
