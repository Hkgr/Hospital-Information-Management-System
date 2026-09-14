<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class DossierMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_fresh_rollback_populated_upgrade_and_lossless_refusal(): void
    {
        $m = require database_path('migrations/2026_09_16_000001_add_patient_dossier_foundation.php');
        $m->down();
        $this->assertFalse(Schema::hasTable('patient_dossiers'));
        try {
            $f = CatalogFixture::make();
            $before = [];
            foreach (['patients', 'visits', 'visit_services', 'visit_procedures', 'blood_transfusions', 'blood_recipient_procedures', 'reporting_periods'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            }
            $m->up();
            $this->assertSame(0, DB::table('patient_dossiers')->count());
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(function ($r) {
                    $v = (array) $r;
                    unset($v['dossier_id']);

                    return $v;
                })->all();
                $this->assertSame($rows, $after, $table);
            }
            $this->assertSame(0, DB::table('visits')->whereNotNull('dossier_id')->count());
            $m->down();
            $m->up(); // No new information has been written: reversible.
            $id = DB::table('patient_dossiers')->insertGetId(['facility_id' => $f['facility'], 'patient_id' => $f['patients'][1], 'code' => 'HIST-1990', 'opening_date' => '1990-01-01', 'entered_by' => $f['user']->id]);
            $keys = Schema::getForeignKeys('visits');
            try {
                $m->down();
                $this->fail('Populated rollback must refuse');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rollback refused', $e->getMessage());
            }
            $this->assertDatabaseHas('patient_dossiers', ['id' => $id, 'opening_date' => '1990-01-01']);
            $this->assertSame($keys, Schema::getForeignKeys('visits'));
        } finally {
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
    }
}
