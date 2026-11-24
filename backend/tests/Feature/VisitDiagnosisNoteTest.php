<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionCase;

class VisitDiagnosisNoteTest extends DossierCompletionCase
{
    public function test_a_new_diagnosis_note_is_optional_but_validated_and_permission_scoped(): void
    {
        $row = ['diagnosis_id' => $this->f['diagnosis'], 'diagnosed_on' => '2000-01-01', 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0], 'note' => 'ناكس'];
        foreach ([['not text'], str_repeat('ن', 10001)] as $invalid) {
            $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [array_replace($row, ['note' => $invalid])]]))->assertUnprocessable()->assertJsonValidationErrors('diagnoses.0.note');
        }
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [$row]]))->assertOk()->assertJsonPath('data.visit.diagnoses.1.note', 'ناكس')->json('data');
        $saved = $this->s['visit']['diagnoses'][1];
        $this->assertDatabaseHas('visit_diagnoses', ['id' => $saved['id'], 'note' => 'ناكس']);
        $row += ['id' => $saved['id'], 'lock_version' => $saved['lock_version']];
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [array_replace($row, ['note' => 'لن يحفظ'])], 'facility_id' => $this->f['other']]))->assertForbidden();
        $this->token = $this->f['viewer']->createToken('diagnosis-note-denied', ['api'])->plainTextToken;
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [array_replace($row, ['note' => 'لن يحفظ'])]]))->assertForbidden();
        $this->assertDatabaseHas('visit_diagnoses', ['id' => $saved['id'], 'note' => 'ناكس']);
    }

    public function test_note_can_be_saved_reloaded_changed_cleared_and_omitted_without_changing_the_directory(): void
    {
        $directory = (array) DB::table('diagnoses')->find($this->f['diagnosis']);
        $original = $this->s['visit']['diagnoses'][0];
        $row = ['id' => $original['id'], 'lock_version' => $original['lock_version'], 'diagnosis_id' => $original['diagnosis_id'], 'diagnosed_on' => null, 'clinic_id' => $original['clinic_id'], 'diagnosing_staff_id' => $original['diagnosing_staff_id'], 'note' => 'ناكس، مع تفاصيل إضافية'];
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [$row]]))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', $row['note'])->json('data');
        $this->callApi('GET', $this->path('/progress'))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', $row['note']);
        $this->callApi('GET', $this->path())->assertOk()->assertJsonPath('data.diagnoses.0.note', $row['note']);
        $row['lock_version'] = $this->s['visit']['diagnoses'][0]['lock_version'];
        $row['note'] = 'نقائلي';
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [$row]]))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', 'نقائلي')->json('data');
        $audit = DB::table('audit_logs')->where('entity_type', 'visit_diagnosis')->where('entity_id', $original['id'])->orderByDesc('id')->first();
        $this->assertSame('ناكس، مع تفاصيل إضافية', json_decode($audit->old_values, true)['note']);
        $this->assertSame('نقائلي', json_decode($audit->new_values, true)['note']);
        $row['lock_version'] = $this->s['visit']['diagnoses'][0]['lock_version'];
        unset($row['note']);
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [$row]]))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', 'نقائلي')->json('data');
        $row['lock_version'] = $this->s['visit']['diagnoses'][0]['lock_version'];
        $row['note'] = null;
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [$row]]))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', null)->json('data');
        $this->callApi('GET', $this->path('/progress'))->assertOk()->assertJsonPath('data.visit.diagnoses.0.note', null);
        $this->assertDatabaseHas('visit_diagnoses', ['id' => $original['id'], 'note' => null]);
        $this->assertSame($directory, (array) DB::table('diagnoses')->find($this->f['diagnosis']));
    }
}
