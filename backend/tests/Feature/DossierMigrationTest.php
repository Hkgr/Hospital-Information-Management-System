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
        try {
            foreach ([
                '2026_09_22_000001_add_service_requests_and_period_stamping.php',
                '2026_09_21_000002_constrain_active_oncology_revision.php',
                '2026_09_21_000001_preserve_voided_oncology_administrations.php',
                '2026_09_20_000002_keep_context_codes_as_legacy_aliases.php',
                '2026_09_20_000002_add_oncology_plans_and_sessions.php',
                '2026_09_20_000001_protect_patient_card_registration_visit.php',
                '2026_09_20_000001_add_visit_pathology_workflow.php',
                '2026_09_18_000002_add_dossier_upload_reservations.php',
                '2026_09_18_000001_extend_dossier_visit_workflow.php',
            ] as $file) {
                (require database_path('migrations/'.$file))->down();
            }
            // Remove the unused additive Phase 2 schema before exercising its parent migration.
            $workflow = require database_path('migrations/2026_09_17_000001_add_dossier_section_workflow.php');
            $workflow->down();
            $m = require database_path('migrations/2026_09_16_000001_add_patient_dossier_foundation.php');
            $m->down();
            $this->assertFalse(Schema::hasTable('patient_dossiers'));
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
