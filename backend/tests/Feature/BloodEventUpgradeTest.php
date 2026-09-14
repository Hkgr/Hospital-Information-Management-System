<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReconcile;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodEventUpgradeTest extends TestCase
{
    use DatabaseMigrations;

    public function test_existing_schema_and_records_upgrade_without_inventing_events_or_losing_references(): void
    {
        $migration = require database_path('migrations/2026_09_14_000003_unify_blood_bank_events.php');
        $migration->down(); // Empty new schema; the old schema remains for the upgrade fixture.
        $f = BloodBankFixture::make();
        $donor = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'patient_id' => $f['patients'][1], 'donor_code' => 'LEGACY-DONOR', 'full_name' => 'اسم قديم محفوظ', 'blood_group' => 'O', 'rh' => 'positive', 'entered_by' => $f['user']->id]);
        DB::table('blood_recipients')->insert(['facility_id' => $f['facility'], 'patient_id' => $f['patients'][1], 'recipient_code' => 'LEGACY-RECIPIENT', 'blood_group' => 'A', 'rh' => 'positive', 'entered_by' => $f['user']->id]);
        $empty = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'donor_code' => 'LEGACY-NO-EVENT', 'full_name' => 'ملف بلا واقعة', 'entered_by' => $f['user']->id, 'created_at' => '2020-01-01']);
        $donation = DB::table('blood_donations')->insertGetId(['facility_id' => $f['facility'], 'donor_id' => $donor, 'reporting_period_id' => DB::table('reporting_periods')->where('facility_id', $f['facility'])->value('id'), 'donated_on' => $f['today'], 'donation_code' => 'DON-HISTORICAL', 'blood_group' => 'O', 'rh' => 'positive', 'units' => '2.5000', 'entered_by' => $f['user']->id]);
        DB::table('blood_donation_codes')->insert(['blood_donation_id' => $donation, 'code' => 'DON-OLD-ALIAS']);
        DB::table('blood_donation_screenings')->insert(['blood_donation_id' => $donation, 'screening_test_id' => $f['test'], 'result' => 'indeterminate', 'tested_on' => $f['today'], 'entered_by' => $f['user']->id]);
        DB::table('blood_bank_screenings')->insert(['donor_id' => $donor, 'analyte' => 'HCV', 'status' => 'complete', 'screening_test_id' => $f['test'], 'result' => 'positive']);
        $before = [];
        foreach (['blood_donors', 'blood_recipients', 'blood_donations', 'blood_transfusions', 'blood_donation_codes', 'blood_donation_screenings', 'blood_bank_screenings', 'blood_recipient_procedures', 'patients', 'visits'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }
        try {
            $migration->up();
            $result = app(BloodBankReconcile::class)->apply();
            $this->assertSame(3, $result['before']['unmapped_profiles']);
            $this->assertSame(0, $result['after']['unmapped_profiles']);
            $this->assertSame(count($before['blood_donations']) + count($before['blood_transfusions']), $result['after']['blood_bank_events']);
            foreach ($before as $table => $rows) {
                $after = DB::table($table)->orderBy('id')->get()->map(fn ($r) => array_diff_key((array) $r, array_flip(['person_id', 'quantity_unit'])))->all();
                $this->assertSame($rows, $after, $table.' historical values/references');
            }
            $person = DB::table('blood_donors')->where('id', $donor)->value('person_id');
            $this->assertDatabaseHas('blood_recipients', ['patient_id' => $f['patients'][1], 'person_id' => $person]);
            $this->assertDatabaseHas('blood_bank_people', ['id' => $person, 'first_name' => null, 'blood_group' => null]);
            $this->assertDatabaseMissing('blood_bank_events', ['person_id' => DB::table('blood_donors')->where('id', $empty)->value('person_id')]);
            $event = DB::table('blood_bank_events')->where('blood_donation_id', $donation)->first();
            $this->assertSame('unit', $event->quantity_unit);
            $this->assertSame('2.5000', $event->quantity);
            $this->assertDatabaseHas('blood_bank_event_codes', ['event_id' => $event->id, 'code' => 'DON-OLD-ALIAS']);
            $this->assertDatabaseMissing('blood_bank_event_screenings', ['event_id' => $event->id]);
            $this->assertSame($result['after'], app(BloodBankReconcile::class)->apply()['after']);
            try {
                $migration->up();
                $this->fail('Existing/partial schema cannot masquerade as completed migration');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('Partial unified', $e->getMessage());
            }
            try {
                $migration->down();
                $this->fail('Rollback must not discard data');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('data exists', $e->getMessage());
            }
        } finally {
            // TestDatabaseSafety is enforced before this destructive command. No external DB.
            $this->artisan('migrate:fresh')->assertExitCode(0);
        }
    }
}
