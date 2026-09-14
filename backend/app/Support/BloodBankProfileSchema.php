<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Recovery of the known partial profile migration, without recreating columns. */
class BloodBankProfileSchema
{
    public static function dropCheck(string $table, string $name): string
    {
        return "ALTER TABLE $table DROP ".(DB::connection()->isMaria() ? 'CONSTRAINT' : 'CHECK')." $name";
    }

    public static function column(string $table, string $column, string $type, int $length): void
    {
        $existing = collect(Schema::getColumns($table))->firstWhere('name', $column);
        if ($existing) {
            if ($existing['type'] !== "$type($length)" || ! $existing['nullable']) {
                throw new RuntimeException("Unexpected existing $table.$column; inspect schema before resuming. No column was replaced.");
            }

            return;
        }
        Schema::table($table, function (Blueprint $t) use ($column, $type, $length) {
            ($type === 'char' ? $t->char($column, $length) : $t->string($column, $length))->nullable();
        });
    }

    public static function check(string $table, string $name, string $expression, ?string $previous = null): void
    {
        // Verify a known engine serialization before resuming partial DDL.
        $existing = DB::table('information_schema.CHECK_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())->where('CONSTRAINT_NAME', $name)->value('CHECK_CLAUSE');
        if ($existing !== null) {
            $normal = fn ($s) => strtolower(preg_replace('/\s+|`|_utf8mb4|_utf8mb3/', '', str_replace("\\'", "'", $s)));
            // These exact equivalent serializations are emitted by MySQL and MariaDB.
            $accepted = [$expression, self::serialized($previous === null ? 'address' : 'new')];
            if (in_array($normal($existing), array_map($normal, $accepted), true)) {
                return;
            }
            if ($previous === null || ! in_array($normal($existing), array_map($normal, [$previous, self::serialized('old')]), true)) {
                throw new RuntimeException("Unexpected $table.$name CHECK definition. Verify the manually applied constraint before retrying; migration not marked complete.");
            }
            DB::statement(self::dropCheck($table, $name).", ADD CONSTRAINT $name CHECK ($expression)");

            return;
        }
        DB::statement("ALTER TABLE $table ADD CONSTRAINT $name CHECK ($expression)");
    }

    private static function serialized(string $kind): string
    {
        if (DB::connection()->isMaria()) {
            return match ($kind) {
                'new' => "result is null or result in ('negative','positive','indeterminate')",
                'old' => "status = 'complete' and result is not null and result in ('negative','positive','indeterminate') or status <> 'complete' and result is null",
                default => '(governorate_text is null or governorate_id is null and city_id is null) and (city_text is null or city_id is null) and (patient_id is null or governorate_text is null and city_text is null)',
            };
        }

        return match ($kind) {
            'new' => "((result is null) or (result in ('negative','positive','indeterminate')))",
            'old' => "(((status = 'complete') and (result is not null) and (result in ('negative','positive','indeterminate'))) or ((status <> 'complete') and (result is null)))",
            default => '(((governorate_text is null) or ((governorate_id is null) and (city_id is null))) and ((city_text is null) or (city_id is null)) and ((patient_id is null) or ((governorate_text is null) and (city_text is null))))',
        };
    }
}
