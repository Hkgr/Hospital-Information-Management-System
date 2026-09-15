<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve every historical alias and its original owner. New contexts
        // have no code of their own: patients.patient_code is stored once.
        Schema::table('patient_dossiers', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->change();
            $table->index(['code', 'patient_id'], 'card_legacy_code_lookup');
        });
    }

    public function down(): void
    {
        if (DB::table('patient_dossiers')->whereNull('code')->exists()) {
            throw new RuntimeException('Code-free clinical contexts exist. Rollback would require invented codes; no schema or data was changed.');
        }
        Schema::table('patient_dossiers', function (Blueprint $table) {
            $table->dropIndex('card_legacy_code_lookup');
            $table->string('code', 60)->nullable(false)->change();
        });
    }
};
