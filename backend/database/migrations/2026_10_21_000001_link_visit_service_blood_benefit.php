<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blood_bank_events', function (Blueprint $t) {
            $t->unique(['id', 'facility_id'], 'blood_events_id_facility_unique');
        });
        Schema::table('visit_services', function (Blueprint $t) {
            $t->unsignedBigInteger('blood_bank_event_id')->nullable()->unique();
            $t->foreign(['blood_bank_event_id', 'facility_id'], 'visit_service_blood_event_scope_fk')
                ->references(['id', 'facility_id'])->on('blood_bank_events')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('visit_services')->whereNotNull('blood_bank_event_id')->exists()) {
            throw new RuntimeException('Linked blood benefits exist. Rollback would discard their clinical links; retain this schema.');
        }
        Schema::table('visit_services', function (Blueprint $t) {
            $t->dropForeign('visit_service_blood_event_scope_fk');
            $t->dropColumn('blood_bank_event_id');
        });
        Schema::table('blood_bank_events', fn (Blueprint $t) => $t->dropUnique('blood_events_id_facility_unique'));
    }
};
