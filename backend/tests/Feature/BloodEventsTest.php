<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodBankReconcile;
use App\Services\BloodBank\BloodBankReports;
use App\Services\BloodBank\BloodEventReports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\AssertsOpenApi;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

class BloodEventsTest extends TestCase
{
    use AssertsOpenApi;
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = BloodBankFixture::make();
        app(BloodBankReconcile::class)->apply();
        $this->token = $this->f['user']->createToken('events-test', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path = '/events', array $data = [], ?string $token = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/blood-bank'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    private function payload(string $kind = 'donation', ?int $person = null): array
    {
        return ['request_id' => (string) Str::uuid(), 'kind' => $kind, ...($kind === 'benefit' ? ['benefit_kind' => 'issue'] : []),
            ...($person ? ['person_id' => $person] : ['person' => ['person_mode' => 'direct', 'first_name' => 'أحمد', 'family_name' => 'محمد', 'gender' => 'unknown', 'birth_date_accuracy' => 'unknown', 'displacement_status' => 'unknown']]),
            'occurred_on' => $this->f['today'], 'quantity' => '0.4500', 'quantity_unit' => 'kg', 'blood_component_id' => $this->f['component'],
            'clinic_id' => $this->f['clinic'], 'responsible_staff_id' => $this->f['staff'], 'blood_group' => 'O', 'rh' => 'positive',
            'screenings' => [['analyte' => 'HCV', 'status' => 'pending']]];
    }

    public function test_atomic_registration_retries_and_one_person_with_multiple_event_types(): void
    {
        $before = DB::table('blood_bank_people')->count();
        $input = $this->payload();
        $this->api('POST', '/events', ['responsible_staff_id' => 999999] + $input)->assertUnprocessable();
        $this->assertDatabaseCount('blood_bank_people', $before);
        $a = $this->api('POST', '/events', $input)->assertCreated()->assertJsonPath('data.quantity', '0.4500')->assertJsonPath('data.quantity_unit', 'kg')->json('data');
        $this->api('POST', '/events', $input)->assertCreated()->assertJsonPath('data.id', $a['id']);
        $this->api('POST', '/events', ['quantity' => '0.5'] + $input)->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_REQUEST_CONFLICT');
        $this->api('POST', '/events', $this->payload('donation', $a['person_id']))->assertCreated();
        $this->api('POST', '/events', $this->payload('benefit', $a['person_id']))->assertCreated();
        $this->api('POST', '/events', $this->payload('benefit', $a['person_id']))->assertCreated();
        $this->assertDatabaseCount('blood_bank_people', $before + 1);
        $this->assertDatabaseCount('blood_donations', 2);
        $this->api('GET', '/events', ['person_id' => $a['person_id']])->assertOk()->assertJsonPath('totals', ['donations' => 2, 'benefits' => 2, 'unique_people' => 1]);
        $this->api('GET', '/people/'.$a['person_id'])->assertJsonPath('data.totals.donations', 2);
        $this->assertDatabaseHas('blood_bank_event_screenings', ['event_id' => $a['id'], 'status' => 'pending', 'result' => null]);
        $this->assertDatabaseCount('blood_donation_screenings', 0);
    }

    public function test_issue_is_not_transfusion_and_linked_stages_count_as_one_benefit(): void
    {
        $before = DB::table('blood_transfusions')->count();
        $issue = $this->api('POST', '/events', $this->payload('benefit'))->assertCreated()->json('data');
        $this->assertDatabaseCount('blood_transfusions', $before);
        $input = ['benefit_kind' => 'transfusion', 'benefit_link_mode' => 'linked', 'issue_event_id' => $issue['id']] + $this->payload('benefit', $issue['person_id']);
        $transfer = $this->api('POST', '/events', $input)->assertCreated()->json('data');
        $this->assertDatabaseCount('blood_transfusions', $before + 1);
        $this->api('GET', '/events', ['person_id' => $issue['person_id']])->assertJsonPath('meta.total', 2)->assertJsonPath('totals.benefits', 1);
        $this->api('POST', '/events', ['request_id' => (string) Str::uuid()] + $input)->assertUnprocessable()->assertJsonValidationErrors('issue_event_id');
        $this->api('GET', '/events/'.$issue['id'])->assertJsonPath('data.linked_transfusion_id', $transfer['id']);
        $this->api('POST', '/events', ['benefit_kind' => 'transfusion'] + $this->payload('benefit', $issue['person_id']))->assertUnprocessable()->assertJsonValidationErrors('benefit_link_mode');
        $this->api('POST', '/events', ['benefit_kind' => null] + $this->payload('benefit'))->assertUnprocessable();
    }

    public function test_quantities_are_explicit_positive_decimal_kg_and_old_units_are_preserved(): void
    {
        foreach (['', '0', '-1', '1.12345', '100000000000000', '1e2'] as $quantity) {
            $this->api('POST', '/events', ['quantity' => $quantity] + $this->payload())->assertUnprocessable()->assertJsonValidationErrors('quantity');
        }
        $this->api('POST', '/events', ['quantity_unit' => 'unit'] + $this->payload())->assertUnprocessable();
        $historic = DB::table('blood_bank_events')->where('legacy', true)->first();
        $this->assertSame('unit', $historic->quantity_unit);
        $this->assertSame('1.0000', $historic->quantity);
        $this->api('POST', '/events', ['occurred_on' => now('Asia/Damascus')->addDay()->toDateString()] + $this->payload())->assertUnprocessable();
        $this->api('POST', '/events', ['occurred_on' => '2000-01-01'] + $this->payload())->assertUnprocessable();
    }

    public function test_date_corrections_keep_aliases_and_versions_and_screening_history(): void
    {
        $row = $this->api('POST', '/events', $this->payload())->assertCreated()->json('data');
        DB::table('blood_bank_event_screenings')->where('event_id', $row['id'])->update(['screening_test_id' => $this->f['test'], 'result' => 'indeterminate']);
        $input = ['lock_version' => 1, 'occurred_on' => now('Asia/Damascus')->subDay()->toDateString(), 'screenings' => [['analyte' => 'HCV', 'status' => 'complete']]] + $this->payload('donation', $row['person_id']);
        $changed = $this->api('PUT', '/events/'.$row['id'], $input)->assertOk()->assertJsonPath('data.screenings.0.result', 'indeterminate')->assertJsonPath('data.lock_version', 2)->json('data');
        $this->assertNotSame($row['code'], $changed['code']);
        $this->api('GET', '/events', ['search' => $row['code']])->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', $changed['code']);
        $this->api('PUT', '/events/'.$row['id'], ['request_id' => (string) Str::uuid()] + $input)->assertConflict()->assertJsonPath('error.code', 'BLOOD_BANK_VERSION_CONFLICT');
        $this->assertDatabaseCount('blood_donations', 1);
        $this->assertDatabaseHas('blood_donation_codes', ['code' => $row['code'], 'blood_donation_id' => $row['blood_donation_id']]);
    }

    public function test_patient_identity_is_reused_not_copied_and_permission_is_global(): void
    {
        $patient = $this->f['patients'][2];
        $input = ['person' => ['person_mode' => 'patient', 'patient_id' => $patient]] + $this->payload('benefit');
        $row = $this->api('POST', '/events', $input)->assertCreated()->json('data');
        $this->api('POST', '/events', ['request_id' => (string) Str::uuid()] + $input)->assertCreated()->assertJsonPath('data.person_id', $row['person_id']);
        $this->assertDatabaseHas('blood_bank_people', ['id' => $row['person_id'], 'patient_id' => $patient, 'first_name' => null, 'address_line' => null]);
        DB::table('patients')->where('id', $patient)->update(['first_name' => 'حديث', 'address_line' => 'عنوان محدث']);
        $this->api('GET', '/people/'.$row['person_id'])->assertJsonPath('data.person.address_line', 'عنوان محدث');
        $this->api('POST', '/events', ['person' => ['person_mode' => 'patient', 'patient_id' => $patient, 'first_name' => 'نسخة']] + $this->payload())->assertUnprocessable();
        DB::table('global_user_roles')->where('user_id', $this->f['user']->id)->delete();
        $this->api('POST', '/events', ['request_id' => (string) Str::uuid()] + $input)->assertForbidden();
    }

    public function test_scoped_permissions_and_server_search_totals_across_pages(): void
    {
        $row = $this->api('POST', '/events', $this->payload())->assertCreated()->json('data');
        for ($i = 0; $i < 11; $i++) {
            $this->api('POST', '/events', $this->payload('benefit', $row['person_id']))->assertCreated();
        }
        foreach ([1, 2] as $page) {
            $this->api('GET', '/events', ['search' => '  أحمد   محمد ', 'per_page' => 10, 'page' => $page])->assertOk()->assertJsonPath('meta.total', 12)->assertJsonPath('totals', ['donations' => 1, 'benefits' => 11, 'unique_people' => 1]);
        }
        foreach (['%', '_', 'لا يوجد'] as $search) {
            $this->api('GET', '/events', ['search' => $search])->assertJsonPath('meta.total', 0);
        }
        foreach (['/events', '/events/'.$row['id'], '/people/'.$row['person_id'], '/events/export/pdf'] as $path) {
            $this->api('GET', $path, ['facility_id' => $this->f['other']])->assertForbidden()->assertJsonMissingPath('data')->assertHeader('Cache-Control', 'no-store, private');
            $this->api('GET', $path, [], 'invalid')->assertUnauthorized();
        }
        $viewer = $this->f['viewer']->createToken('viewer', ['api'])->plainTextToken;
        $this->api('POST', '/events', $this->payload('benefit'), $viewer)->assertForbidden();
        $this->api('POST', '/events', $this->payload(), $viewer)->assertForbidden();
        DB::table('facilities')->where('id', $this->f['facility'])->update(['is_active' => false]);
        $this->api('GET')->assertForbidden();
    }

    public function test_reconciliation_is_idempotent_and_does_not_invent_events_for_old_profiles(): void
    {
        $before = app(BloodBankReconcile::class)->inventory();
        $id = DB::table('blood_donors')->insertGetId(['facility_id' => $this->f['facility'], 'donor_code' => 'OLD-001', 'full_name' => 'اسم قديم لا يجزأ', 'entered_by' => $this->f['user']->id]);
        app(BloodBankReconcile::class)->apply();
        $person = DB::table('blood_donors')->where('id', $id)->value('person_id');
        $this->assertDatabaseCount('blood_bank_events', $before['blood_bank_events']);
        $this->api('GET', '/people/'.$person)->assertJsonPath('data.name', 'اسم قديم لا يجزأ')->assertJsonPath('data.totals.unique_people', 0);
        $this->api('GET', '/people', ['search' => 'OLD-001'])->assertJsonPath('meta.total', 1);
        $after = app(BloodBankReconcile::class)->inventory();
        $this->assertSame($after, app(BloodBankReconcile::class)->apply()['after']);
        $this->assertDatabaseHas('blood_bank_identity_reviews', ['source' => 'blood_donors', 'source_id' => $id]);
    }

    public function test_person_edits_do_not_change_event_blood_type_and_stale_edits_are_rejected(): void
    {
        $row = $this->api('POST', '/events', $this->payload())->assertCreated()->json('data');
        $p = $this->payload()['person'] + ['blood_group' => 'AB', 'rh' => 'negative'];
        $input = ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'person' => $p];
        $this->api('PUT', '/people/'.$row['person_id'], $input)->assertOk()->assertJsonPath('data.blood_group', 'AB');
        $this->api('GET', '/events/'.$row['id'])->assertJsonPath('data.blood_group', 'O');
        $this->api('PUT', '/people/'.$row['person_id'], ['request_id' => (string) Str::uuid()] + $input)->assertConflict();
    }

