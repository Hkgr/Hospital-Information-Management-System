<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        'blood_bank_events' => 'blood_bank_events_reporting_period_id_facility_id_foreign',
        'blood_donations' => 'fk_blood_donations_5fc8d62ae0',
        'blood_transfusions' => 'fk_blood_transfusions_5fc8d62ae0',
    ];

    public function up(): void
    {
        $this->verify();
        $this->change('NULL');
    }

    public function down(): void
    {
        $this->verify();
        // Check every table before changing any DDL. Never invent periods or delete events.
        foreach (array_keys(self::KEYS) as $table) {
            if (DB::table($table)->whereNull('reporting_period_id')->exists()) {
                throw new RuntimeException('Cannot restore NOT NULL: blood-bank records without periods exist. Keep the compatible schema or restore a reviewed backup.');
            }
        }
        $this->change('NOT NULL');
    }

    private function verify(): void
    {
        foreach (self::KEYS as $table => $name) {
            $column = collect(Schema::getColumns($table))->firstWhere('name', 'reporting_period_id');
            $key = collect(Schema::getForeignKeys($table))->firstWhere('name', $name);
            if (! $column || $column['type_name'] !== 'bigint' || ! str_contains($column['type'], 'unsigned')
                || ! $key || $key['columns'] !== ['reporting_period_id', 'facility_id']
                || $key['foreign_table'] !== 'reporting_periods' || $key['foreign_columns'] !== ['id', 'facility_id']) {
                throw new RuntimeException("Unexpected blood-bank period schema in $table. Inspect the partial/changed schema before retrying; no data was reassigned.");
            }
        }
    }

    private function change(string $nullable): void
    {
        foreach (array_keys(self::KEYS) as $table) {
            // Nullability alone can change while retaining the scoped FK and its indexes.
            // No FOREIGN_KEY_CHECKS override, data rewrite, or modification of other sections.
            DB::statement("ALTER TABLE `$table` MODIFY `reporting_period_id` BIGINT UNSIGNED $nullable");
        }
    }
};
