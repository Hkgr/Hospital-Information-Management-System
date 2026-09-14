<?php

namespace Tests\Feature;

use Database\Seeders\BloodBankReferenceSeeder;
use Database\Seeders\SyrianCitiesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyrianCitiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_governorates_alias_matching_and_idempotency_preserve_existing_places(): void
    {
        app(BloodBankReferenceSeeder::class)->run();
        $raqqa = DB::table('governorates')->where('code', 'SY-RA')->value('id');
        $aleppo = DB::table('governorates')->where('code', 'SY-HL')->value('id');
        $id = DB::table('cities')->insertGetId(['governorate_id' => $raqqa, 'name_ar' => 'الثورة']);
        $other = DB::table('cities')->insertGetId(['governorate_id' => $aleppo, 'name_ar' => 'الثورة']);
        $before = DB::table('cities')->orderBy('id')->get()->keyBy('id');
        $counts = app(SyrianCitiesSeeder::class)->complete();
        $this->assertCount(14, $counts);
        $this->assertGreaterThan(160, DB::table('cities')->count());
        $this->assertDatabaseHas('cities', ['id' => $id, 'name_ar' => 'الثورة', 'governorate_id' => $raqqa]);
        $this->assertDatabaseHas('cities', ['id' => $other, 'governorate_id' => $aleppo]);
        $this->assertSame(0, DB::table('cities')->where('governorate_id', $raqqa)->where('name_ar', 'الطبقة')->count());
        $this->assertSame(1, DB::table('cities')->where('governorate_id', DB::table('governorates')->where('code', 'SY-DI')->value('id'))->count());
        foreach ($before as $old) {
            $this->assertEquals($old, DB::table('cities')->find($old->id));
        }
        $after = DB::table('cities')->orderBy('id')->get();
        $again = app(SyrianCitiesSeeder::class)->complete();
        $this->assertEquals($after, DB::table('cities')->orderBy('id')->get());
        $this->assertSame(0, array_sum(array_column($again, 3)));
    }

    public function test_ambiguous_aliases_abort_all_changes_without_merging_ids(): void
    {
        app(BloodBankReferenceSeeder::class)->run();
        $id = DB::table('governorates')->where('code', 'SY-RA')->value('id');
        DB::table('cities')->insert([['governorate_id' => $id, 'name_ar' => 'الطبقة'], ['governorate_id' => $id, 'name_ar' => 'الثورة']]);
        $before = DB::table('cities')->orderBy('id')->get();
        try {
            app(SyrianCitiesSeeder::class)->complete();
            $this->fail('Ambiguous place IDs must not merge');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('City alias conflict SY-RA', $e->getMessage());
        }
        $this->assertEquals($before, DB::table('cities')->orderBy('id')->get());
    }
}
