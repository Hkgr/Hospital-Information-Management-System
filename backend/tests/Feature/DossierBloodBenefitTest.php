<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BloodBank\BloodBankAccess;
use App\Services\BloodBank\BloodEventQueries;
use App\Services\BloodBank\BloodEventWriter;
use App\Services\Users\DataEntryCardPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DossierCompletionCase;

class DossierBloodBenefitTest extends DossierCompletionCase
{
    public function test_data_entry_default_permissions_allow_only_the_card_benefit_workflow(): void
    {
        app(DataEntryCardPermissions::class)->define();
        $role = DB::table('roles')->insertGetId(['code' => 'BLOOD-ENTRY-'.$this->f['tag'], 'name_ar' => 'اختبار مدخل البيانات']);
        foreach (DataEntryCardPermissions::GRANTS as $code) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $clerk = User::factory()->create();
        DB::table('facility_user_roles')->insert(['role_id' => $role, 'facility_id' => $this->f['facility'], 'user_id' => $clerk->id]);
        $this->token = $clerk->createToken('blood-entry', ['api'])->plainTextToken;
        $this->saveSection('clinical', ['services' => [$this->row($this->benefit())], 'procedures' => []])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/blood-bank/options?facility_id='.$this->f['facility'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->postJson('/api/blood-bank/events', ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid()] + $this->benefit() + ['person_id' => 1])->assertForbidden();
    }

    private function service(): int
    {
        return DB::table('services')->where('code', 'SER-4-001')->value('id')
            ?? DB::table('services')->insertGetId(['category_id' => DB::table('services')->where('id', $this->f['service'])->value('category_id'), 'code' => 'SER-4-001', 'name_ar' => 'نقل دم', 'is_active' => true]);
    }

    public function test_new_transfusion_service_requires_a_benefit_and_rolls_back_every_row(): void
    {
        $before = DB::table('blood_bank_events')->count();
        $this->saveSection('clinical', ['services' => [
            ['catalog_id' => $this->f['service']] + $this->context(),
            ['catalog_id' => $this->service()] + $this->context(),
        ], 'procedures' => []])->assertUnprocessable()->assertJsonValidationErrors('services.1.blood_benefit');
        $this->assertDatabaseMissing('visit_services', ['visit_id' => $this->s['visit']['id']]);
        $this->assertSame($before, DB::table('blood_bank_events')->count());
    }

    private function benefit(array $extra = []): array
    {
        return $extra + ['kind' => 'benefit', 'benefit_kind' => 'transfusion', 'benefit_link_mode' => 'independent', 'occurred_on' => '2001-03-02', 'quantity' => '0.4500', 'quantity_unit' => 'kg', 'blood_group' => 'A', 'rh' => 'positive', 'hemoglobin_g_dl' => '9.1', 'crossmatch_result' => 'compatible', 'blood_component_id' => DB::table('blood_components')->where('is_active', true)->value('id'), 'clinic_id' => $this->f['clinics'][0], 'responsible_staff_id' => $this->f['workflow_doctors'][0], 'screenings' => [['analyte' => 'HCV', 'status' => 'pending']]];
    }

    private function row(array $benefit): array
    {
        return ['catalog_id' => $this->service(), 'blood_benefit' => $benefit] + $this->context();
    }

