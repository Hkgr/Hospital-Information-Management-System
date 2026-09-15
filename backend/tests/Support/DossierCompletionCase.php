<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class DossierCompletionCase extends TestCase
{
    use RefreshDatabase;

    protected array $f;

    protected string $token;

    protected array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierCompletionFixture::make();
        $this->token = $this->f['user']->createToken('completion-test', ['api'])->plainTextToken;
        $this->s = $this->callApi('POST', '', ['person_mode' => 'new', 'code' => 'PH3-'.Str::random(8), 'opening_date' => '2001-01-01', 'visit_date' => '2001-03-02', 'visit_type_id' => $this->f['visit_type'], 'first_name' => 'أحمد', 'family_name' => 'محمد', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown'])->assertCreated()->json('data');
        $this->s = $this->callApi('PUT', "/{$this->s['id']}/medical", ['lock_version' => 1, 'is_oncology' => false])->assertOk()->json('data');
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version']]))->assertOk()->json('data');
    }

    protected function callApi(string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/dossiers'.$path, $data + ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid()], ['Authorization' => 'Bearer '.$this->token]);
    }

    protected function path(string $suffix = ''): string
    {
        return "/{$this->s['id']}/visits/{$this->s['visit']['id']}".$suffix;
    }

    protected function visit(array $extra = []): array
    {
        return $extra + ['visit_date' => '2001-03-02', 'visit_type_id' => $this->f['visit_type'], 'is_referred' => false, 'diagnoses' => [['diagnosis_id' => $this->f['diagnosis'], 'diagnosed_on' => null, 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0]]]];
    }

    protected function context(): array
    {
        return ['clinic_id' => $this->f['clinics'][0], 'doctor_id' => $this->f['workflow_doctors'][0]];
    }

    protected function outcome(array $extra = []): array
    {
        return $extra + ['code' => 'DOS-NORX', 'outcome_on' => '2001-03-02'] + $this->context();
    }

    protected function rx(): array
    {
        return ['prescribing_clinic_id' => $this->f['clinics'][0], 'prescribing_staff_id' => $this->f['workflow_doctors'][0], 'prescribed_on' => '2001-03-02', 'items' => [['medication_id' => $this->f['medication'], 'display_order' => 0, 'note' => 'تعليمات الوصفة'], ['medication_id' => $this->f['medication'], 'display_order' => 1, 'note' => 'بند ثانٍ']]];
    }

    protected function saveSection(string $section, array $data)
    {
        return $this->callApi('PUT', $this->path('/'.$section), $data + ['lock_version' => $this->s['visit']['lock_version']]);
    }
}
