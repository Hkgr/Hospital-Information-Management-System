<?php

namespace Tests\Feature;

use App\Services\BloodBank\BloodBankReconcile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BloodBankFixture;
use Tests\TestCase;

// Legacy links remain readable; all current write behavior is covered by BloodEventsTest.
class BloodBankTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_links_resolve_to_the_same_person_and_writes_cannot_create_orphan_profiles(): void
    {
        $f = BloodBankFixture::make();
        $donor = DB::table('blood_donors')->insertGetId(['facility_id' => $f['facility'], 'donor_code' => 'OLD-DONOR', 'full_name' => 'ملف تاريخي بلا واقعة', 'entered_by' => $f['user']->id]);
        app(BloodBankReconcile::class)->apply();
        $person = DB::table('blood_donors')->where('id', $donor)->value('person_id');
        $this->withHeader('Authorization', 'Bearer '.$f['user']->createToken('legacy', ['api'])->plainTextToken);
        $this->getJson('/api/blood-bank/legacy/donor/'.$donor.'?facility_id='.$f['facility'])->assertOk()->assertJsonPath('data.person_id', $person)->assertJsonPath('data.event_id', null);
        $this->getJson('/api/blood-bank/legacy/donor/'.$donor.'?facility_id='.$f['other'])->assertForbidden();
        $this->getJson('/api/blood-bank/legacy/donor/'.$donor.'/donations/999?facility_id='.$f['facility'])->assertNotFound();
        foreach (['POST' => ['', '/donor/'.$donor.'/donations'], 'PUT' => ['/donor/'.$donor, '/donor/'.$donor.'/donations/999']] as $method => $paths) {
            foreach ($paths as $path) {
                $this->json($method, '/api/blood-bank'.$path, ['facility_id' => $f['facility']])->assertStatus(410)->assertJsonPath('error.code', 'BLOOD_BANK_LEGACY_WRITE_RETIRED')->assertHeader('Cache-Control', 'no-store, private');
            }
        }
        $this->assertDatabaseCount('blood_donors', 1);
        $this->assertDatabaseCount('blood_donations', 0);
    }
}
