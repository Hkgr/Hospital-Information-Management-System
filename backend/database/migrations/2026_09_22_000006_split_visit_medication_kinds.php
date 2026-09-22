<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_medications', function (Blueprint $t) {
            $t->unsignedBigInteger('prescribing_clinic_id')->nullable()->after('prescribing_staff_id');
            $t->foreign(['prescribing_clinic_id', 'facility_id'], 'onc_dispense_clinic')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE visit_medications DROP CONSTRAINT onc_dispense_purpose');
        DB::statement("ALTER TABLE visit_medications ADD CONSTRAINT onc_dispense_purpose CHECK (dispensing_purpose IS NULL OR (dispensing_purpose = 'unlinked' AND dose_session_id IS NULL) OR dispensing_purpose IN ('take_home','supportive'))");
    }

    public function down(): void
    {
        if (DB::table('visit_medications')->where('dispensing_purpose', 'unlinked')->orWhereNotNull('prescribing_clinic_id')->exists()) {
            throw new RuntimeException('Medication-kind rollback is not retained: unlinked dispensing or its clinic is already stored.');
        }
        DB::statement('ALTER TABLE visit_medications DROP CONSTRAINT onc_dispense_purpose');
        DB::statement("ALTER TABLE visit_medications ADD CONSTRAINT onc_dispense_purpose CHECK (dispensing_purpose IS NULL OR dispensing_purpose IN ('take_home','supportive'))");
        Schema::table('visit_medications', function (Blueprint $t) {
            $t->dropForeign('onc_dispense_clinic');
        });
        Schema::table('visit_medications', function (Blueprint $t) {
            if (Schema::hasIndex('visit_medications', 'onc_dispense_clinic')) {
                $t->dropIndex('onc_dispense_clinic');
            }
            $t->dropColumn('prescribing_clinic_id');
        });
    }
};
