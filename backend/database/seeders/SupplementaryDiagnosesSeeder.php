<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Adds three diagnoses requested by the hospital. Additive only: nothing is
 * deleted or renamed, and an existing label is left as it is.
 *
 *   php artisan db:seed --class=SupplementaryDiagnosesSeeder --force
 *
 * Idempotent: running it twice changes nothing.
 */
class SupplementaryDiagnosesSeeder extends Seeder
{
    public const LABELS = [
        'DOS-DX-94' => 'ورم جيوب انفية',
        'DOS-DX-95' => 'نقص صفيحات',
        'DOS-DX-96' => 'عوز فيرنيوجين',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::LABELS as $code => $name) {
                $existing = DB::table('diagnoses')->where('code', $code)->lockForUpdate()->first();
                if ($existing && $existing->name_ar !== $name) {
                    throw new \RuntimeException("Diagnosis seed code $code is already used for another label; review before retrying.");
                }
                if (! DB::table('diagnoses')->whereRaw("TRIM(REGEXP_REPLACE(name_ar, '[[:space:]]+', ' ')) = ?", [$name])->exists()) {
                    DB::table('diagnoses')->insert(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
