<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixture;
use Tests\TestCase;

class DossierWorkflowMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_upgrade_preserves_period_fks_and_rollback_refuses_before_ddl(): void
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
            $m = require database_path('migrations/2026_09_17_000001_add_dossier_section_workflow.php');
            $m->down();
            $f = CatalogFixture::make();
            $periods = DB::table('visits')->orderBy('id')->pluck('reporting_period_id', 'id')->all();
            $keys = Schema::getForeignKeys('visits');
            DB::table('reporting_periods')->update(['status' => 'locked']);
            $m->up();
            $this->assertSame($periods, DB::table('visits')->orderBy('id')->pluck('reporting_period_id', 'id')->all());
            $this->assertSame($keys, Schema::getForeignKeys('visits'));
            $m->down();
            $m->up();
            DB::table('visits')->where('facility_id', $f['facility'])->limit(1)->update(['reporting_period_id' => null]);
            try {
                $m->down();
                $this->fail('NULL period must block rollback');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rollback refused', $e->getMessage());
            }
            $this->assertTrue(Schema::hasTable('dossier_section_progress'));
            $this->assertSame($keys, Schema::getForeignKeys('visits'));
            $this->assertTrue(DB::table('visits')->whereNull('reporting_period_id')->exists());
        } finally {
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
    }
}
