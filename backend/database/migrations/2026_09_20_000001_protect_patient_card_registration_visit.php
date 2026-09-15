<?php

use App\Services\Dossiers\PatientCardInventory;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $inventory = app(PatientCardInventory::class)->report();
        if ($inventory['duplicate_canonical_code_groups'] || $inventory['invalid_canonical_codes']) {
            throw new RuntimeException('Canonical patient-code collisions or invalid codes require operator review. Run patients:reconcile-cards --details. No schema or data was changed.');
        }
        // patients.id / patient_code already uniquely identify the global card.
        // Keep old context IDs, codes and all scope FKs intact. Do not invent or
        // guess registration visits for legacy contexts.
        Schema::table('visits', function (Blueprint $table) {
            $table->unique(['id', 'facility_id', 'patient_id', 'dossier_id'], 'visits_card_registration_scope');
        });
        Schema::table('patient_dossiers', function (Blueprint $table) {
            $table->unsignedBigInteger('registration_visit_id')->nullable();
            $table->foreign(['registration_visit_id', 'facility_id', 'patient_id', 'id'], 'card_registration_visit_fk')
                ->references(['id', 'facility_id', 'patient_id', 'dossier_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        if (DB::table('patient_dossiers')->whereNotNull('registration_visit_id')->exists()) {
            throw new RuntimeException('Patient-card registration history exists; rollback would remove its protection. No data or schema was changed.');
        }
        Schema::table('patient_dossiers', function (Blueprint $table) {
            $table->dropForeign('card_registration_visit_fk');
            $table->dropColumn('registration_visit_id');
        });
        Schema::table('visits', fn (Blueprint $table) => $table->dropUnique('visits_card_registration_scope'));
    }
};
