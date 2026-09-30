<?php

namespace Tests\Feature;

use App\Services\Auth\TaskPermissions;
use App\Services\Auth\UserAccessContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionCase;

class TaskPermissionsTest extends DossierCompletionCase
{
    private function only(array $codes): void
    {
        $roles = DB::table('facility_user_roles')->where('user_id', $this->f['user']->id)->pluck('role_id');
        DB::table('role_permissions')->whereIn('role_id', $roles)->delete();
        foreach ($codes as $code) {
            DB::table('role_permissions')->insert(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
    }

    public function test_services_task_does_not_allow_procedures_prescriptions_outcomes_or_export(): void
    {
        $this->only(['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.services.update']);
        $row = ['catalog_id' => $this->f['service']] + $this->context();
        $this->s = $this->saveSection('clinical', ['services' => [$row], 'procedures' => []])->assertOk()->assertJsonCount(1, 'data.clinical.services')->json('data');
        $this->saveSection('clinical', ['services' => [], 'procedures' => [['catalog_id' => $this->f['procedure']] + $this->context()]])->assertForbidden();
        $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => null])->assertForbidden();
        $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome()])->assertForbidden();
        $this->callApi('POST', '/'.$this->s['id'].'/report/xlsx')->assertForbidden();
        $codes = collect(app(UserAccessContext::class)->forUser($this->f['user']))->firstWhere('facility.id', $this->f['facility'])['permissions'];
        $this->assertNotContains('dossiers.clinical.update', $codes);
        $this->assertDatabaseMissing('visit_procedures', ['visit_id' => $this->s['visit']['id']]);
        $this->assertDatabaseMissing('visit_prescriptions', ['visit_id' => $this->s['visit']['id']]);
    }

    public function test_prescription_task_does_not_authorize_an_outcome_in_the_same_payload(): void
    {
        $this->only(['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.prescriptions.update']);
        $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => $this->outcome()])->assertForbidden();
        $this->assertDatabaseMissing('visit_prescriptions', ['visit_id' => $this->s['visit']['id']]);
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => null])->assertOk()->json('data');
        $this->assertDatabaseHas('visit_prescriptions', ['visit_id' => $this->s['visit']['id']]);
        $this->assertDatabaseMissing('visit_medications', ['visit_id' => $this->s['visit']['id']]);
    }

    public function test_visit_draft_correction_preserves_omitted_diagnoses_but_cannot_write_them(): void
    {
        $this->only(['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.visits.draft.update']);
        $before = DB::table('visit_diagnoses')->where('visit_id', $this->s['visit']['id'])->get()->toJson();
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version']]))->assertForbidden();
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => []]))->assertOk();
        $this->assertSame($before, DB::table('visit_diagnoses')->where('visit_id', $this->s['visit']['id'])->get()->toJson());
    }

    public function test_legacy_grants_expand_one_way_without_crossing_scope_or_activating_disabled_permissions(): void
    {
        $tasks = app(TaskPermissions::class);
        $this->assertContains('dossiers.services.update', $tasks->effective(['dossiers.clinical.update']));
        $this->assertNotContains('dossiers.clinical.update', $tasks->effective(['dossiers.services.update']));
        $this->assertNotContains('patients.basic.search', $tasks->effective(['patients.search']));
        $this->assertContains('patients.basic.search', $tasks->effective(['patients.search'], true));
        DB::table('permissions')->where('code', 'dossiers.services.update')->update(['is_active' => false]);
        $this->assertNotContains('dossiers.services.update', $tasks->effective(['dossiers.clinical.update']));
    }

    public function test_populated_upgrade_is_repeatable_and_preserves_assignments_and_disabled_definitions(): void
    {
        $before = [];
        foreach (['patients', 'patient_dossiers', 'visits', 'visit_diagnoses', 'role_permissions', 'facility_user_roles', 'global_user_roles', 'audit_logs'] as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        DB::table('permissions')->where('code', 'dossiers.services.update')->update(['is_active' => false]);
        $migration = require database_path('migrations/2026_09_30_000002_define_task_permissions.php');
        $migration->up();
        $migration->up();
        $migration->down();
        $this->assertDatabaseHas('permissions', ['code' => 'dossiers.services.update', 'is_active' => false]);
        foreach ($before as $table => $snapshot) {
            $this->assertSame($snapshot, DB::table($table)->orderBy('id')->get()->toJson(), $table);
        }
    }

    public function test_registration_tasks_save_one_identity_and_handoff_resumes_the_same_first_visit(): void
    {
        $this->only(['patients.basic.view', 'patients.basic.search', 'patients.basic.create', 'patient_cards.register']);
        $api = function (string $method, string $path, array $input = []) {
            $this->app['auth']->forgetGuards();

            return $this->json($method, '/api/reception/'.$path, $input + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
        };
        $api('GET', 'options')->assertOk()->assertJsonPath('data.can_search', true)->assertJsonPath('data.can_register', true);
        $input = ['request_id' => (string) Str::uuid(), 'person_mode' => 'new', 'first_name' => 'مريض', 'family_name' => 'تسليم', 'birth_date_accuracy' => 'unknown', 'gender' => 'unknown', 'displacement_status' => 'unknown', 'opening_date' => '2001-01-01'];
        $api('POST', 'registrations', $input)->assertUnprocessable()->assertJsonValidationErrors('visit_date');
        $input['visit_date'] = '2001-02-03';
        $card = $api('POST', 'registrations', $input)->assertCreated()->assertJsonPath('data.workflow', null)->json('data');
        $api('POST', 'registrations', $input)->assertCreated()->assertJsonPath('data.id', $card['id']);
        $api('GET', 'patients', ['search' => $card['code']])->assertOk()->assertJsonFragment(['dossier_id' => $card['id']]);
        $this->callApi('GET', '/'.$card['id'])->assertForbidden();
        $this->callApi('GET', '/'.$card['id'].'/progress')->assertForbidden();
        $this->callApi('POST', '/'.$card['id'].'/report/pdf')->assertForbidden();
        $this->callApi('DELETE', '/'.$card['id'])->assertForbidden();
        $this->only(['dossiers.view', 'dossiers.medical.update', 'dossiers.visits.update']);
        $api('GET', 'cards/'.$card['id'])->assertOk()->assertJsonPath('data.workflow.visit.id', $card['registration_visit_id']);
        $snapshot = $this->callApi('GET', '/'.$card['id'].'/progress')->assertOk()->json('data');
        $this->assertSame($card['code'], $snapshot['code']);
        $this->assertSame($card['patient_id'], $snapshot['patient']['id']);
        $this->assertSame($card['registration_visit_id'], $snapshot['visit']['id']);
        $this->callApi('PUT', '/'.$card['id'].'/medical', ['lock_version' => $snapshot['lock_version'], 'is_oncology' => false])->assertOk();
        $this->assertSame(1, DB::table('visits')->where('dossier_id', $card['id'])->count());
        $this->assertDatabaseHas('patient_dossiers', ['id' => $card['id'], 'patient_id' => $card['patient_id']]);
    }

    public function test_diagnosis_task_cannot_change_visit_date_and_medical_read_cannot_open_visit_history(): void
    {
        $this->only(['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.diagnoses.update']);
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-02-01']))->assertForbidden();
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => array_map(fn ($row) => Arr::only((array) $row, ['id', 'lock_version', 'diagnosis_id', 'diagnosed_on', 'clinic_id', 'diagnosing_staff_id']), $this->s['visit']['diagnoses'])]))->assertOk()->json('data');
        $this->only(['dossiers.medical.view']);
        $this->callApi('GET', $this->path())->assertForbidden();
        $this->callApi('GET', '/'.$this->s['id'].'/progress')->assertForbidden();
        $this->callApi('GET', '/'.$this->s['id'])->assertOk()->assertJsonPath('data.latest_visit', null);
    }
}
