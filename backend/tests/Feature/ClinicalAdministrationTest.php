<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\DossierReports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionCase;

class ClinicalAdministrationTest extends DossierCompletionCase
{
    public function test_doctor_correction_preserves_saved_procedure_snapshot_after_directory_changes(): void
    {
        $this->correctDoctorWithSnapshot('radiology', 'ct');
    }

    public function test_doctor_correction_preserves_null_legacy_snapshot_after_directory_changes(): void
    {
        $this->correctDoctorWithSnapshot(null, null);
    }

    public function test_explicit_procedure_and_clinic_corrections_revalidate_and_audit_replacement_snapshots(): void
    {
        $procedure = $this->f['procedure'];
        $clinic = $this->f['clinics'][0];
        $surgical = $this->f['clinics'][1];
        DB::table('clinics')->where('id', $clinic)->update(['care_setting' => 'radiology']);
        DB::table('clinics')->where('id', $surgical)->update(['clinic_kind' => 'surgical']);
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => 'radiology', 'guidance_method' => 'ct']);
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $procedure] + $this->context()]])->assertOk()->json('data');
        $saved = $this->s['clinical']['procedures'][0];
        $moved = array_replace($saved, ['clinic_id' => $surgical, 'doctor_id' => $this->f['workflow_doctors'][1]]);
        $this->saveSection('clinical', ['services' => [], 'procedures' => [$moved]])->assertUnprocessable();
        $this->assertDatabaseHas('visit_procedures', ['id' => $saved['id'], 'clinic_id' => $clinic, 'guidance_method_snapshot' => 'ct']);
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => 'surgical_clinic', 'guidance_method' => null]);
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [$moved]])->assertOk()->json('data');
        $audit = DB::table('audit_logs')->where('entity_type', 'visit_procedures')->where('entity_id', $saved['id'])->orderByDesc('id')->first();
        $this->assertSame('radiology', json_decode($audit->old_values, true)['execution_location_snapshot']);
        $this->assertSame('ct', json_decode($audit->old_values, true)['guidance_method_snapshot']);
        $this->assertSame('surgical_clinic', json_decode($audit->new_values, true)['execution_location_snapshot']);
        $this->assertNull(json_decode($audit->new_values, true)['guidance_method_snapshot']);
        $replacement = DB::table('procedures')->insertGetId(['code' => 'NEW-'.$this->f['tag'], 'name_ar' => 'خزعة بديلة اصطناعية', 'execution_location' => 'radiology', 'guidance_method' => 'ultrasound']);
        $changed = array_replace($this->s['clinical']['procedures'][0], ['catalog_id' => $replacement]);
        $this->saveSection('clinical', ['services' => [], 'procedures' => [$changed]])->assertUnprocessable();
        $changed['clinic_id'] = $clinic;
        $changed['doctor_id'] = $this->f['workflow_doctors'][0];
        $this->saveSection('clinical', ['services' => [], 'procedures' => [$changed]])->assertOk();
        $this->assertDatabaseHas('visit_procedures', ['id' => $saved['id'], 'procedure_id' => $replacement, 'execution_location_snapshot' => 'radiology', 'guidance_method_snapshot' => 'ultrasound']);
    }

    private function correctDoctorWithSnapshot(?string $location, ?string $guidance): void
    {
        $procedure = $this->f['procedure'];
        $clinic = $this->f['clinics'][0];
        DB::table('clinics')->where('id', $clinic)->update(['care_setting' => 'radiology']);
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => $location, 'guidance_method' => $guidance]);
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $procedure] + $this->context()]])->assertOk()->json('data');
        DB::table('procedures')->where('id', $procedure)->update(['execution_location' => 'surgical_clinic', 'guidance_method' => null]);
        $otherDoctor = $this->f['workflow_doctors'][1];
        DB::table('clinic_staff')->insert(['clinic_id' => $clinic, 'staff_id' => $otherDoctor, 'starts_on' => '1990-01-01']);
        foreach ([['doctor_id' => $otherDoctor, 'manual_doctor_name' => null], ['doctor_id' => null, 'manual_doctor_name' => 'تصحيح اسم الطبيب']] as $correction) {
            $row = array_replace($this->s['clinical']['procedures'][0], $correction);
            $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => [$row]])->assertOk()->json('data');
            $saved = $this->s['clinical']['procedures'][0];
            $this->assertSame($location, $saved['execution_location']);
            $this->assertSame($guidance, $saved['guidance_method']);
            $this->assertDatabaseHas('visit_procedures', ['id' => $saved['id'], 'execution_location_snapshot' => $location, 'guidance_method_snapshot' => $guidance]);
        }
        $this->callApi('GET', "/{$this->s['id']}/progress")->assertOk()->assertJsonPath('data.clinical.procedures.0.manual_doctor_name', 'تصحيح اسم الطبيب');
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-02-01', 'diagnoses' => []]))->assertOk()->json('data');
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome()])->assertOk()->json('data');
        $this->callApi('POST', $this->path('/complete'), ['lock_version' => $this->s['visit']['lock_version'], 'dossier_lock_version' => $this->s['lock_version'], 'confirmed' => true, 'clinic_id' => $clinic, 'attending_staff_id' => $otherDoctor])->assertOk();
        $this->assertDatabaseHas('visit_procedures', ['id' => $saved['id'], 'performed_on' => '2001-02-01', 'execution_location_snapshot' => $location, 'guidance_method_snapshot' => $guidance]);
        $audit = DB::table('audit_logs')->where('entity_type', 'visit_procedures')->where('entity_id', $saved['id'])->where('new_values', 'like', '%manual_doctor_name%')->orderByDesc('id')->first();
        $this->assertStringContainsString('تصحيح اسم الطبيب', json_encode(json_decode($audit->new_values), JSON_UNESCAPED_UNICODE));
        $request = Request::create('/');
        $request->setUserResolver(fn () => $this->f['user']);
        $f = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility']);
        $report = app(DossierReports::class)->document($request, $f, [], $this->s['id'], $this->s['visit']['id']);
        $this->assertStringContainsString('تصحيح اسم الطبيب', json_encode($report, JSON_UNESCAPED_UNICODE));
    }

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
