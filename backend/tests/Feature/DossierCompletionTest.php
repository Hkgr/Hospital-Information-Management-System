<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionCase;

class DossierCompletionTest extends DossierCompletionCase
{
    public function test_multiple_occurrences_period_independence_replay_omission_void_and_atomic_date_correction(): void
    {
        $periods = DB::table('reporting_periods')->orderBy('id')->get()->toJson();
        $row = ['catalog_id' => $this->f['service'], 'note' => 'ملاحظة'] + $this->context();
        $input = ['request_id' => (string) Str::uuid(), 'lock_version' => $this->s['visit']['lock_version'], 'services' => [$row, $row], 'procedures' => [['catalog_id' => $this->f['procedure']] + $this->context()]];
        $this->s = $this->saveSection('clinical', $input)->assertOk()->assertJsonCount(2, 'data.clinical.services')->json('data');
        $this->saveSection('clinical', $input)->assertOk();
        $this->saveSection('clinical', array_replace($input, ['services' => []]))->assertConflict();
        $this->assertDatabaseHas('visit_services', ['visit_id' => $this->s['visit']['id'], 'reporting_period_id' => null]);
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => []])->assertOk()->assertJsonCount(2, 'data.clinical.services')->json('data');
        $event = $this->s['clinical']['services'][0];
        $this->saveSection('clinical', ['services' => [$event + ['remove' => true]], 'procedures' => []])->assertUnprocessable();
        $this->s = $this->saveSection('clinical', ['services' => [$event + ['remove' => true, 'void_reason' => 'تصحيح إدخال مكرر']], 'procedures' => []])->assertOk()->assertJsonCount(1, 'data.clinical.services')->json('data');
        $this->s = $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2001-02-01', 'diagnoses' => []]))->assertOk()->json('data');
        $this->assertDatabaseHas('visit_services', ['id' => $this->s['clinical']['services'][0]['id'], 'performed_on' => '2001-02-01']);
        $this->assertDatabaseHas('visit_procedures', ['id' => $this->s['clinical']['procedures'][0]['id'], 'performed_on' => '2001-02-01']);
        DB::table('clinic_staff')->where('clinic_id', $this->f['clinics'][0])->update(['ends_on' => '2002-01-01']);
        $before = DB::table('visits')->where('id', $this->s['visit']['id'])->first();
        $this->callApi('PUT', $this->path(), $this->visit(['lock_version' => $this->s['visit']['lock_version'], 'visit_date' => '2003-01-01', 'diagnoses' => []]))->assertUnprocessable();
        $this->assertEquals($before, DB::table('visits')->where('id', $before->id)->first());
        $this->assertSame($periods, DB::table('reporting_periods')->orderBy('id')->get()->toJson());
    }

    public function test_one_prescription_multiple_items_snapshot_preserved_and_no_dispensing(): void
    {
        $dispensing = DB::table('visit_medications')->orderBy('id')->get()->toJson();
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => $this->outcome()])->assertOk()->assertJsonCount(2, 'data.clinical.prescription.items')->json('data');
        $rx = $this->s['clinical']['prescription'];
        DB::table('medications')->where('id', $this->f['medication'])->update(['name_ar' => 'اسم جديد', 'code' => 'RENAMED', 'is_active' => false]);
        $rx['items'][0]['note'] = 'ملاحظة مصححة';
        $rx['items'] = [$rx['items'][0]];
        $this->s = $this->saveSection('medications', ['prescription' => $rx, 'outcome' => null])->assertOk()->assertJsonCount(2, 'data.clinical.prescription.items')->json('data');
        $this->assertStringStartsWith('دواء اختبار', $this->s['clinical']['prescription']['items'][0]['name_ar']);
        $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => null])->assertConflict();
        $this->assertSame(1, DB::table('visit_prescriptions')->where('visit_id', $this->s['visit']['id'])->count());
        $this->assertSame($dispensing, DB::table('visit_medications')->orderBy('id')->get()->toJson());
    }

    public function test_outgoing_referral_is_separate_conditional_and_cleared_with_audit(): void
    {
        $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome(['code' => 'DOS-REFER'])])->assertUnprocessable()->assertJsonValidationErrors('outcome.referral_target');
        $out = $this->outcome(['code' => 'DOS-REFER', 'referral_target' => 'مشفى الوجهة', 'outgoing_referral_date' => '2001-03-01', 'outgoing_referral_reason' => 'إحالة خارجية']);
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $out])->assertOk()->json('data');
        $old = $this->s['clinical']['outcome'];
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome(['id' => $old['id'], 'lock_version' => $old['lock_version']])])->assertOk()->assertJsonPath('data.clinical.outcome.referral_target', null)->json('data');
        $this->assertFalse($this->s['visit']['is_referred']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'visit_outcomes', 'entity_id' => $old['id']]);
        $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome(['outcome_on' => '2099-01-01'])])->assertConflict();
        $current = $this->s['clinical']['outcome'];
        $current['outcome_on'] = '2099-01-01';
        $this->saveSection('medications', ['prescription' => null, 'outcome' => $current])->assertUnprocessable();
    }

    public function test_global_medication_creation_is_explicit_normalized_and_not_granted_by_facility_role(): void
    {
        $data = ['code' => '  NEW   CODE  ', 'name_ar' => '  دواء   جديد  ', 'request_id' => (string) Str::uuid()];
        $id = $this->callApi('POST', '/medications', $data)->assertCreated()->assertJsonPath('data.name_ar', 'دواء جديد')->json('data.id');
        $this->callApi('POST', '/medications', $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->callApi('POST', '/medications', ['code' => 'new code', 'name_ar' => 'اسم مختلف'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->callApi('POST', '/medications', ['code' => 'OTHER', 'name_ar' => 'دواء    جديد'])->assertUnprocessable()->assertJsonValidationErrors('name_ar');
        DB::table('global_user_roles')->where('user_id', $this->f['user']->id)->delete();
        $this->callApi('POST', '/medications', ['code' => 'NO', 'name_ar' => 'غير مسموح'])->assertForbidden();
        $this->assertSame(0, DB::table('visit_prescriptions')->where('visit_id', $this->s['visit']['id'])->count());
    }

    public function test_finalization_explicit_atomic_and_subsequent_visit_independent(): void
    {
        $this->callApi('POST', $this->path('/complete'), ['lock_version' => $this->s['visit']['lock_version'], 'dossier_lock_version' => $this->s['lock_version'], 'confirmed' => true, 'clinic_id' => $this->f['clinics'][0], 'attending_staff_id' => $this->f['workflow_doctors'][0]])->assertUnprocessable();
        $this->s = $this->saveSection('clinical', ['services' => [], 'procedures' => []])->assertOk()->json('data');
        $this->callApi('POST', $this->path('/complete'), ['lock_version' => $this->s['visit']['lock_version'], 'dossier_lock_version' => $this->s['lock_version'], 'confirmed' => true, 'clinic_id' => $this->f['clinics'][0], 'attending_staff_id' => $this->f['workflow_doctors'][0]])->assertUnprocessable();
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome()])->assertOk()->json('data');
        $this->s = $this->callApi('POST', $this->path('/complete'), ['lock_version' => $this->s['visit']['lock_version'], 'dossier_lock_version' => $this->s['lock_version'], 'confirmed' => true, 'clinic_id' => $this->f['clinics'][0], 'attending_staff_id' => $this->f['workflow_doctors'][0]])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.visit.status', 'complete')->json('data');
        $initial = $this->s['visit']['id'];
        $this->saveSection('clinical', ['services' => [], 'procedures' => []])->assertConflict();
        $this->callApi('GET', "/{$this->s['id']}/visits/new")->assertOk()->assertJsonPath('data.visit', null);
        $input = $this->visit(['request_id' => (string) Str::uuid(), 'visit_date' => '2002-01-01']);
        $next = $this->callApi('POST', "/{$this->s['id']}/visits/subsequent", $input)->assertCreated()->assertJsonPath('data.visit.dossier_visit_kind', 'subsequent')->json('data');
        $this->callApi('POST', "/{$this->s['id']}/visits/subsequent", $input)->assertCreated()->assertJsonPath('data.visit.id', $next['visit']['id']);
        $this->callApi('GET', "/{$this->s['id']}/progress")->assertJsonPath('data.visit.id', $initial);
        $this->callApi('GET', "/{$this->s['id']}")->assertJsonPath('data.latest_visit.id', $next['visit']['id'])->assertJsonPath('data.visit_count', 2);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'patient_dossier', 'entity_id' => $this->s['id'], 'event' => 'activated']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'dossier_visit', 'entity_id' => $initial, 'event' => 'completed']);
    }

    public function test_prescription_kinds_are_independent_and_outcome_does_not_replace_them(): void
    {
        $funding = DB::table('funding_sources')->insertGetId(['code' => 'RXF-'.$this->f['tag'], 'name_ar' => 'تمويل وصفة اختبار', 'is_active' => true]);
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx() + ['kind' => 'unlinked'], 'outcome' => null])->assertOk()->json('data');
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx() + ['kind' => 'dose_linked', 'funding_source_id' => $funding], 'outcome' => null])->assertOk()->json('data');
        $this->saveSection('medications', ['prescription' => $this->rx() + ['kind' => 'outside'], 'outcome' => null])->assertUnprocessable()->assertJsonValidationErrors('prescription.unavailable_reason');
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx() + ['kind' => 'outside', 'unavailable_reason' => 'غير متوفر في مخزون المشفى'], 'outcome' => null])->assertOk()->assertJsonCount(3, 'data.clinical.prescriptions')->json('data');
        $this->assertSame('unlinked', $this->s['clinical']['prescription']['kind']);
        $this->assertSame($funding, $this->s['clinical']['prescriptions'][1]['funding_source_id']);
        $this->assertSame('غير متوفر في مخزون المشفى', $this->s['clinical']['prescriptions'][2]['unavailable_reason']);
        $this->saveSection('medications', ['prescription' => $this->rx() + ['kind' => 'dose_linked', 'funding_source_id' => $funding], 'outcome' => null])->assertConflict();
        $this->s = $this->saveSection('medications', ['prescription' => null, 'outcome' => $this->outcome()])->assertOk()->json('data');
        $this->assertCount(3, $this->s['clinical']['prescriptions']);
        $this->assertSame('DOS-NORX', $this->s['clinical']['outcome']['code']);
        $this->assertSame(0, DB::table('visit_medications')->where('visit_id', $this->s['visit']['id'])->count());
    }
}
