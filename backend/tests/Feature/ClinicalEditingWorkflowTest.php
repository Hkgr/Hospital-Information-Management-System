<?php

namespace Tests\Feature;

use App\Console\Commands\InitializeClinicalEditing;
use App\Models\User;
use App\Services\Users\ProtectedRolePolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DossierCompletionCase;

class ClinicalEditingWorkflowTest extends DossierCompletionCase
{
    private function reader(array $codes): array
    {
        $user = User::factory()->create();
        $role = DB::table('roles')->insertGetId(['code' => 'EDIT-'.Str::random(14), 'name_ar' => 'استعراض طبي']);
        foreach (DB::table('permissions')->whereIn('code', $codes)->where('is_active', true)->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $id]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role, 'facility_id' => $this->f['facility']]);

        return [$user, $role, $user->createToken('editing', ['api'])->plainTextToken];
    }

    private function asToken(string $token, string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/'.$path, $data + ['facility_id' => $this->f['facility'], 'request_id' => (string) Str::uuid()], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_initialization_is_explicit_once_and_granular_revocation_through_role_api_remains_effective(): void
    {
        [$reader, $role, $token] = $this->reader(['dossiers.view']);
        [$unrelated, $basicRole] = $this->reader(['patients.basic.view']);
        $before = DB::table('role_permissions')->where('role_id', $role)->count();
        $this->artisan('patient-cards:initialize-editing')->assertSuccessful();
        $this->assertSame($before, DB::table('role_permissions')->where('role_id', $role)->count());
        $this->artisan('patient-cards:initialize-editing', ['--apply' => true, '--execution-reference' => 'TEST-INITIAL-GRANT'])->assertSuccessful();
        foreach (InitializeClinicalEditing::UPDATES as $code) {
            $this->assertDatabaseHas('role_permissions', ['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $basicRole)->count());
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', 'dossiers.view')->value('id')]);
        $this->asToken($token, 'GET', 'dossiers/options')->assertOk()->assertJsonPath('data.capabilities.diagnoses_update', true)->assertJsonPath('data.capabilities.delete', false)->assertJsonPath('data.capabilities.export', false)->assertJsonPath('data.capabilities.visits_complete', false);
        [$admin, $adminRole, $adminToken] = $this->reader(ProtectedRolePolicy::MINIMUM);
        DB::table('roles')->where('id', $adminRole)->update(['is_system_super_admin' => true]);
        DB::table('global_user_roles')->insert(['role_id' => $adminRole, 'user_id' => $admin->id]);
        $view = $this->asToken($adminToken, 'GET', 'users/roles/'.$role)->assertOk()->json('data');
        $ids = collect($view['permissions'])->reject(fn ($p) => $p['code'] === 'dossiers.diagnoses.update')->pluck('id')->all();
        $this->asToken($adminToken, 'PUT', 'users/roles/'.$role, ['name_ar' => $view['name_ar'], 'permission_ids' => $ids, 'lock_version' => $view['lock_version'], 'reason' => 'سحب تعديل التشخيصات'])->assertOk();
        $this->artisan('patient-cards:initialize-editing', ['--apply' => true, '--execution-reference' => 'TEST-REPLAY'])->assertSuccessful();
        $this->asToken($token, 'GET', 'dossiers/options')->assertOk()->assertJsonPath('data.capabilities.diagnoses_update', false);
        $this->asToken($token, 'PUT', 'dossiers/'.$this->s['id'].'/visits/'.$this->s['visit']['id'], ['visit_date' => $this->s['visit']['visit_date'], 'is_referred' => false, 'lock_version' => $this->s['visit']['lock_version'], 'diagnoses' => [['diagnosis_id' => $this->f['diagnosis'], 'clinic_id' => $this->f['clinics'][0], 'diagnosing_staff_id' => $this->f['workflow_doctors'][0]]]])->assertForbidden();
        $this->asToken($token, 'GET', 'dossiers', ['facility_id' => PHP_INT_MAX])->assertForbidden();
        $this->asToken($unrelated->createToken('unrelated', ['api'])->plainTextToken, 'GET', 'dossiers')->assertForbidden();
    }

    public function test_old_other_author_entries_keep_ids_during_medical_diagnosis_service_procedure_prescription_and_outcome_edits(): void
    {
        $this->s = $this->saveSection('clinical', ['services' => [['catalog_id' => $this->f['service'], 'note' => 'قديم'] + $this->context()], 'procedures' => [['catalog_id' => $this->f['procedure'], 'note' => 'قديم'] + $this->context()]])->assertOk()->json('data');
        $this->s = $this->saveSection('medications', ['prescription' => $this->rx(), 'outcome' => $this->outcome()])->assertOk()->json('data');
        [$reader, , $token] = $this->reader(['dossiers.medical.view', 'dossiers.visits.view']);
        $this->artisan('patient-cards:initialize-editing', ['--apply' => true, '--execution-reference' => 'TEST-OLDER-RECORDS'])->assertSuccessful();
        $base = 'dossiers/'.$this->s['id'];
        $visit = $this->s['visit']['id'];
        $medical = $this->asToken($token, 'PUT', $base.'/medical', ['lock_version' => $this->s['lock_version'], 'is_oncology' => false, 'clinical_history' => 'تصحيح بواسطة مستخدم آخر'])->assertOk()->json('data');
        $diagnoses = array_map(fn ($row) => Arr::only($row, ['id', 'lock_version', 'diagnosis_id', 'diagnosed_on', 'clinic_id', 'diagnosing_staff_id']), $medical['visit']['diagnoses']);
        $diagnoses[0]['diagnosed_on'] = '2001-02-01';
        $snapshot = $this->asToken($token, 'PUT', $base.'/visits/'.$visit, ['lock_version' => $medical['visit']['lock_version'], 'visit_date' => '2001-03-02', 'is_referred' => false, 'diagnoses' => $diagnoses])->assertOk()->json('data');
        $this->assertSame($medical['visit']['diagnoses'][0]['id'], $snapshot['visit']['diagnoses'][0]['id']);
        $services = $snapshot['clinical']['services'];
        $procedures = $snapshot['clinical']['procedures'];
        $services[0]['catalog_id'] = $services[0]['catalog_id'] ?? $services[0]['service_id'] ?? $this->f['service'];
        $procedures[0]['catalog_id'] = $procedures[0]['catalog_id'] ?? $procedures[0]['procedure_id'] ?? $this->f['procedure'];
        $services[0]['note'] = 'تصحيح خدمة قديمة';
        $procedures[0]['note'] = 'تصحيح إجراء قديم';
        DB::table('clinic_staff')->where('staff_id', $this->f['workflow_doctors'][0])->update(['starts_on' => '2024-01-01']);
        DB::table('staff')->where('id', $this->f['workflow_doctors'][0])->update(['is_active' => false]);
        $snapshot = $this->asToken($token, 'PUT', $base.'/visits/'.$visit.'/clinical', ['lock_version' => $snapshot['visit']['lock_version'], 'services' => $services, 'procedures' => $procedures])->assertOk()->json('data');
        $this->assertSame($services[0]['id'], $snapshot['clinical']['services'][0]['id']);
        $this->assertSame($procedures[0]['id'], $snapshot['clinical']['procedures'][0]['id']);
        $rx = Arr::only($snapshot['clinical']['prescription'], ['id', 'lock_version', 'kind', 'prescribing_clinic_id', 'prescribing_staff_id', 'prescribed_on', 'note', 'items']);
        $outcome = Arr::only($snapshot['clinical']['outcome'], ['id', 'lock_version', 'code', 'clinic_id', 'doctor_id', 'outcome_on', 'note']);
        $rx['note'] = 'تصحيح وصفة';
        $outcome['note'] = 'تصحيح نتيجة';
        $snapshot = $this->asToken($token, 'PUT', $base.'/visits/'.$visit.'/medications', ['lock_version' => $snapshot['visit']['lock_version'], 'prescription' => $rx, 'outcome' => $outcome])->assertOk()->json('data');
        $this->assertSame($rx['id'], $snapshot['clinical']['prescription']['id']);
        $this->assertSame($outcome['id'], $snapshot['clinical']['outcome']['id']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $reader->id, 'entity_type' => 'visit_services', 'entity_id' => $services[0]['id']]);
        $this->asToken($token, 'PUT', $base.'/visits/'.$visit.'/clinical', ['lock_version' => 1, 'services' => $services, 'procedures' => $procedures])->assertConflict();
        DB::table('visits')->where('id', $visit)->update(['status' => 'complete', 'attending_staff_id' => $this->f['workflow_doctors'][0]]);
        $this->asToken($token, 'PUT', $base.'/visits/'.$visit.'/clinical', ['lock_version' => $snapshot['visit']['lock_version'], 'services' => $services, 'procedures' => $procedures])->assertConflict();
    }
}
