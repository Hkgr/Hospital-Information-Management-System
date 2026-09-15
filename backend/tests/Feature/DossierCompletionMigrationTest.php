<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DossierWorkflowFixture;
use Tests\TestCase;

class DossierCompletionMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_mariadb_upgrade_preserves_history_and_refuses_used_rollback_before_ddl(): void
    {
        $uploads = require database_path('migrations/2026_09_18_000002_add_dossier_upload_reservations.php');
        $migration = require database_path('migrations/2026_09_18_000001_extend_dossier_visit_workflow.php');
        $uploads->down();
        $migration->down();
        try {
            DossierWorkflowFixture::make();
            $before = [];
            foreach (['visit_services', 'visit_procedures', 'visit_outcomes'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            }
            $migration->up();
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(fn ($r) => array_intersect_key((array) $r, array_flip(array_keys($rows[0] ?? []))))->all();
                $this->assertSame($rows, $after, $table);
            }
            $migration->down();
            $migration->up();
            DB::table('visit_services')->limit(1)->update(['reporting_period_id' => null]);
            $keys = Schema::getForeignKeys('visit_services');
            try {
                $migration->down();
                $this->fail('Must refuse rollback');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('rollback refused', $e->getMessage());
            }
            $this->assertSame($keys, Schema::getForeignKeys('visit_services'));
            $this->assertTrue(Schema::hasTable('visit_prescriptions'));
            $this->assertTrue(DB::table('visit_services')->whereNull('reporting_period_id')->exists());
        } finally {
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
    }
}
