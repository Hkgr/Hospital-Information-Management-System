<?php

namespace Tests\Feature;

use App\Support\TestDatabaseSafety;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionFixture;
use Tests\TestCase;

class PatientWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseSafety::assertAvailable($this->app);
        $this->f = DossierCompletionFixture::make();
        $this->withHeader('Authorization', 'Bearer '.$this->f['user']->createToken('workspace', ['api'])->plainTextToken);
    }

    public function test_registration_and_visit_correction_have_no_classification(): void
    {
        $input = ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'person_mode' => 'new',
            'code' => 'WORK-'.Str::random(12), 'opening_date' => '2000-01-01', 'visit_date' => '2001-03-02',
            'first_name' => 'أحمد', 'family_name' => 'محمد', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'];
        $s = $this->postJson('/api/dossiers', $input)->assertCreated()->json('data');
        $this->assertArrayNotHasKey('visit_type_id', $s['visit']);
        $this->assertSame('draft', $s['visit']['status']);
        $this->postJson('/api/dossiers', $input)->assertCreated()->assertJsonPath('data.visit.id', $s['visit']['id']);
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $s['id'])->count());
        $this->putJson("/api/dossiers/{$s['id']}/visits/{$s['visit']['id']}", [
            'facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid(), 'lock_version' => $s['visit']['lock_version'],
            'visit_date' => '2001-03-03', 'is_referred' => false, 'diagnoses' => [],
        ])->assertOk()->assertJsonPath('data.visit.visit_date', '2001-03-03');
        $this->postJson('/api/dossiers', array_replace($input, ['request_id' => (string) Str::uuid(), 'visit_date' => '2099-01-01']))->assertUnprocessable();
    }

    public function test_facility_visit_directory_filters_real_visits_without_leaking_other_facilities(): void
    {
        $facility = $this->f['facility'];
        $r = $this->getJson("/api/dossiers/visits?facility_id=$facility&search=".urlencode($this->f['search_patient_code']))->assertOk();
        $this->assertNotEmpty($r->json('data'));
        foreach ($r->json('data') as $row) {
            $this->assertSame($this->f['search_patient_code'], $row['patient_code']);
            $this->assertNotEmpty($row['dossier_id']);
        }
        $this->getJson("/api/dossiers/visits?facility_id=$facility&status=draft")->assertOk();
        $this->getJson('/api/dossiers/visits?facility_id='.$this->f['other'])->assertForbidden();
    }

    public function test_doctor_options_and_saved_diagnoses_follow_clinic_and_actual_date(): void
    {
        $facility = $this->f['facility'];
        [$clinicA, $clinicB] = $this->f['clinics'];
        [$doctorA, $doctorB] = $this->f['workflow_doctors'];
        $this->getJson("/api/dossiers/options/doctors?facility_id=$facility&clinic_id=$clinicA&visit_date=2001-03-02")
            ->assertOk()->assertJsonPath('data.0.id', $doctorA)->assertJsonMissing(['id' => $doctorB]);
        $this->getJson("/api/dossiers/options/doctors?facility_id=$facility&clinic_id=$clinicB&visit_date=1980-01-01")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/dossiers/options/doctors?facility_id=$facility&clinic_id=$clinicB&visit_date=2001-03-02")
            ->assertOk()->assertJsonPath('data.0.id', $doctorB)->assertJsonMissing(['id' => $doctorA]);
    }

    public function test_documented_workspace_routes_have_no_visit_classification(): void
    {
        $doc = $this->getJson('/docs/api.json')->assertOk()->json();
        $paths = $doc['paths'];
        $this->assertArrayHasKey('/api/dossiers/visits', $paths);
        $schema = $paths['/api/dossiers/visits']['get']['responses']['200']['content']['application/json']['schema'];
        $this->assertArrayHasKey('patient_code', $schema['properties']['data']['items']['properties']);
        $this->assertStringNotContainsString('visit_type_id', json_encode($doc));
        $this->assertStringNotContainsString('visit_types', json_encode($doc));
    }
}
