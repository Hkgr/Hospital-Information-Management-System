<?php

namespace Tests\Feature;

use App\Services\Periods\PeriodWriter;
use Database\Seeders\ReportingPeriodPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionCase;

class ReportingPeriodTest extends DossierCompletionCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ReportingPeriodPermissionsSeeder::class)->run();
        foreach (array_keys(ReportingPeriodPermissionsSeeder::PERMISSIONS) as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
    }

    public function test_a_visit_inside_an_existing_period_is_stamped_with_it(): void
    {
        $period = $this->month(2001, 3);
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => $this->savedDiagnoses()]))->assertOk();
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id'], 'reporting_period_id' => $period]);
    }

    public function test_a_visit_in_a_month_with_no_period_is_stamped_null_and_saves(): void
    {
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id'], 'reporting_period_id' => null, 'visit_date' => '2001-03-02']);
    }

    public function test_a_visit_is_stamped_from_its_own_date_not_the_dossier_opening(): void
    {
        $august = $this->month(2001, 8);
        $september = $this->month(2001, 9);
        $card = $this->callApi('POST', '', ['person_mode' => 'new', 'code' => 'PH3-AUG', 'opening_date' => '2001-08-01', 'visit_date' => '2001-09-15', 'visit_type_id' => $this->f['visit_type'], 'first_name' => 'خالد', 'family_name' => 'يوسف', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'])->assertCreated()->json('data');
        $this->assertDatabaseHas('visits', ['id' => $card['visit']['id'], 'reporting_period_id' => $september]);
        $this->assertDatabaseMissing('visits', ['id' => $card['visit']['id'], 'reporting_period_id' => $august]);
    }

    public function test_changing_a_visit_date_to_another_month_restamps_it(): void
    {
        $march = $this->month(2001, 3);
        $april = $this->month(2001, 4);
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => $this->savedDiagnoses()]))->assertOk()->json('data');
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id'], 'reporting_period_id' => $march]);
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-04-10', 'diagnoses' => $this->savedDiagnoses()]))->assertOk();
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id'], 'reporting_period_id' => $april]);
    }

    public function test_overlapping_periods_return_ambiguous_and_write_nothing(): void
    {
        DB::table('reporting_periods')->insert([['facility_id' => $this->f['facility'], 'starts_on' => '2001-03-01', 'ends_on' => '2001-03-31', 'status' => 'open'], ['facility_id' => $this->f['facility'], 'starts_on' => '2001-03-15', 'ends_on' => '2001-04-15', 'status' => 'open']]);
        $before = DB::table('visits')->where('id', $this->s['visit']['id'])->first();
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-03-20']))->assertConflict()->assertJsonPath('error.code', 'PERIOD_AMBIGUOUS');
        $this->assertEquals($before, DB::table('visits')->where('id', $before->id)->first());
    }

    public function test_creating_an_existing_month_conflicts(): void
    {
        $this->periods('POST', '', ['year' => 2004, 'month' => 6])->assertCreated();
        $this->periods('POST', '', ['year' => 2004, 'month' => 6])->assertConflict()->assertJsonPath('error.code', 'PERIOD_EXISTS');
    }

    public function test_open_submitted_locked_reopen_and_reopen_requires_reason(): void
    {
        $period = $this->periods('POST', '', ['year' => 2004, 'month' => 7])->assertCreated()->json('data');
        $period = $this->periods('POST', '/'.$period['id'].'/submit', ['lock_version' => $period['lock_version']])->assertOk()->assertJsonPath('data.status', 'submitted')->json('data');
        $period = $this->periods('POST', '/'.$period['id'].'/lock', ['lock_version' => $period['lock_version']])->assertOk()->assertJsonPath('data.status', 'locked')->json('data');
        $this->periods('POST', '/'.$period['id'].'/reopen', ['lock_version' => $period['lock_version']])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->periods('POST', '/'.$period['id'].'/reopen', ['lock_version' => $period['lock_version'], 'reason' => 'تصحيح الإقفال'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'reporting_period', 'entity_id' => $period['id'], 'event' => 'reopened']);
    }

    public function test_lock_from_open_is_an_invalid_transition(): void
    {
        $period = $this->periods('POST', '', ['year' => 2004, 'month' => 8])->assertCreated()->json('data');
        $this->periods('POST', '/'.$period['id'].'/lock', ['lock_version' => $period['lock_version']])->assertConflict()->assertJsonPath('error.code', 'PERIOD_INVALID_TRANSITION');
    }

    public function test_unassigned_lists_exactly_the_rows_with_a_date_and_no_period(): void
    {
        $expected = [];
        foreach (PeriodWriter::TABLES as $table => $date) {
            foreach (DB::table($table)->where('facility_id', $this->f['facility'])->whereNotNull($date)->whereNull('reporting_period_id')->selectRaw("DATE_FORMAT(`$date`, '%Y-%m') as month")->selectRaw('COUNT(*) as count')->groupBy('month')->orderBy('month')->get() as $row) {
                $expected[] = ['table' => $table, 'month' => $row->month, 'count' => (int) $row->count];
            }
        }
        $this->periods('GET', '/unassigned')->assertOk()->assertExactJson(['data' => $expected]);
    }

    public function test_backfill_previews_then_stamps_only_null_rows_and_never_creates_a_period(): void
    {
        $period = $this->month(2001, 3);
        $visit = $this->s['visit']['id'];
        $this->assertDatabaseHas('visits', ['id' => $visit, 'reporting_period_id' => null]);
        $already = DB::table('visits')->whereNotNull('reporting_period_id')->orderBy('id')->get()->toJson();
        $count = DB::table('reporting_periods')->count();
        $this->artisan('periods:backfill')->assertSuccessful();
        $this->assertDatabaseHas('visits', ['id' => $visit, 'reporting_period_id' => null]);
        $this->artisan('periods:backfill', ['--apply' => true])->assertSuccessful();
        $this->assertDatabaseHas('visits', ['id' => $visit, 'reporting_period_id' => $period]);
        $this->assertSame($already, DB::table('visits')->whereNotNull('reporting_period_id')->where('id', '!=', $visit)->orderBy('id')->get()->toJson());
        $this->assertSame($count, DB::table('reporting_periods')->count());
    }

    public function test_blood_bank_rows_are_untouched(): void
    {
        $this->month(2001, 3);
        $donations = DB::table('blood_donations')->orderBy('id')->get()->toJson();
        $events = DB::table('blood_bank_events')->orderBy('id')->get()->toJson();
        $transfusions = DB::table('blood_transfusions')->orderBy('id')->get()->toJson();
        $this->artisan('periods:backfill', ['--apply' => true])->assertSuccessful();
        $this->assertSame($donations, DB::table('blood_donations')->orderBy('id')->get()->toJson());
        $this->assertSame($events, DB::table('blood_bank_events')->orderBy('id')->get()->toJson());
        $this->assertSame($transfusions, DB::table('blood_transfusions')->orderBy('id')->get()->toJson());
    }

    public function test_permissions_seeder_writes_no_role_permissions_rows(): void
    {
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson();
        app(ReportingPeriodPermissionsSeeder::class)->run();
        app(ReportingPeriodPermissionsSeeder::class)->run();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toJson());
        $this->assertDatabaseHas('permissions', ['code' => 'periods.view']);
    }

    private function month(int $year, int $month): int
    {
        $starts = sprintf('%04d-%02d-01', $year, $month);
        $ends = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        return DB::table('reporting_periods')->insertGetId(['facility_id' => $this->f['facility'], 'starts_on' => $starts, 'ends_on' => $ends, 'status' => 'open']);
    }

    private function periods(string $method, string $path = '', array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/periods'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function savedDiagnoses(): array
    {
        $row = $this->s['visit']['diagnoses'][0];

        return [[
            'id' => $row['id'],
            'lock_version' => $row['lock_version'],
            'diagnosis_id' => $row['diagnosis_id'],
            'diagnosed_on' => $row['diagnosed_on'] ?? null,
            'clinic_id' => $row['clinic_id'],
            'diagnosing_staff_id' => $row['diagnosing_staff_id'],
        ]];
    }
}