    public function test_event_correction_keeps_the_original_clinical_recipient_name_after_person_edit(): void
    {
        $row = $this->api('POST', '/events', ['benefit_kind' => 'transfusion', 'benefit_link_mode' => 'independent'] + $this->payload('benefit'))->assertCreated()->json('data');
        $original = DB::table('blood_transfusions')->where('id', $row['blood_transfusion_id'])->value('external_recipient_name');
        $personal = ['first_name' => 'اسم مصحح'] + $this->payload()['person'];
        $this->api('PUT', '/people/'.$row['person_id'], ['request_id' => (string) Str::uuid(), 'lock_version' => 1, 'person' => $personal])->assertOk();
        $this->api('PUT', '/events/'.$row['id'], ['benefit_kind' => 'transfusion', 'benefit_link_mode' => 'independent', 'lock_version' => 1, 'quantity' => '0.6000'] + $this->payload('benefit', $row['person_id']))->assertOk()->assertJsonPath('data.name', 'اسم مصحح محمد');
        $this->assertDatabaseHas('blood_transfusions', ['id' => $row['blood_transfusion_id'], 'external_recipient_name' => $original, 'units' => '0.6000']);
    }

    public function test_reports_and_openapi_describe_actual_events_and_units_and_retire_legacy_writes(): void
    {
        $row = $this->api('POST', '/events', $this->payload('benefit'))->assertCreated()->json('data');
        $this->api('POST', '', BloodBankFixture::profile($this->f))->assertStatus(410);
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        foreach (['/events', '/events/'.$row['id'], '/people', '/people/'.$row['person_id']] as $path) {
            $template = preg_replace('#/\d+$#', str_contains($path, '/people/') ? '/{person}' : '/{event}', $path);
            $operation = $doc['paths']['/api/blood-bank'.$template]['get'];
            $this->assertSame([['bearerAuth' => []]], $operation['security']);
            $this->assertMatchesSchema($doc, $operation['responses']['200']['content']['application/json']['schema'], $this->api('GET', $path)->assertOk()->json());
        }
        $r = Request::create('/api/blood-bank/events');
        $r->setUserResolver(fn () => $this->f['user']);
        $f = app(BloodBankAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        foreach ([[null, null], [$row['person_id'], null], [null, $row['id']]] as [$person,$event]) {
            $document = app(BloodEventReports::class)->document($r, $f, [], $person, $event);
            $ledger = collect($document['sections'])->firstWhere('title', 'سجل التبرع والاستفادة');
            $this->assertNotEmpty($ledger['rows']);
            $kg = collect($ledger['rows'])->firstWhere('code', $row['code']);
            $this->assertSame('كغ', $kg['quantity_unit']);
            $this->assertStringContainsString('صرف', $kg['type']);
            $path = tempnam(storage_path('framework/testing'), 'events-xlsx');
            try {
                file_put_contents($path, app(BloodBankReports::class)->xlsx($document));
                $book = IOFactory::load($path);
                $sheet = $book->getSheetByName('سجل التبرع والاستفادة');
                $this->assertNotNull($sheet);
                $found = false;
                foreach ($sheet->getRowIterator() as $line) {
                    $n = $line->getRowIndex();
                    if ($sheet->getCell('A'.$n)->getValue() === $row['code']) {
                        $found = true;
                        $this->assertSame('s', $sheet->getCell('A'.$n)->getDataType());
                        $this->assertSame('n', $sheet->getCell('G'.$n)->getDataType());
                        $this->assertEquals(0.45, $sheet->getCell('G'.$n)->getValue());
                    }
                }
                $this->assertTrue($found);
            } finally {
                unlink($path);
            }
        }
        $this->api('GET', '/events/'.$row['id'].'/report/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_excel_preserves_large_decimal_exactly_and_keeps_legacy_units_separate(): void
    {
        $quantity = '12345678901234.5678';
        $row = $this->api('POST', '/events', ['quantity' => $quantity] + $this->payload('benefit'))->assertCreated()->json('data');
        $request = Request::create('/api/blood-bank/events');
        $request->setUserResolver(fn () => $this->f['user']);
        $facility = app(BloodBankAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $document = app(BloodEventReports::class)->document($request, $facility, []);
        $ledger = collect($document['sections'])->firstWhere('title', 'سجل التبرع والاستفادة');
        $this->assertContains('وحدة (تاريخية)', array_column($ledger['rows'], 'quantity_unit'));
        $this->assertContains('كغ', array_column($ledger['rows'], 'quantity_unit'));
        $path = tempnam(storage_path('framework/testing'), 'events-decimal');
        try {
            file_put_contents($path, app(BloodBankReports::class)->xlsx($document));
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('سجل التبرع والاستفادة');
            $found = false;
            foreach ($sheet->getRowIterator() as $line) {
                $n = $line->getRowIndex();
                if ($sheet->getCell('A'.$n)->getValue() === $row['code']) {
                    $found = true;
                    $this->assertSame('s', $sheet->getCell('G'.$n)->getDataType());
                    $this->assertSame($quantity, $sheet->getCell('G'.$n)->getValue());
                    $this->assertSame('كغ', $sheet->getCell('H'.$n)->getValue());
                }
            }
            $this->assertTrue($found);
        } finally {
            unlink($path);
        }
    }
}
