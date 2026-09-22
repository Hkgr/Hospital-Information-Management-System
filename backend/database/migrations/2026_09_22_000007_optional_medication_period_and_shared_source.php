<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PERIODS = [
        'dose_sessions' => 'fk_dose_sessions_5fc8d62ae0',
        'visit_medications' => 'fk_visit_medications_5fc8d62ae0',
    ];

    private const SOURCE = "medication_source IS NULL OR medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none')";

    public function up(): void
    {
        $this->verify();
        foreach (array_keys(self::PERIODS) as $table) {
            DB::statement("ALTER TABLE `$table` MODIFY `reporting_period_id` BIGINT UNSIGNED NULL");
        }
        DB::statement('ALTER TABLE `dose_session_items` MODIFY `funding_source_id` BIGINT UNSIGNED NULL');
        Schema::table('dose_session_items', function (Blueprint $t) {
            $t->string('medication_source', 30)->nullable()->after('funding_source_id');
        });
        Schema::table('visit_medications', function (Blueprint $t) {
            $t->string('medication_source', 30)->nullable()->after('funding_source_id');
        });
        DB::statement('ALTER TABLE dose_session_items ADD CONSTRAINT onc_item_medication_source CHECK ('.self::SOURCE.')');
        DB::statement('ALTER TABLE visit_medications ADD CONSTRAINT onc_dispense_medication_source CHECK ('.self::SOURCE.')');
    }

    public function down(): void
    {
        $this->verify();
        if (DB::table('dose_sessions')->whereNull('reporting_period_id')->exists()
            || DB::table('visit_medications')->whereNull('reporting_period_id')->exists()
            || DB::table('dose_session_items')->whereNull('funding_source_id')->orWhereNotNull('medication_source')->exists()
            || DB::table('visit_medications')->whereNotNull('medication_source')->exists()) {
            throw new RuntimeException('Medication period and source rollback is not retained: a row already omits the period, omits the catalog funding id, or stores a medication source.');
        }
        DB::statement('ALTER TABLE dose_session_items DROP CONSTRAINT onc_item_medication_source');
        DB::statement('ALTER TABLE visit_medications DROP CONSTRAINT onc_dispense_medication_source');
        Schema::table('dose_session_items', function (Blueprint $t) {
            $t->dropColumn('medication_source');
        });
        Schema::table('visit_medications', function (Blueprint $t) {
            $t->dropColumn('medication_source');
        });
        DB::statement('ALTER TABLE `dose_session_items` MODIFY `funding_source_id` BIGINT UNSIGNED NOT NULL');
        foreach (array_keys(self::PERIODS) as $table) {
            DB::statement("ALTER TABLE `$table` MODIFY `reporting_period_id` BIGINT UNSIGNED NOT NULL");
        }
    }

    private function verify(): void
    {
        foreach (self::PERIODS as $table => $name) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'reporting_period_id');
            $key = collect(Schema::getForeignKeys($table))->firstWhere('name', $name);
            if (! $column || $column['type_name'] !== 'bigint' || ! str_contains($column['type'], 'unsigned')
                || ! $key || $key['columns'] !== ['reporting_period_id', 'facility_id']
                || $key['foreign_table'] !== 'reporting_periods' || $key['foreign_columns'] !== ['id', 'facility_id']) {
                throw new RuntimeException("Unexpected medication period schema in $table. Inspect it before retrying; no data was reassigned.");
            }
        }
        $column = collect(Schema::getColumns('dose_session_items'))->firstWhere('name', 'funding_source_id');
        $key = collect(Schema::getForeignKeys('dose_session_items'))->firstWhere('name', 'fk_dose_session_items_92519fb16d');
        if (! $column || $column['type_name'] !== 'bigint' || ! str_contains($column['type'], 'unsigned')
            || ! $key || $key['columns'] !== ['funding_source_id'] || $key['foreign_table'] !== 'funding_sources') {
            throw new RuntimeException('Unexpected dose-item funding schema. Inspect it before retrying; no data was reassigned.');
        }
    }
};
