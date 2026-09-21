<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\DossierCompletionCase;

class ServiceRequestTest extends DossierCompletionCase
{
    public function test_pending_service_saves_without_performance_or_period(): void
    {
        $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk();
        $this->assertDatabaseHas('visit_services', ['visit_id' => $this->s['visit']['id'], 'status' => 'pending', 'performed_on' => null, 'reporting_period_id' => null, 'requested_on' => '2001-03-02']);
    }

    public function test_completed_service_saves_with_visit_date(): void
    {
        $this->saveSection('clinical', $this->clinical(['status' => 'completed']))->assertOk();
        $this->assertDatabaseHas('visit_services', ['visit_id' => $this->s['visit']['id'], 'status' => 'completed', 'performed_on' => '2001-03-02']);
    }

    public function test_completing_a_pending_service_stamps_the_period_from_performed_on(): void
    {
        $period = $this->period('2001-03-01', '2001-03-31');
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->callApi('POST', $this->path('/services/'.$row['id'].'/complete'), ['lock_version' => $row['lock_version'], 'performed_on' => '2001-03-20'])->assertOk();
        $this->assertDatabaseHas('visit_services', ['id' => $row['id'], 'status' => 'completed', 'performed_on' => '2001-03-20', 'reporting_period_id' => $period]);
    }

    public function test_requested_in_september_completed_in_october_stamps_october(): void
    {
        $september = $this->period('2005-09-01', '2005-09-30');
        $october = $this->period('2005-10-01', '2005-10-31');
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2005-09-15', 'diagnoses' => $this->savedDiagnoses()]))->assertOk()->json('data');
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->callApi('POST', $this->path('/services/'.$row['id'].'/complete'), ['lock_version' => $row['lock_version'], 'performed_on' => '2005-10-02'])->assertOk();
        $this->assertDatabaseHas('visit_services', ['id' => $row['id'], 'reporting_period_id' => $october]);
        $this->assertDatabaseMissing('visit_services', ['id' => $row['id'], 'reporting_period_id' => $september]);
    }

    public function test_duplicate_pending_request_names_the_existing_date(): void
    {
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $existing = $this->s['clinical']['services'][0];
        $response = $this->saveSection('clinical', ['services' => [$existing, ['catalog_id' => $this->f['service'], 'status' => 'pending'] + $this->context()], 'procedures' => []])->assertUnprocessable()->assertJsonValidationErrors('services.1.catalog_id');
        $this->assertSame('المريض مسجّل على هذه الخدمة منذ 2001-03-02 وما زال ينتظر. سجّل انتهاءها أو ألغِها أولاً.', $response->json('errors')['services.1.catalog_id'][0]);
    }

