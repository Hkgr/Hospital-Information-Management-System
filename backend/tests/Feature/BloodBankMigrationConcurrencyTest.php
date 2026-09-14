<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodBankMigrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_backfill_rollback_and_overlapping_date_corrections(): void
    {
        $f = BloodBankFixture::make();
        $refinement = require database_path('migrations/2026_09_14_000002_refine_blood_bank_profiles.php');
        $refinement->down();
        $migration = require database_path('migrations/2026_09_14_000001_add_blood_bank_registration.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('blood_recipients'));
        $id = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'donor_code' => 'LEGACY-BB', 'full_name' => 'اسم تاريخي لا يقسم آليًا', 'gender' => 'unknown', 'entered_by' => $f['user']->id]);
        $event = DB::table('blood_donations')->insertGetId(['facility_id' => $f['facility'], 'donor_id' => $id, 'reporting_period_id' => DB::table('reporting_periods')->where('facility_id', $f['facility'])->value('id'), 'donated_on' => $f['today'], 'blood_group' => 'O', 'rh' => 'positive', 'units' => '1.2500', 'entered_by' => $f['user']->id, 'updated_at' => '2020-01-01 00:00:00']);
        $before = (array) DB::table('blood_donations')->where('id', $event)->first();
        $migration->up();
        $refinement->up();
        // A historical result and method survive both application and rollback.
        $screen = DB::table('blood_bank_screenings')->insertGetId(['donor_id' => $id, 'analyte' => 'HCV', 'status' => 'complete', 'result' => 'positive', 'screening_test_id' => $f['test']]);
        $screenBefore = (array) DB::table('blood_bank_screenings')->where('id', $screen)->first();
        $refinement->down();
        $refinement->up();
        $this->assertSame($screenBefore, (array) DB::table('blood_bank_screenings')->where('id', $screen)->first());
        DB::table('blood_bank_screenings')->where('id', $screen)->update(['status' => 'pending']);
        $this->assertDatabaseHas('blood_bank_screenings', ['id' => $screen, 'result' => 'positive', 'screening_test_id' => $f['test']]);
        DB::table('blood_bank_screenings')->where('id', $screen)->update(['status' => 'complete']);
        $after = (array) DB::table('blood_donations')->where('id', $event)->first();
        $code = $after['donation_code'];
        unset($after['donation_code']);
        $this->assertSame($before, $after);
        $this->assertSame('DON-'.str_replace('-', '', $f['today']).'-'.str_pad((string) $event, 6, '0', STR_PAD_LEFT), $code);
        $this->assertDatabaseHas('blood_donation_codes', ['blood_donation_id' => $event, 'code' => $code]);
        $this->assertDatabaseHas('blood_donors', ['id' => $id, 'donor_code' => 'LEGACY-BB', 'first_name' => null, 'family_name' => null]);
        $payload = ['facility_id' => $f['facility'], 'request_id' => (string) Str::uuid(), 'lock_version' => 1, 'donated_on' => now('Asia/Damascus')->subDay()->toDateString(), 'blood_group' => 'O', 'rh' => 'positive', 'units' => '1.2500'];
        $file = storage_path('framework/testing/blood-correction-'.Str::uuid().'.json');
        file_put_contents($file, json_encode(['user_id' => $f['user']->id, 'donor' => $id, 'donation' => $event, 'payload' => $payload], JSON_THROW_ON_ERROR));
        // Explicitly carry the guarded connection into the worker. PHPUnit's
        // dotenv values can otherwise override a MariaDB run's process environment.
        $db = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, 'tests/Support/blood-correction-worker.php', $file], base_path(), ['APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'TEST_DATABASE_CONFIRMED' => 'true',
            'TEST_DATABASE_HOST' => $db['host'], 'TEST_DATABASE_NAME' => $db['database']]);
        $process->setTimeout(20);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $out) => str_contains($out, 'DONOR_LOCKED')), $process->getErrorOutput());
            $start = microtime(true);
            $token = $f['user']->createToken('blood-concurrency', ['api'])->plainTextToken;
            $this->putJson("/api/blood-bank/donor/$id/donations/$event", ['request_id' => (string) Str::uuid(), 'donated_on' => $f['today']] + $payload, ['Authorization' => 'Bearer '.$token])->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_VERSION_CONFLICT');
            $this->assertGreaterThan(0.3, microtime(true) - $start, 'Correction overlaps the other transaction.');
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertDatabaseCount('blood_donations', 1);
            $this->assertDatabaseCount('blood_donation_codes', 2);
            $this->assertDatabaseHas('blood_donations', ['id' => $event, 'donated_on' => $payload['donated_on'], 'lock_version' => 2, 'units' => '1.2500']);
        } finally {
            $process->stop();
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
