<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReconcile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankPeriodMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_populated_upgrade_preserves_links_and_scoped_keys_and_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_09_15_000001_make_blood_bank_periods_optional.php');
        $tables = ['blood_bank_events', 'blood_donations', 'blood_transfusions'];
        // Dossier Phase 2 independently made visits optional. This blood-bank
        // migration must preserve the installed visit contract, not restore an old one.
        $visitPeriodNullable = collect(Schema::getColumns('visits'))->firstWhere('name', 'reporting_period_id')['nullable'];
        $migration->down(); // Reproduce the deployed NOT NULL schema before inserting historical data.
        try {
            $f = BloodBankFixture::make();
            $period = DB::table('reporting_periods')->where('facility_id', $f['facility'])->value('id');
            $donor = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'donor_code' => 'PERIOD-LEGACY', 'full_name' => 'تاريخ محفوظ', 'entered_by' => $f['user']->id]);
            DB::table('blood_donations')->insert(['facility_id' => $f['facility'], 'donor_id' => $donor, 'reporting_period_id' => $period, 'donated_on' => $f['today'], 'donation_code' => 'DON-PERIOD-LEGACY', 'blood_group' => 'O', 'rh' => 'positive', 'units' => '2.5000', 'entered_by' => $f['user']->id]);
            app(BloodBankReconcile::class)->apply();
            $before = $keys = [];
            foreach ([...$tables, 'reporting_periods'] as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->all();
                $keys[$table] = Schema::getForeignKeys($table);
            }
            $migration->up();
            foreach ($before as $table => $rows) {
                $this->assertNotEmpty($rows);
                $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->all(), $table);
                $this->assertSame($keys[$table], Schema::getForeignKeys($table), $table);
            }
            // A lossless rollback remains possible while every record still has a period.
            $migration->down();
            $migration->up();
            $other = DB::table('facilities')->insertGetId(['code' => 'PERIOD-OTHER', 'name_ar' => 'منشأة أخرى']);
            $wrongPeriod = DB::table('reporting_periods')->insertGetId(['facility_id' => $other, 'starts_on' => '2000-01-01', 'ends_on' => '2099-01-01', 'status' => 'locked']);
            foreach ($tables as $table) {
                $column = collect(Schema::getColumns($table))->firstWhere('name', 'reporting_period_id');
                $this->assertTrue($column['nullable'], $table);
                $id = DB::table($table)->where('facility_id', $f['facility'])->value('id');
                try {
                    DB::table($table)->where('id', $id)->update(['reporting_period_id' => $wrongPeriod]);
                    $this->fail('Cross-facility period must violate the retained composite FK: '.$table);
                } catch (QueryException $e) {
                    $this->assertSame('23000', $e->getCode());
                }
                DB::table($table)->where('id', $id)->update(['reporting_period_id' => null]);
            }
            $after = [];
            foreach ($tables as $table) {
                $after[$table] = DB::table($table)->orderBy('id')->get()->all();
            }
            try {
                $migration->down();
                $this->fail('Rollback must refuse to fabricate periods or discard events');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Cannot restore NOT NULL', $e->getMessage());
            }
            foreach ($tables as $table) {
                $this->assertTrue(collect(Schema::getColumns($table))->firstWhere('name', 'reporting_period_id')['nullable']);
                $this->assertEquals($after[$table], DB::table($table)->orderBy('id')->get()->all());
            }
            $this->assertSame($visitPeriodNullable, collect(Schema::getColumns('visits'))->firstWhere('name', 'reporting_period_id')['nullable']);
            $this->assertEquals($before['reporting_periods'], DB::table('reporting_periods')->where('facility_id', '!=', $other)->orderBy('id')->get()->all());
        } finally {
            // Existing TestDatabaseSafety guard runs before the test can reach destructive DDL.
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
    }
}
