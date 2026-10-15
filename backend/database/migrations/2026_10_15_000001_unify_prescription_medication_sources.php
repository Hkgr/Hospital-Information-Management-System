<?php

use App\Services\Dossiers\MedicationSources;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['patient_dossiers' => 'dossiers_medication_source', 'oncology_session_doses' => 'onc_course_medication_source', 'dose_session_items' => 'onc_item_medication_source', 'visit_medications' => 'onc_dispense_medication_source'];

    public function up(): void
    {
        // Review every ambiguous definition before any lookup insertion or DDL.
        app(MedicationSources::class)->bootstrap(true);
        foreach (self::TABLES as $table => $check) {
            DB::statement("ALTER TABLE $table DROP CONSTRAINT $check");
            Schema::table($table, function (Blueprint $t) use ($check) {
                $t->string('medication_source', 50)->nullable()->change();
                $t->foreign('medication_source', $check.'_fk')->references('code')->on('funding_sources')->restrictOnDelete()->restrictOnUpdate();
            });
        }
        DB::statement('ALTER TABLE visit_prescriptions DROP CONSTRAINT rx_kind_fields');
        DB::statement("ALTER TABLE visit_prescriptions ADD CONSTRAINT rx_kind_fields CHECK ((kind IN ('unlinked','dose_linked') AND unavailable_reason IS NULL) OR (kind = 'outside' AND unavailable_reason IS NOT NULL))");
    }

    public function down(): void
    {
        $codes = array_column(json_decode(file_get_contents(database_path('data/medication-sources.json')), true, flags: JSON_THROW_ON_ERROR), 'code');
        foreach (self::TABLES as $table => $check) {
            if (DB::table($table)->whereNotNull('medication_source')->whereNotIn('medication_source', $codes)->exists()) {
                throw new RuntimeException('Rollback refused: additional shared medication sources are in use. No records changed.');
            }
        }
        if (DB::table('visit_prescriptions')->where(fn ($q) => $q->where('kind', '<>', 'dose_linked')->whereNotNull('funding_source_id'))->orWhere(fn ($q) => $q->where('kind', 'dose_linked')->whereNull('funding_source_id'))->exists()) {
            throw new RuntimeException('Rollback refused: prescription sources would violate the old kind constraint. No records changed.');
        }
        foreach (self::TABLES as $table => $check) {
            Schema::table($table, function (Blueprint $t) use ($check) {
                $t->dropForeign($check.'_fk');
                $t->string('medication_source', 30)->nullable()->change();
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT $check CHECK (medication_source IS NULL OR medication_source IN ('".implode("','", $codes)."'))");
        }
        DB::statement('ALTER TABLE visit_prescriptions DROP CONSTRAINT rx_kind_fields');
        DB::statement("ALTER TABLE visit_prescriptions ADD CONSTRAINT rx_kind_fields CHECK ((kind = 'unlinked' AND funding_source_id IS NULL AND unavailable_reason IS NULL) OR (kind = 'dose_linked' AND funding_source_id IS NOT NULL AND unavailable_reason IS NULL) OR (kind = 'outside' AND funding_source_id IS NULL AND unavailable_reason IS NOT NULL))");
        // Shared lookup rows and all historical relationships survive rollback.
    }
};
