<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionCase;

class ClinicalAdministrationTest extends DossierCompletionCase
{
    public function test_options_and_writes_reject_inactive_archived_foreign_and_expired_contexts(): void
    {
        $clinic = $this->f['clinics'][0];
        $row = ['catalog_id' => $this->f['service']] + $this->context();
        foreach ([['is_active' => false], ['is_active' => true, 'archived_at' => now()]] as $fields) {
            DB::table('clinics')->where('id', $clinic)->update($fields);
            $this->callApi('GET', '/options/doctors', ['clinic_id' => $clinic, 'visit_date' => '2001-03-02'])->assertStatus(isset($fields['archived_at']) ? 404 : 200);
            $this->saveSection('clinical', ['services' => [$row], 'procedures' => []])->assertUnprocessable();
        }
        DB::table('clinics')->where('id', $clinic)->update(['is_active' => true, 'archived_at' => null]);
        $foreign = DB::table('facilities')->insertGetId(['code' => 'FOREIGN-'.$this->f['tag'], 'name_ar' => 'أخرى', 'timezone' => 'Asia/Damascus']);
        $foreignClinic = DB::table('clinics')->insertGetId(['facility_id' => $foreign, 'code' => 'OTHER', 'name_ar' => 'عيادة أخرى']);
        $this->saveSection('clinical', ['services' => [array_replace($row, ['clinic_id' => $foreignClinic])], 'procedures' => []])->assertUnprocessable();
        $this->callApi('GET', '/options/doctors', ['clinic_id' => $foreignClinic])->assertNotFound();
        $this->callApi('GET', '/options/doctors', ['clinic_id' => $clinic, 'visit_date' => '2001-03-02'])->assertOk()->assertJsonFragment(['id' => $this->f['workflow_doctors'][0]]);
        $this->saveSection('clinical', ['services' => [$row], 'procedures' => []])->assertOk();
    }

    public function test_procedure_locations_enforced_for_direct_requests_and_manual_names(): void
    {
        $procedure = $this->f['procedure'];
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => 'radiology', 'guidance_method' => 'ct']);
        foreach ([['doctor_id' => $this->f['workflow_doctors'][0]], ['doctor_id' => null, 'manual_doctor_name' => 'طبيب خارجي']] as $doctor) {
            $row = $doctor + ['catalog_id' => $procedure, 'clinic_id' => $this->f['clinics'][0]];
            $this->saveSection('clinical', ['services' => [], 'procedures' => [$row]])->assertUnprocessable()->assertJsonValidationErrors('procedures.0.clinic_id');
        }
        DB::table('clinics')->where('id', $this->f['clinics'][0])->update(['care_setting' => 'radiology']);
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $procedure] + $this->context()]])->assertOk()->json('data');
        $this->assertDatabaseHas('visit_procedures', ['visit_id' => $this->s['visit']['id'], 'execution_location_snapshot' => 'radiology', 'guidance_method_snapshot' => 'ct']);
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => 'surgical_clinic', 'guidance_method' => null]);
        $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $procedure] + $this->context()]])->assertUnprocessable();
        // Directory changes do not rewrite an already recorded procedure's classification.
        $saved = $this->s['clinical']['procedures'][0];
        $this->saveSection('clinical', ['services' => [], 'procedures' => [$saved]])->assertOk();
        $this->assertDatabaseHas('visit_procedures', ['id' => $saved['id'], 'guidance_method_snapshot' => 'ct']);
    }

    public function test_manual_name_cannot_be_combined_with_a_directory_doctor(): void
    {
        $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service'], 'manual_doctor_name' => 'اسم يدوي'] + $this->context()], 'procedures' => []])->assertUnprocessable()->assertJsonValidationErrors('services.0.doctor_id');
    }

    public function test_classification_cannot_bypass_the_dated_clinic_assignment(): void
    {
        DB::table('clinics')->where('id', $this->f['clinics'][0])->update(['care_setting' => 'outpatient']);
        DB::table('staff')->where('id', $this->f['workflow_doctors'][0])->update(['practice_group' => 'resident']);
        DB::table('clinic_staff')->where('clinic_id', $this->f['clinics'][0])->update(['ends_on' => '2000-01-01']);
        $this->callApi('GET', '/options/doctors', ['clinic_id' => $this->f['clinics'][0], 'visit_date' => '2001-03-02'])->assertOk()->assertJsonCount(0, 'data');
        $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service']] + $this->context()], 'procedures' => []])->assertUnprocessable();
    }

    public function test_manual_doctor_survives_reopening_date_correction_and_completion(): void
    {
        $row = ['catalog_id' => $this->f['procedure'], 'clinic_id' => $this->f['clinics'][0], 'doctor_id' => null, 'manual_doctor_name' => 'طبيب خارجي مسجل'];
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [$row]])->assertOk()->assertJsonPath('data.clinical.procedures.0.manual_doctor_name', $row['manual_doctor_name'])->json('data');
        $this->callApi('GET', "/{$this->s['id']}/progress")->assertOk()->assertJsonPath('data.clinical.procedures.0.manual_doctor_name', $row['manual_doctor_name']);
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-02-01', 'diagnoses' => []]))->assertOk()->json('data');
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome()])->assertOk()->json('data');
        $this->callApi('POST', $this->path('/complete'), ['lock_version' => $this->s['visit']['lock_version'], 'dossier_lock_version' => $this->s['lock_version'], 'confirmed' => true, 'clinic_id' => $this->f['clinics'][0], 'attending_staff_id' => $this->f['workflow_doctors'][0]])->assertOk();
        $this->assertDatabaseHas('visit_procedures', ['visit_id' => $this->s['visit']['id'], 'manual_doctor_name' => $row['manual_doctor_name'], 'performed_on' => '2001-02-01']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'visit_procedures', 'entity_id' => $this->s['clinical']['procedures'][0]['id']]);
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->f['user']);
        $facility = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility']);
        $report = app(DossierReports::class)->document($request, $facility, [], $this->s['id'], $this->s['visit']['id']);
        $this->assertStringContainsString($row['manual_doctor_name'], json_encode($report, JSON_UNESCAPED_UNICODE));
    }
}
