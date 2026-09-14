<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BloodBankReferenceSeeder extends Seeder
{
    // ISO 3166-2:SY governorates; Arabic names checked against the OCHA Syria atlas.
    // Only a small initial city directory; other existing cities remain untouched.
    public const GOVERNORATES = [
        'SY-HL' => ['حلب', 'حلب'], 'SY-DI' => ['دمشق', 'دمشق'], 'SY-RD' => ['ريف دمشق', 'دوما'],
        'SY-HI' => ['حمص', 'حمص'], 'SY-HM' => ['حماة', 'حماة'], 'SY-ID' => ['إدلب', 'إدلب'],
        'SY-LA' => ['اللاذقية', 'اللاذقية'], 'SY-TA' => ['طرطوس', 'طرطوس'], 'SY-HA' => ['الحسكة', 'الحسكة'],
        'SY-RA' => ['الرقة', 'الرقة'], 'SY-DY' => ['دير الزور', 'دير الزور'], 'SY-DR' => ['درعا', 'درعا'],
        'SY-SU' => ['السويداء', 'السويداء'], 'SY-QU' => ['القنيطرة', 'القنيطرة'],
    ];

    public const COMPONENTS = [
        'whole' => ['WB', 'كامل', ['WB', 'WHOLE_BLOOD'], ['كامل', 'دم كامل']],
        'red_cells' => ['PRBC', 'ركازة', ['PRBC', 'RBC', 'PACKED_RBC', 'PACKED_RED_CELLS'], ['ركازة', 'ركازة حمراء', 'كريات حمراء مركزة']],
        'plasma' => ['FFP', 'بلازما', ['FFP', 'PLASMA'], ['بلازما', 'بلازما طازجة مجمدة']],
        'platelets' => ['PLT', 'صفيحات', ['PLT', 'PLATELETS'], ['صفيحات', 'صفيحات دموية']],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::GOVERNORATES as $code => [$name, $city]) {
                $aliases = $name === 'إدلب' ? [$name, 'ادلب'] : [$name];
                $rows = DB::table('governorates')->where('code', $code)->orWhereIn('name_ar', $aliases)->lockForUpdate()->get();
                if ($rows->count() > 1 || ($rows->first()?->country_code && $rows->first()->country_code !== 'SY')) {
                    throw new RuntimeException("Ambiguous governorate mapping: $code. Review the existing directory; no IDs were merged.");
                }
                $id = $rows->first()?->id ?? DB::table('governorates')->insertGetId(['code' => $code, 'name_ar' => $name, 'created_at' => now()]);
                DB::table('governorates')->where('id', $id)->update(['country_code' => 'SY']);
                DB::table('cities')->insertOrIgnore(['governorate_id' => $id, 'name_ar' => $city, 'created_at' => now()]);
            }
            foreach (self::COMPONENTS as $kind => [$code, $name, $codes, $names]) {
                $rows = DB::table('blood_components')->where('registration_kind', $kind)->orWhereIn('code', $codes)->orWhereIn('name_ar', $names)->lockForUpdate()->get();
                if ($rows->count() > 1 || ($rows->first()?->registration_kind && $rows->first()->registration_kind !== $kind)) {
                    throw new RuntimeException("Ambiguous blood component mapping: $kind. Review the existing directory; no IDs were merged.");
                }
                $id = $rows->first()?->id ?? DB::table('blood_components')->insertGetId(['code' => $code, 'name_ar' => $name, 'created_at' => now()]);
                DB::table('blood_components')->where('id', $id)->update(['registration_kind' => $kind]);
            }
        });
    }
}
