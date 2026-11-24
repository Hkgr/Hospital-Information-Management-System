<?php

namespace Database\Seeders;

use App\Services\Directory\IssuedCodes;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class VisitDiagnosisAdditionsSeeder extends Seeder
{
    public const LABELS = [
        'ورم جيوب انفية',
        'عوز فيرنيوجين',
        'ورم رأس البنكرياس',
        'ساركوما فخذ',
        'ضخامات عقدية',
        'فقر دم انحلالي',
        'لمفوما خلية لمفية',
        'نقص كريات بيض',
        'ورم اعور',
        'ورم طريق بولي علوي',
        'عوز GGPD ( داء الغوال)',
        'ورم عرطل كبير',
        'برنارد سولييه',
        'متلازمة غلانزمان',
        'فون ويلبراند',
        'ورم بلعوم حنجري',
        'تطاول زمن النزف PT',
        'ابيضاض الدم اللمفاوي للخلايا البائية B.ALL',
        'ابيضاض الدم اللمفاوي للخلايا التائية T.ALL',
    ];

    public function run(): void
    {
        DB::transaction(function () {
            // Share the existing inline directory creation lock and code issuer.
            $key = ['sequence_key' => 'diagnosis_directory', 'scope_key' => 'global', 'period_key' => 'all'];
            DB::table('number_sequences')->insertOrIgnore($key);
            DB::table('number_sequences')->where($key)->lockForUpdate()->first();
            $names = DB::table('diagnoses')->pluck('name_ar')->map(fn ($name) => trim($name))->all();
            foreach (self::LABELS as $name) {
                if (in_array($name, $names, true)) {
                    continue;
                }
                DB::table('diagnoses')->insert(['code' => app(IssuedCodes::class)->diagnosis(), 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
                $names[] = $name;
            }
        });
    }
}
