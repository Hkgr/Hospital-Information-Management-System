<?php

namespace Tests\Feature;

use Database\Seeders\MissingReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MissingReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    private int $link;

    protected function setUp(): void
    {
        parent::setUp();
        $facility = DB::table('facilities')->insertGetId(['code' => 'MBZ-ALEPPO', 'name_ar' => 'المشفى الإماراتي - حلب', 'timezone' => 'Asia/Damascus']);
        $type = DB::table('staff_types')->insertGetId(['code' => 'DOCTOR', 'name_ar' => 'طبيب']);
        $staff = [];
        foreach (['DR-003', 'DR-005', 'DR-006', 'DR-007', 'DR-008', 'DR-009', 'DR-010', 'DR-011'] as $code) {
            $staff[$code] = DB::table('staff')->insertGetId(['staff_code' => $code, 'full_name' => $code, 'search_name' => $code, 'staff_type_id' => $type]);
        }
        $clinics = [];
        for ($n = 1; $n <= 11; $n++) {
            $code = 'CLI-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            $clinics[$code] = DB::table('clinics')->insertGetId(['facility_id' => $facility, 'code' => $code, 'name_ar' => 'عيادة '.$n]);
        }
        $category = DB::table('service_categories')->insertGetId(['code' => 'SER-CAT', 'name_ar' => 'إيكو']);
        DB::table('services')->insert(['code' => 'SER-2-001', 'name_ar' => 'ايكو قلبي', 'category_id' => $category]);
        DB::table('procedures')->insert(['code' => 'PRO-006', 'name_ar' => 'إجراء مرجعي']);
        $this->link = DB::table('clinic_staff')->insertGetId(['clinic_id' => $clinics['CLI-001'], 'staff_id' => $staff['DR-005'], 'starts_on' => '2026-09-12']);
    }

    public function test_seeder_fills_gaps_is_idempotent_and_preserves_existing_rows(): void
    {
        $existing = [
            'staff' => DB::table('staff')->orderBy('id')->get(['id', 'staff_code', 'full_name']),
            'clinics' => DB::table('clinics')->orderBy('id')->get(['id', 'code', 'name_ar']),
            'services' => DB::table('services')->orderBy('id')->get(['id', 'code', 'name_ar']),
            'procedures' => DB::table('procedures')->orderBy('id')->get(['id', 'code', 'name_ar']),
        ];
        $before = $this->counts();

        app(MissingReferenceSeeder::class)->run();

        $this->assertSame($before['staff'] + 4, DB::table('staff')->count());
        $this->assertSame($before['clinics'] + 2, DB::table('clinics')->count());
        $this->assertSame($before['services'] + 4, DB::table('services')->count());
        $this->assertSame($before['procedures'] + 1, DB::table('procedures')->count());
        $this->assertSame($before['diagnoses'] + 77, DB::table('diagnoses')->count());
        $this->assertDatabaseHas('clinic_staff', ['id' => $this->link, 'starts_on' => '2000-01-01', 'ends_on' => null]);

        foreach ($existing['staff'] as $row) {
            $this->assertDatabaseHas('staff', ['id' => $row->id, 'staff_code' => $row->staff_code, 'full_name' => $row->full_name]);
        }
        foreach (['clinics', 'services', 'procedures'] as $table) {
            foreach ($existing[$table] as $row) {
                $this->assertDatabaseHas($table, ['id' => $row->id, 'code' => $row->code, 'name_ar' => $row->name_ar]);
            }
        }

        $after = $this->counts();
        app(MissingReferenceSeeder::class)->run();
        $this->assertSame($after, $this->counts());
    }

    private function counts(): array
    {
        return [
            'staff' => DB::table('staff')->count(),
            'clinics' => DB::table('clinics')->count(),
            'services' => DB::table('services')->count(),
            'procedures' => DB::table('procedures')->count(),
            'diagnoses' => DB::table('diagnoses')->count(),
            'clinic_staff' => DB::table('clinic_staff')->count(),
            'service_categories' => DB::table('service_categories')->count(),
        ];
    }
}
