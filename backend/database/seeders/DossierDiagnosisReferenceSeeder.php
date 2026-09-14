<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierDiagnosisReferenceSeeder extends Seeder
{
    public const LABELS = ['خباثات الاذن و ملحقاتها', 'خباثات الغدد اللعابية', 'لمفوما تائية', 'خلية مشعرة', 'فطار فطراني', 'ساركوما الانسجة الرخوة', 'ورم الحنجرة', 'خباثات الجهاز التنفسي و الصدر', 'خباثات الرئة', 'خباثات الرغامى و القصبات', 'خباثات البريتوان', 'خباثات الحوض', 'نقائل ورمية للأعضاء', 'ساركوما خد', 'الحبن', 'ورم القلب'];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::LABELS as $index => $name) {
                $code = 'DOS-DX-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                $existing = DB::table('diagnoses')->where('code', $code)->first();
                if ($existing && $existing->name_ar !== $name) {
                    throw new \RuntimeException("Diagnosis seed code $code is already used for another label; review before retrying.");
                }
                if (! DB::table('diagnoses')->whereRaw("TRIM(REGEXP_REPLACE(name_ar, '[[:space:]]+', ' ')) = ?", [$name])->exists()) {
                    DB::table('diagnoses')->insert(['code' => $code, 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
