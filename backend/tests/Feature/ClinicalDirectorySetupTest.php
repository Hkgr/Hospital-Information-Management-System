<?php

namespace Tests\Feature;

use App\Services\Directory\ClinicalDirectorySetup;
use Database\Seeders\ClinicalStaffTypesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalDirectorySetupTest extends TestCase
{
    use RefreshDatabase;

    private function facility(): int
    {
        return DB::table('facilities')->insertGetId(['code' => 'SETUP-'.Str::random(8), 'name_ar' => 'منشأة اصطناعية', 'timezone' => 'Asia/Damascus']);
    }

    public function test_preview_and_repeatable_setup_preserve_legacy_identity_and_links(): void
    {
        app(ClinicalStaffTypesSeeder::class)->run();
        $facility = $this->facility();
        $setup = app(ClinicalDirectorySetup::class);
        $plan = $setup->preview($facility, '2001-01-01');
        $this->assertSame([], $plan['errors']);
        $counts = DB::table('staff')->count();
        $this->assertCount(27, $plan['doctors']);
        $this->assertSame($counts, DB::table('staff')->count());
        $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test/operator-ticket');
        $after = $setup->preview($facility, '2001-01-01');
        foreach ($after['doctors'] as $entry) {
            $this->assertSame($entry['staff_type_id'], $entry['before']->staff_type_id);
            $this->assertSame($entry['practice_group'], $entry['before']->practice_group);
            $before = collect($plan['doctors'])->firstWhere('name', $entry['name'])['before'];
            if ($before) {
                foreach (['id', 'staff_code', 'license_no', 'is_active', 'archived_at'] as $key) {
                    $this->assertSame($before->$key, $entry['before']->$key);
                }
            }
        }
        $tables = ['staff', 'clinics', 'clinic_staff', 'procedures', 'blood_components', 'audit_logs'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
        $rows = $snapshot();
        $setup->apply($facility, '2001-01-01', [], $after['fingerprint'], 'test/repeat');
        $this->assertSame($rows, $snapshot());
        $this->assertCount(16, $after['proposed_links']);
        $this->assertSame(2, DB::table('procedures')->where('execution_location', 'radiology')->whereIn('name_ar', array_keys(ClinicalDirectorySetup::PROCEDURES))->count());
        foreach (['كريات مكثفة', 'صفيحات', 'بلازما', 'دم كامل'] as $name) {
            $this->assertDatabaseHas('blood_components', ['name_ar' => $name]);
        }
        $this->assertDatabaseHas('audit_logs', ['event' => 'clinical_directory_setup', 'actor_id' => null]);
    }

    public function test_empty_target_directory_creates_all_names_and_preserves_renamed_legacy_rows(): void
    {
        app(ClinicalStaffTypesSeeder::class)->run();
        $setup = app(ClinicalDirectorySetup::class);
        $facility = $this->facility();
        // Model an empty target roster without deleting any populated test records.
        $plan = $setup->preview($facility, '2001-01-01');
        foreach ($plan['doctors'] as $entry) {
            if ($entry['before']) {
                DB::table('staff')->where('id', $entry['before']->id)->update(['full_name' => 'مرجع سابق '.$entry['before']->id]);
            }
        }
        $plan = $setup->preview($facility, '2001-01-01');
        $this->assertSame([], $plan['errors']);
        $this->assertCount(27, array_filter($plan['doctors'], fn ($entry) => $entry['before'] === null));
        $count = DB::table('staff')->count();
        $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test/empty-directory');
        $this->assertSame($count + 27, DB::table('staff')->count());
        $this->assertSame(16, DB::table('clinic_staff')->join('clinics', 'clinics.id', '=', 'clinic_staff.clinic_id')->where('clinics.facility_id', $facility)->count());
    }

    public function test_schema_rollback_refuses_to_discard_classifications_before_ddl(): void
    {
        $facility = $this->facility();
        $id = DB::table('clinics')->insertGetId(['facility_id' => $facility, 'code' => 'GUARD', 'name_ar' => 'محفوظة', 'clinic_kind' => 'blood', 'care_setting' => 'outpatient']);
        $migration = require database_path('migrations/2026_09_30_000001_correct_clinical_directory_classification.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse populated classifications');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('retain', $e->getMessage());
        }
        $this->assertDatabaseHas('clinics', ['id' => $id, 'clinic_kind' => 'blood']);
    }

    public function test_missing_type_and_ambiguous_names_abort_without_partial_writes(): void
    {
        app(ClinicalStaffTypesSeeder::class)->run();
        DB::table('staff_types')->where('code', 'SPECIALIST')->update(['is_active' => false]);
        $facility = $this->facility();
        $setup = app(ClinicalDirectorySetup::class);
        $plan = $setup->preview($facility, '2001-01-01');
        $this->assertNotEmpty($plan['errors']);
        $count = DB::table('staff')->count();
        try {
            $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test');
            $this->fail('Expected refusal');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SPECIALIST', $e->getMessage());
        }
        $this->assertSame($count, DB::table('staff')->count());
        $type = DB::table('staff_types')->where('code', 'RESIDENT')->value('id');
        foreach (['إيمان   المحمد', 'ايمان المحمد'] as $name) {
            DB::table('staff')->insert(['staff_code' => 'AMB-'.Str::random(10), 'full_name' => $name, 'search_name' => $name, 'staff_type_id' => $type]);
        }
        $this->assertStringContainsString('ملتبس', implode(' ', $setup->preview($facility, '2001-01-01')['errors']));
    }

    public function test_stale_preview_and_component_collision_are_refused_and_archived_staff_stays_archived(): void
    {
        app(ClinicalStaffTypesSeeder::class)->run();
        $facility = $this->facility();
        $setup = app(ClinicalDirectorySetup::class);
        $plan = $setup->preview($facility, '2001-01-01');
        $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test');
        $doctor = $setup->preview($facility, '2001-01-01')['doctors'][0]['before'];
        DB::table('staff')->where('id', $doctor->id)->update(['archived_at' => now(), 'is_active' => false, 'license_no' => '000123']);
        $plan = $setup->preview($facility, '2001-01-01');
        $this->assertSame('keep_inactive_or_archived', $plan['proposed_links'][0]['action']);
        $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test');
        $this->assertDatabaseHas('staff', ['id' => $doctor->id, 'is_active' => false, 'license_no' => '000123']);
        DB::table('blood_components')->insert(['code' => 'COLLIDE-'.Str::random(6), 'name_ar' => 'كريات حمراء مركزة']);
        $bad = $setup->preview($facility, '2001-01-01');
        $this->assertNotEmpty($bad['errors']);
        $this->expectException(\RuntimeException::class);
        $setup->apply($facility, '2001-01-01', [], $plan['fingerprint'], 'test');
    }
}