    private function standaloneBenefit(int $person, array $benefit): array
    {
        foreach (['blood_bank.view', 'blood_bank.issue.create', 'blood_bank.transfusion.create'] as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $request = Request::create('/', 'POST');
        $request->setUserResolver(fn () => $this->f['user']);
        $facility = app(BloodBankAccess::class)->facility($this->f['user'], $this->f['facility']);
        $id = app(BloodEventWriter::class)->save($request, $facility, $benefit + ['person_id' => $person, 'request_id' => (string) Str::uuid()]);

        return app(BloodEventQueries::class)->event($id, $facility['id']);
    }

    private function allowDelete(): void
    {
        DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', 'dossiers.delete')->value('id')]);
    }

    public static function purgeBenefits(): array
    {
        return ['independent transfusion' => ['independent'], 'transfusion after prior issue' => ['linked'], 'issue only' => ['issue']];
    }

    #[DataProvider('purgeBenefits')]
    public function test_card_purge_removes_only_owned_blood_facts_and_dependants(string $kind): void
    {
        $person = DB::table('blood_bank_people')->insertGetId(['facility_id' => $this->f['facility'], 'patient_id' => $this->s['patient']['id'], 'code' => 'BP-'.Str::uuid(), 'entered_by' => $this->f['user']->id]);
        $issue = $this->benefit(['benefit_kind' => 'issue']);
        unset($issue['benefit_link_mode'], $issue['hemoglobin_g_dl'], $issue['crossmatch_result']);
        $prior = $this->standaloneBenefit($person, $issue);
        $outside = $this->standaloneBenefit($person, $this->benefit());
        $data = $kind === 'issue' ? $issue : $this->benefit($kind === 'linked' ? ['benefit_link_mode' => 'linked', 'issue_event_id' => $prior['id']] : []);
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($data)], 'procedures' => []])->assertOk()->json('data');
        $owned = $this->s['clinical']['services'][0]['blood_benefit'];
        if ($owned['blood_transfusion_id']) {
            DB::table('blood_recipient_procedures')->insert(['blood_transfusion_id' => $owned['blood_transfusion_id'], 'procedure_id' => $this->f['procedure'], 'performed_on' => '2001-03-02', 'quantity' => 1, 'entered_by' => $this->f['user']->id]);
        }
        $otherDossier = $this->f['dossiers'][1];
        $this->allowDelete();
        $this->callApi('DELETE', '/'.$this->s['id'])->assertNoContent();
        $this->assertDatabaseMissing('patient_dossiers', ['id' => $this->s['id']]);
        $this->assertDatabaseMissing('blood_bank_events', ['id' => $owned['id']]);
        $this->assertDatabaseMissing('blood_bank_event_codes', ['event_id' => $owned['id']]);
        $this->assertDatabaseMissing('blood_bank_event_screenings', ['event_id' => $owned['id']]);
        if ($owned['blood_transfusion_id']) {
            $this->assertDatabaseMissing('blood_transfusions', ['id' => $owned['blood_transfusion_id']]);
            $this->assertDatabaseMissing('blood_recipient_procedures', ['blood_transfusion_id' => $owned['blood_transfusion_id']]);
        }
        foreach ([$prior, $outside] as $retained) {
            $this->assertEquals($retained, app(BloodEventQueries::class)->event($retained['id'], $this->f['facility']));
            $this->assertDatabaseHas('audit_logs', ['entity_type' => 'blood_bank_event', 'entity_id' => $retained['id']]);
        }
        $this->assertDatabaseHas('patients', ['id' => $this->s['patient']['id']]);
        $this->assertDatabaseHas('patient_dossiers', ['id' => $otherDossier]);
    }

    public function test_card_purge_refuses_an_external_transfusion_of_its_issue_before_any_deletion(): void
    {
        $issue = $this->benefit(['benefit_kind' => 'issue']);
        unset($issue['benefit_link_mode'], $issue['hemoglobin_g_dl'], $issue['crossmatch_result']);
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($issue)], 'procedures' => []])->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $owned = $row['blood_benefit'];
        $outside = $this->standaloneBenefit($owned['person_id'], $this->benefit(['benefit_link_mode' => 'linked', 'issue_event_id' => $owned['id']]));
        $before = DB::table('audit_logs')->count();
        $this->allowDelete();
        $this->callApi('DELETE', '/'.$this->s['id'])->assertConflict()->assertJsonPath('error.code', 'DOSSIER_VERSION_CONFLICT');
        $this->assertDatabaseHas('patient_dossiers', ['id' => $this->s['id'], 'registration_visit_id' => $this->s['visit']['id']]);
        $this->assertDatabaseHas('visits', ['id' => $this->s['visit']['id']]);
        $this->assertDatabaseHas('visit_services', ['id' => $row['id'], 'blood_bank_event_id' => $owned['id']]);
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->assertEquals($outside, app(BloodEventQueries::class)->event($outside['id'], $this->f['facility']));
    }

    public function test_card_purge_deletes_both_owned_stages_but_keeps_another_facility_untouched(): void
    {
        $issue = $this->benefit(['benefit_kind' => 'issue']);
        unset($issue['benefit_link_mode'], $issue['hemoglobin_g_dl'], $issue['crossmatch_result']);
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($issue)], 'procedures' => []])->assertOk()->json('data');
        $issued = $this->s['clinical']['services'][0]['blood_benefit'];
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($this->benefit(['benefit_link_mode' => 'linked', 'issue_event_id' => $issued['id']]))], 'procedures' => []])->assertOk()->json('data');
        $events = array_column($this->s['clinical']['services'], 'blood_bank_event_id');
        $outsideId = DB::table('blood_transfusions')->insertGetId(['facility_id' => $this->f['other'], 'patient_id' => $this->s['patient']['id'], 'blood_component_id' => $issued['blood_component_id'], 'units' => '0.3000', 'quantity_unit' => 'kg', 'transfused_on' => '2001-03-02', 'entered_by' => $this->f['user']->id]);
        $outside = (array) DB::table('blood_transfusions')->find($outsideId);
        $this->allowDelete();
        $this->callApi('DELETE', '/'.$this->s['id'])->assertNoContent();
        $this->assertSame(0, DB::table('blood_bank_events')->whereIn('id', $events)->count());
        $this->assertSame($outside, (array) DB::table('blood_transfusions')->find($outsideId));
    }

    public function test_atomic_link_replay_edit_omission_and_separate_legitimate_benefit(): void
    {
        $input = ['request_id' => (string) Str::uuid(), 'lock_version' => $this->s['visit']['lock_version'], 'services' => [$this->row($this->benefit())], 'procedures' => []];
        $this->s = $this->saveSection('clinical', $input)->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $event = $row['blood_benefit'];
        $this->assertNotNull($row['blood_bank_event_id']);
        $this->assertSame($event['id'], $row['blood_bank_event_id']);
        $this->assertDatabaseHas('blood_bank_people', ['id' => $event['person_id'], 'patient_id' => $this->s['patient']['id'], 'facility_id' => $this->f['facility']]);
        $this->assertDatabaseHas('blood_transfusions', ['id' => $event['blood_transfusion_id'], 'visit_id' => $this->s['visit']['id'], 'patient_id' => $this->s['patient']['id'], 'units' => '0.4500', 'quantity_unit' => 'kg']);
        $count = DB::table('blood_bank_events')->count();
        $this->saveSection('clinical', $input)->assertOk();
        $this->assertSame($count, DB::table('blood_bank_events')->count());
        $edited = $this->row($this->benefit(['id' => $event['id'], 'lock_version' => $event['lock_version'], 'quantity' => '0.6500'])) + ['id' => $row['id'], 'lock_version' => $row['lock_version']];
        $this->s = $this->saveSection('clinical', ['services' => [$edited], 'procedures' => []])->assertOk()->json('data');
        $this->assertSame($event['id'], $this->s['clinical']['services'][0]['blood_benefit']['id']);
        $this->assertSame($count, DB::table('blood_bank_events')->count());
        $this->assertDatabaseHas('blood_bank_events', ['id' => $event['id'], 'quantity' => '0.6500']);
        $this->saveSection('clinical', ['services' => [$edited], 'procedures' => []])->assertConflict();
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($this->benefit())], 'procedures' => []])->assertOk()->assertJsonCount(2, 'data.clinical.services')->json('data');
        $this->assertSame($count + 1, DB::table('blood_bank_events')->count());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'blood_bank_event', 'entity_id' => $event['id'], 'actor_id' => $this->f['user']->id]);
    }

    public function test_context_is_patient_bound_and_does_not_create_records_or_grant_blood_bank_access(): void
    {
        $count = DB::table('blood_bank_people')->count();
        $this->callApi('GET', $this->path('/blood-benefit-context'))->assertOk()->assertJsonPath('data.patient.patient_id', $this->s['patient']['id']);
        $this->callApi('GET', $this->path('/blood-benefit-issues'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame($count, DB::table('blood_bank_people')->count());
        $this->withToken($this->token)->getJson('/api/blood-bank/options?facility_id='.$this->f['facility'])->assertForbidden();
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->whereIn('permission_id', DB::table('permissions')->whereIn('code', ['dossiers.services.update', 'dossiers.clinical.update'])->select('id'))->delete();
        $this->callApi('GET', $this->path('/blood-benefit-context'))->assertForbidden();
        $this->saveSection('clinical', ['services' => [$this->row($this->benefit())], 'procedures' => []])->assertForbidden();
    }

    public function test_tampered_identity_bad_medical_fields_and_non_blood_service_roll_back(): void
    {
        $count = DB::table('blood_bank_events')->count();
        foreach ([['patient_id' => $this->f['patients'][1]], ['facility_id' => $this->f['facility']], ['person_id' => 999999], ['id' => 999999], ['quantity' => '0'], ['blood_component_id' => 999999], ['occurred_on' => '2999-01-01'], ['responsible_staff_id' => $this->f['workflow_doctors'][1]]] as $invalid) {
            $this->saveSection('clinical', ['services' => [$this->row($this->benefit($invalid))], 'procedures' => []])->assertUnprocessable();
            $this->assertSame($count, DB::table('blood_bank_events')->count());
        }
        $row = $this->row($this->benefit());
        $row['catalog_id'] = $this->f['service'];
        $this->saveSection('clinical', ['services' => [$row], 'procedures' => []])->assertUnprocessable();
        $this->callApi('GET', $this->path('/blood-benefit-context'), ['facility_id' => $this->f['other_facility'] ?? 999999])->assertForbidden();
        $this->assertDatabaseMissing('visit_services', ['visit_id' => $this->s['visit']['id']]);
    }

    public function test_linked_service_cannot_be_removed_replaced_or_moved_while_legacy_is_unchanged(): void
    {
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($this->benefit())], 'procedures' => []])->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $this->saveSection('clinical', ['services' => [$row + ['remove' => true, 'void_reason' => 'تصحيح']], 'procedures' => []])->assertUnprocessable();
        $this->saveSection('clinical', ['services' => [array_replace($row, ['catalog_id' => $this->f['service']])], 'procedures' => []])->assertUnprocessable();
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2002-01-01']))->assertUnprocessable();
        // A pre-existing unlinked service stays historical; it is never backfilled.
        $id = DB::table('visit_services')->insertGetId(['client_request_id' => (string) Str::uuid(), 'facility_id' => $this->f['facility'], 'visit_id' => $this->s['visit']['id'], 'service_id' => $this->service(), 'performed_on' => '2001-03-02', 'clinic_id' => $this->f['clinics'][0], 'performed_by' => $this->f['workflow_doctors'][0], 'entered_by' => $this->f['user']->id]);
        $count = DB::table('blood_bank_events')->count();
        $this->s = $this->saveSection('clinical', ['services' => [['id' => $id, 'lock_version' => 1, 'catalog_id' => $this->service(), 'note' => 'تاريخي'] + $this->context()], 'procedures' => []])->assertOk()->json('data');
        $this->assertSame($count, DB::table('blood_bank_events')->count());
        $this->assertDatabaseHas('visit_services', ['id' => $id, 'blood_bank_event_id' => null]);
    }

    public function test_issue_and_linked_transfusion_reuse_the_same_patient_and_count_once(): void
    {
        $issue = $this->benefit(['benefit_kind' => 'issue']);
        unset($issue['benefit_link_mode'], $issue['hemoglobin_g_dl'], $issue['crossmatch_result']);
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($issue)], 'procedures' => []])->assertOk()->json('data');
        $issued = $this->s['clinical']['services'][0]['blood_benefit'];
        $this->callApi('GET', $this->path('/blood-benefit-issues'))->assertOk()->assertJsonPath('data.0.id', $issued['id']);
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($this->benefit(['benefit_link_mode' => 'linked', 'issue_event_id' => $issued['id']]))], 'procedures' => []])->assertOk()->json('data');
        $this->assertSame(1, app(BloodEventQueries::class)->totals(app(BloodEventQueries::class)->events($this->f['facility'], ['person_id' => $issued['person_id']]))['benefits']);
        $this->saveSection('clinical', ['services' => [$this->row($this->benefit(['benefit_link_mode' => 'linked', 'issue_event_id' => $issued['id']]))], 'procedures' => []])->assertUnprocessable();
    }

    public function test_migration_rollback_refuses_to_discard_populated_links(): void
    {
        $this->s = $this->saveSection('clinical', ['services' => [$this->row($this->benefit())], 'procedures' => []])->assertOk()->json('data');
        $row = $this->s['clinical']['services'][0];
        $migration = require database_path('migrations/2026_10_21_000001_link_visit_service_blood_benefit.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain existing clinical links.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Linked blood benefits exist', $e->getMessage());
        }
        $this->assertDatabaseHas('visit_services', ['id' => $row['id'], 'blood_bank_event_id' => $row['blood_bank_event_id']]);
        $this->assertDatabaseHas('blood_bank_events', ['id' => $row['blood_bank_event_id']]);
    }
}
