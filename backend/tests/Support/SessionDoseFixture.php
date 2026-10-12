<?php

namespace Tests\Support;

use App\Services\Dossiers\SessionDoseMedications;
use Illuminate\Support\Facades\DB;

class SessionDoseFixture
{
    public static function medication(string $tag, string $name = 'دواء نوعي اختبار'): int
    {
        DB::table('medication_categories')->insertOrIgnore(['code' => SessionDoseMedications::CATEGORY_CODE, 'name_ar' => 'نوعي', 'is_active' => true]);
        $category = DB::table('medication_categories')->where('code', SessionDoseMedications::CATEGORY_CODE)->first();
        if (! $category->is_active) {
            throw new \RuntimeException('The existing test category is disabled; the fixture never reactivates it.');
        }

        return DB::table('medications')->insertGetId(['code' => 'SD-'.$tag, 'name_ar' => $name.' '.$tag, 'category_id' => $category->id, 'is_active' => true]);
    }
}
