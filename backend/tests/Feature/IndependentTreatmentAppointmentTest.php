<?php

namespace Tests\Feature;

use App\Services\Dossiers\DossierAccess;
use App\Services\Dossiers\OncologyReports;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionCase;

class IndependentTreatmentAppointmentTest extends DossierCompletionCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(OncologyPermissionsSeeder::class);
        foreach (DB::table('permissions')->whereIn('code', array_keys(OncologyPermissionsSeeder::CODES))->pluck('id') as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        }
    }

    public function test_next_appointment_needs_no_plan_and_is_not_an_administration(): void
    {
        $path = '/'.$this->s['id'].'/treatment-sessions';
        $counts = [];
        foreach (['visits', 'oncology_plans', 'dose_sessions', 'visit_medications'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $data = ['planned_on' => '2001-03-05', 'request_id' => (string) Str::uuid(), 'note' => 'موعد فقط، الخطة لم تتحدد بعد'] + $this->context();
        $id = $this->callApi('POST', $path, $data)->assertCreated()->json('data.id');
        $this->callApi('POST', $path, $data)->assertCreated()->assertJsonPath('data.id', $id);
        $this->callApi('POST', $path, array_replace($data, ['planned_on' => '2001-03-06']))->assertConflict();
        $this->assertDatabaseHas('oncology_sessions', ['id' => $id, 'plan_id' => null, 'revision_id' => null, 'planned_on' => '2001-03-05']);
        $this->callApi('GET', $path)->assertOk()->assertJsonFragment(['id' => $id, 'plan_id' => null]);
        $facility = app(DossierAccess::class)->facility($this->f['user'], $this->f['facility'], 'export');
        $report = app(OncologyReports::class)->sections($facility, $this->s['id'], [$this->s['visit']['id']], false);
        $this->assertStringContainsString('موعد مستقل #'.$id, json_encode($report, JSON_UNESCAPED_UNICODE));
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
        $this->callApi('PUT', "$path/$id", ['lock_version' => 1, 'status' => 'scheduled', 'planned_on' => '2001-03-06', 'reason' => 'تصحيح الموعد'])->assertOk();
        $this->callApi('PUT', "$path/$id", ['lock_version' => 1, 'status' => 'cancelled', 'reason' => 'نسخة قديمة'])->assertConflict();
        $this->callApi('PUT', "$path/$id", ['lock_version' => 2, 'status' => 'cancelled', 'reason' => 'إلغاء صريح'])->assertOk();
        $this->assertDatabaseHas('oncology_sessions', ['id' => $id, 'status' => 'cancelled', 'lock_version' => 3]);
    }

    public function test_independent_appointment_keeps_scope_permissions_clinic_and_date_validation(): void
    {
        $path = '/'.$this->s['id'].'/treatment-sessions';
        $data = ['planned_on' => '2030-01-02'] + $this->context();
        $this->callApi('POST', $path, array_replace($data, ['planned_on' => 'not-a-date']))->assertUnprocessable();
        $this->callApi('POST', $path, array_replace($data, ['doctor_id' => $this->f['workflow_doctors'][1]]))->assertUnprocessable();
        $this->callApi('POST', $path, $data + ['facility_id' => $this->f['other']])->assertForbidden();
        $id = $this->callApi('POST', $path, $data)->assertCreated()->json('data.id');
        $this->callApi('GET', $path.'/'.$id)->assertOk()->assertJsonPath('data.plan_id', null);
        $this->callApi('GET', '/'.$this->s['id'].'/treatment-plans')->assertOk()->assertJsonPath('next_dose.id', $id);
        $this->callApi('PUT', $path.'/'.$id, ['lock_version' => 1, 'status' => 'scheduled', 'reason' => 'date required'])->assertUnprocessable();
        $this->callApi('PUT', $path.'/'.$id, ['lock_version' => 1, 'status' => 'scheduled', 'planned_on' => '2030-01-03', 'reason' => 'no fabricated revision', 'carry_forward' => true])->assertUnprocessable();
        DB::table('role_permissions')->where('role_id', $this->f['dossier_role'])->where('permission_id', DB::table('permissions')->where('code', 'dossiers.treatment.schedule')->value('id'))->delete();
        $this->callApi('POST', $path, $data)->assertForbidden();
        $this->assertDatabaseHas('oncology_sessions', ['id' => $id, 'planned_on' => '2030-01-02', 'lock_version' => 1]);
    }

    public function test_nullable_plan_does_not_bypass_composite_scope_or_invent_a_plan_on_rollback(): void
    {
        $id = $this->callApi('POST', '/'.$this->s['id'].'/treatment-sessions', ['planned_on' => '2001-03-05'] + $this->context())->assertCreated()->json('data.id');
        foreach ([['facility_id' => $this->f['other']], ['session_number' => 1]] as $invalid) {
            try {
                DB::table('oncology_sessions')->where('id', $id)->update($invalid);
                $this->fail('Database accepted an inconsistent appointment.');
            } catch (QueryException $e) {
                $this->assertContains((int) $e->errorInfo[1], [1452, 4025]);
            }
        }
        $before = DB::table('oncology_sessions')->where('id', $id)->first();
        $migration = require database_path('migrations/2026_09_22_000002_allow_independent_treatment_appointments.php');
        try {
            $migration->down();
            $this->fail('Rollback must refuse before DDL or data loss.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Rollback refused', $e->getMessage());
        }
        $this->assertEquals($before, DB::table('oncology_sessions')->where('id', $id)->first());
    }
}
