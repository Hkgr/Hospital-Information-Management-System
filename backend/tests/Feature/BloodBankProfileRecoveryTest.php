<?php

namespace Tests\Feature;

use App\Support\BloodBankProfileSchema;
use Database\Seeders\SyrianCitiesSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankProfileRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    public function test_resume_verified_partial_migration_and_manually_completed_constraint_without_data_loss(): void
    {
        $f = BloodBankFixture::make();
        $id = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'donor_code' => 'LEGACY-PRESERVE', 'full_name' => 'تاريخ محفوظ', 'governorate_text' => 'عنوان يدوي', 'city_text' => 'مدينة يدوية', 'entered_by' => $f['user']->id]);
        $before = DB::table('blood_donors')->find($id);
        $migration = require database_path('migrations/2026_09_14_000002_refine_blood_bank_profiles.php');
        // Fully completed, including a manually completed result check.
        $migration->up();
        $this->assertEquals($before, DB::table('blood_donors')->find($id));
        // Same partial point as the MariaDB failure: columns exist, old CHECK remains.
        DB::statement(BloodBankProfileSchema::dropCheck('blood_bank_screenings', 'bb_screen_result').", ADD CONSTRAINT bb_screen_result CHECK ((status = 'complete' AND result IS NOT NULL AND result IN ('negative','positive','indeterminate')) OR (status <> 'complete' AND result IS NULL))");
        $migration->up();
        DB::table('blood_bank_screenings')->insert(['donor_id' => $id, 'analyte' => 'HCV', 'status' => 'complete']);
        $this->assertDatabaseHas('blood_bank_screenings', ['donor_id' => $id, 'status' => 'complete', 'result' => null]);
        $this->assertEquals($before, DB::table('blood_donors')->find($id));
        app(SyrianCitiesSeeder::class)->complete();
        $this->assertEquals($before, DB::table('blood_donors')->find($id));
        // Unknown manual definitions are not silently accepted as successful migration.
        DB::statement(BloodBankProfileSchema::dropCheck('blood_bank_screenings', 'bb_screen_result').', ADD CONSTRAINT bb_screen_result CHECK (result IS NULL)');
        try {
            $migration->up();
            $this->fail('Unknown constraint must stop migration');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Unexpected blood_bank_screenings.bb_screen_result', $e->getMessage());
        }
        // Restore representable synthetic data solely for DatabaseMigrations rollback.
        DB::table('blood_donors')->where('id', $id)->update(['governorate_text' => null, 'city_text' => null]);
        DB::table('blood_bank_screenings')->where('donor_id', $id)->update(['status' => 'not_requested']);
    }
}
