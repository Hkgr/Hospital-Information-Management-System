<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            // Cover the scoped COUNT DISTINCT without fetching complete visit rows.
            $table->index(['facility_id', 'attending_staff_id', 'status', 'voided_at', 'patient_id'], 'visits_facility_doctor_patients_idx');
            $table->index(['facility_id', 'clinic_id', 'status', 'voided_at', 'patient_id'], 'visits_facility_clinic_patients_idx');
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropIndex('visits_facility_doctor_patients_idx');
            $table->dropIndex('visits_facility_clinic_patients_idx');
        });
    }
};