    public function test_same_service_for_a_different_patient_saves(): void
    {
        $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk();
        $other = $this->callApi('POST', '', ['person_mode' => 'new', 'code' => 'PH3-'.Str::random(8), 'opening_date' => '2001-01-01', 'visit_date' => '2001-03-02', 'visit_type_id' => $this->f['visit_type'], 'first_name' => 'سارة', 'family_name' => 'علي', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'])->assertCreated()->json('data');
        $other = $this->callApi('PUT', "/{$other['id']}/medical", ['lock_version' => 1, 'is_oncology' => false])->assertOk()->json('data');
        $other = $this->callApi('PUT', "/{$other['id']}/visits/{$other['visit']['id']}", $this->visit(['lock_version' => $other['visit']['lock_version']]))->assertOk()->json('data');
        $this->callApi('PUT', "/{$other['id']}/visits/{$other['visit']['id']}/clinical", $this->clinical(['status' => 'pending']) + ['lock_version' => $other['visit']['lock_version']])->assertOk();
        $this->assertSame(2, DB::table('visit_services')->where('service_id', $this->f['service'])->where('status', 'pending')->whereNull('voided_at')->count());
    }

    public function test_same_patient_can_register_again_after_complete_or_cancel(): void
    {
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->s = $this->callApi('POST', $this->path('/services/'.$row['id'].'/complete'), ['lock_version' => $row['lock_version'], 'performed_on' => '2001-03-02'])->assertOk()->json('data');
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $again = $this->s['clinical']['services'][1];
        $this->assertSame('pending', $again['status']);
        $this->s = $this->callApi('POST', $this->path('/services/'.$again['id'].'/cancel'), ['lock_version' => $again['lock_version'], 'cancelled_reason' => 'لم يحضر'])->assertOk()->json('data');
        $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk();
        $this->assertSame(1, DB::table('visit_services')->where('visit_id', $this->s['visit']['id'])->where('service_id', $this->f['service'])->where('status', 'pending')->whereNull('voided_at')->count());
    }

    public function test_completing_a_non_pending_service_is_conflict(): void
    {
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'completed']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->callApi('POST', $this->path('/services/'.$row['id'].'/complete'), ['lock_version' => $row['lock_version'], 'performed_on' => '2001-03-02'])->assertConflict()->assertJsonPath('error.code', 'SERVICE_NOT_PENDING');
    }

    public function test_cancelling_without_a_reason_is_unprocessable(): void
    {
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->callApi('POST', $this->path('/services/'.$row['id'].'/cancel'), ['lock_version' => $row['lock_version']])->assertUnprocessable()->assertJsonValidationErrors('cancelled_reason');
    }

    public function test_completing_before_requested_on_is_unprocessable(): void
    {
        $this->s = $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->callApi('POST', $this->path('/services/'.$row['id'].'/complete'), ['lock_version' => $row['lock_version'], 'performed_on' => '2001-03-01'])->assertUnprocessable()->assertJsonValidationErrors('performed_on');
    }

    public function test_a_different_unique_violation_in_the_same_save_surfaces_unchanged(): void
    {
        $uuid = (string) Str::uuid();
        $visit = DB::table('visits')->where('id', $this->s['visit']['id'])->first();
        DB::table('visit_services')->insert(['visit_id' => $visit->id, 'facility_id' => $this->f['facility'], 'patient_id' => $visit->patient_id, 'service_id' => $this->f['service'], 'status' => 'completed', 'requested_on' => '2001-03-02', 'performed_on' => '2001-03-02', 'client_request_id' => $uuid, 'entered_by' => $this->f['user']->id]);
        Str::createUuidsUsing(fn () => Uuid::fromString($uuid));
        try {
            $this->withoutExceptionHandling();
            $this->saveSection('clinical', $this->clinical(['catalog_id' => $this->f['items']['service'][2], 'status' => 'completed']));
            $this->fail('A different 1062 should surface unchanged.');
        } catch (QueryException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
            $this->assertStringNotContainsString('visit_services_one_open_request', $e->getMessage());
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function test_pending_queue_lists_oldest_first_with_days_waiting(): void
    {
        $this->saveSection('clinical', $this->clinical(['status' => 'pending']))->assertOk();
        $other = $this->callApi('POST', '', ['person_mode' => 'new', 'code' => 'PH3-'.Str::random(8), 'opening_date' => '2001-01-01', 'visit_date' => '2001-01-10', 'visit_type_id' => $this->f['visit_type'], 'first_name' => 'ليلى', 'family_name' => 'حسن', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'])->assertCreated()->json('data');
        $other = $this->callApi('PUT', "/{$other['id']}/medical", ['lock_version' => 1, 'is_oncology' => false])->assertOk()->json('data');
        $other = $this->callApi('PUT', "/{$other['id']}/visits/{$other['visit']['id']}", $this->visit(['lock_version' => $other['visit']['lock_version'], 'visit_date' => '2001-01-10']))->assertOk()->json('data');
        $this->callApi('PUT', "/{$other['id']}/visits/{$other['visit']['id']}/clinical", $this->clinical(['status' => 'pending']) + ['lock_version' => $other['visit']['lock_version']])->assertOk();
        $data = $this->callApi('GET', '/services/pending')->assertOk()->json('data');
        $this->assertSame(['2001-01-10', '2001-03-02'], array_column($data, 'requested_on'));
        $this->assertSame((int) DB::selectOne('SELECT DATEDIFF(?, ?) as d', [$this->f['today'], '2001-01-10'])->d, (int) $data[0]['days_waiting']);
        $this->assertSame($this->f['service'], $data[0]['service_id']);
        $this->assertNotEmpty($data[0]['name_ar']);
        $this->assertSame($other['id'], $data[0]['dossier_id']);
    }

    public function test_visit_procedures_are_unchanged_by_this_work(): void
    {
        $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $this->f['procedure']] + $this->context()]])->assertOk();
        $this->assertFalse(Schema::hasColumn('visit_procedures', 'status'));
        $this->assertFalse(Schema::hasColumn('visit_procedures', 'requested_on'));
        $this->assertDatabaseHas('visit_procedures', ['visit_id' => $this->s['visit']['id'], 'performed_on' => '2001-03-02']);
    }

    private function clinical(array $extra = []): array
    {
        return ['services' => [$extra + ['catalog_id' => $this->f['service'], 'status' => 'completed'] + $this->context()], 'procedures' => []];
    }

    private function period(string $starts, string $ends): int
    {
        return DB::table('reporting_periods')->insertGetId(['facility_id' => $this->f['facility'], 'starts_on' => $starts, 'ends_on' => $ends, 'status' => 'open']);
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
