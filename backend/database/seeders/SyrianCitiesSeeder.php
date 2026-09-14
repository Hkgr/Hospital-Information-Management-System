<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Explicit, offline directory completion; never rewrites addresses or merges IDs. */
class SyrianCitiesSeeder extends Seeder
{
    public function run(): void
    {
        $counts = $this->complete();
        $this->command?->table(['Governorate', 'Before', 'After', 'Added'], $counts);
    }

    public function complete(): array
    {
        $directory = json_decode(file_get_contents(database_path('reference/syrian-cities.json')), true, 512, JSON_THROW_ON_ERROR);

        return DB::transaction(function () use ($directory) {
            $counts = [];
            foreach ($directory['governorates'] as $code => $cities) {
                $name = BloodBankReferenceSeeder::GOVERNORATES[$code][0];
                $governorates = DB::table('governorates')->where('code', $code)->orWhereIn('name_ar', $name === 'إدلب' ? [$name, 'ادلب'] : [$name])->lockForUpdate()->get();
                if ($governorates->count() !== 1 || $governorates[0]->country_code !== 'SY') {
                    throw new RuntimeException("Missing or ambiguous Syrian governorate $code; verify the reference directory first. No cities changed.");
                }
                $id = $governorates[0]->id;
                $before = DB::table('cities')->where('governorate_id', $id)->count();
                $seen = [];
                foreach ($cities as $city) {
                    $names = is_array($city) ? $city : [$city];
                    foreach ($names as $alias) {
                        if (isset($seen[$alias])) {
                            throw new RuntimeException("Duplicate reference alias $code / $alias");
                        }
                        $seen[$alias] = true;
                    }
                    $matches = DB::table('cities')->where('governorate_id', $id)->whereIn('name_ar', $names)->lockForUpdate()->get();
                    if ($matches->count() > 1) {
                        throw new RuntimeException("City alias conflict $code / {$names[0]}: IDs ".$matches->pluck('id')->implode(', ').'. Review manually; no IDs merged and transaction rolled back.');
                    }
                    if ($matches->isEmpty()) {
                        DB::table('cities')->insertOrIgnore(['governorate_id' => $id, 'name_ar' => $names[0], 'created_at' => now()]);
                    }
                }
                $after = DB::table('cities')->where('governorate_id', $id)->count();
                $counts[] = [$name, $before, $after, $after - $before];
            }

            return $counts;
        });
    }
}
