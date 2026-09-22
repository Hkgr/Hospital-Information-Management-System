<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_dossiers', function (Blueprint $t) {
            $t->decimal('weight_kg', 6, 2)->nullable()->after('clinical_history');
            $t->decimal('height_cm', 6, 2)->nullable()->after('weight_kg');
        });
        DB::statement('ALTER TABLE patient_dossiers ADD CONSTRAINT dossiers_weight_kg CHECK (weight_kg IS NULL OR (weight_kg > 0 AND weight_kg < 500)), ADD CONSTRAINT dossiers_height_cm CHECK (height_cm IS NULL OR (height_cm > 0 AND height_cm < 300))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patient_dossiers DROP CONSTRAINT dossiers_weight_kg, DROP CONSTRAINT dossiers_height_cm');
        Schema::table('patient_dossiers', function (Blueprint $t) {
            $t->dropColumn(['weight_kg', 'height_cm']);
        });
    }
};
