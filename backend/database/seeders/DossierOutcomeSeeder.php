<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierOutcomeSeeder extends Seeder
{
    public const OUTCOMES = ['DOS-RX' => 'تخريج مع وصفة', 'DOS-NORX' => 'تخريج بدون وصفة', 'DOS-STUDY' => 'تخريج للدراسة', 'DOS-REFER' => 'إحالة لمشفى آخر', 'DOS-DEATH' => 'وفاة'];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::OUTCOMES as $code => $name) {
                $existing = DB::table('visit_results')->where('code', $code)->first();
                if ($existing && $existing->name_ar !== $name) {
                    throw new \RuntimeException('Reserved dossier outcome code has a different label; operator review required.');
                }
                DB::table('visit_results')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'result_group' => 'dossier', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
    }
}
