<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->string('kind', 20)->default('unlinked')->after('visit_id');
            $t->unsignedBigInteger('funding_source_id')->nullable()->after('note');
            $t->text('unavailable_reason')->nullable()->after('funding_source_id');
            $t->foreign('funding_source_id', 'prescriptions_funding')->references('id')->on('funding_sources')->restrictOnDelete();
        });
        DB::table('visit_prescriptions')->update(['kind' => 'unlinked', 'funding_source_id' => null, 'unavailable_reason' => null]);
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->dropUnique('prescriptions_one_active');
        });
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->dropColumn('active_visit_id');
        });
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->string('active_kind_key', 48)->nullable()->storedAs("CASE WHEN voided_at IS NULL THEN CONCAT(visit_id, ':', kind) ELSE NULL END");
            $t->unique('active_kind_key', 'prescriptions_one_kind');
        });
        DB::statement("ALTER TABLE visit_prescriptions ADD CONSTRAINT rx_kind_fields CHECK ((kind = 'unlinked' AND funding_source_id IS NULL AND unavailable_reason IS NULL) OR (kind = 'dose_linked' AND funding_source_id IS NOT NULL AND unavailable_reason IS NULL) OR (kind = 'outside' AND funding_source_id IS NULL AND unavailable_reason IS NOT NULL))");
    }

    public function down(): void
    {
        if (DB::table('visit_prescriptions')->where('kind', '<>', 'unlinked')->orWhereNotNull('funding_source_id')->orWhereNotNull('unavailable_reason')->exists()) {
            throw new RuntimeException('Prescription-kind rollback is not retained: a non-unlinked prescription is already stored.');
        }
        if (DB::table('visit_prescriptions')->whereNull('voided_at')->select('visit_id')->groupBy('visit_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Prescription-kind rollback is not retained: a visit already has more than one active prescription.');
        }
        DB::statement('ALTER TABLE visit_prescriptions DROP CONSTRAINT rx_kind_fields');
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->dropUnique('prescriptions_one_kind');
        });
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->dropColumn('active_kind_key');
        });
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->unsignedBigInteger('active_visit_id')->nullable()->storedAs('CASE WHEN voided_at IS NULL THEN visit_id ELSE NULL END');
            $t->unique('active_visit_id', 'prescriptions_one_active');
            $t->dropForeign('prescriptions_funding');
        });
        Schema::table('visit_prescriptions', function (Blueprint $t) {
            $t->dropColumn(['kind', 'funding_source_id', 'unavailable_reason']);
        });
    }
};
