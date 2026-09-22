<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierFixture;
use Tests\TestCase;

class DossierPurgeTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierFixture::make();
        $this->token = $this->f['user']->createToken('dossier-purge', ['api'])->plainTextToken;
    }

    private function api(string $method, string $path, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, '/api/dossiers'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_delete_requires_permission_and_capabilities_default_false(): void
    {
        $id = $this->f['dossiers'][0];
        $this->api('GET', '/options')->assertOk()->assertJsonPath('data.capabilities.delete', false);
        $this->api('DELETE', '/'.$id)->assertForbidden()->assertJsonPath('error.code', 'DOSSIER_ACCESS_DENIED');
        $this->assertDatabaseHas('patient_dossiers', ['id' => $id]);
        $this->assertTrue(DB::table('visits')->where('dossier_id', $id)->exists());
    }

    public function test_delete_removes_dossier_visits_and_associations(): void
    {
        $permission = DB::table('permissions')->where('code', 'dossiers.delete')->value('id');
        $this->assertNotNull($permission);
        DB::table('role_permissions')->insert(['role_id' => $this->f['dossier_role'], 'permission_id' => $permission]);
        $id = $this->f['dossiers'][0];
        $visits = DB::table('visits')->where('dossier_id', $id)->pluck('id')->all();
        $this->assertNotEmpty($visits);
        $this->api('GET', '/options')->assertOk()->assertJsonPath('data.capabilities.delete', true);
        $this->api('DELETE', '/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('patient_dossiers', ['id' => $id]);
        $this->assertSame(0, DB::table('visits')->where('dossier_id', $id)->count());
        $this->assertSame(0, DB::table('dossier_oncology_selections')->where('dossier_id', $id)->count());
        $this->assertSame(0, DB::table('visit_diagnoses')->whereIn('visit_id', $visits)->count());
        $this->assertSame(0, DB::table('visit_medications')->whereIn('visit_id', $visits)->count());
        $this->assertSame(0, DB::table('dose_sessions')->whereIn('visit_id', $visits)->count());
        $this->assertSame(0, DB::table('visit_outcomes')->whereIn('visit_id', $visits)->count());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'patient_dossier', 'entity_id' => $id, 'event' => 'deleted', 'facility_id' => $this->f['facility']]);
        $this->assertDatabaseHas('patient_dossiers', ['id' => $this->f['dossiers'][1]]);
        $this->api('DELETE', '/'.$id)->assertNotFound()->assertJsonPath('error.code', 'DOSSIER_NOT_FOUND');
    }
}
