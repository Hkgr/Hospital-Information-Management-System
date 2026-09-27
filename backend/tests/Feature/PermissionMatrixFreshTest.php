<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\UserAccessContext;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermissionMatrixFreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_alone_defines_every_admin_permission_and_enables_real_export(): void
    {
        // Run on a new migrated database without other permission seeders.
        $this->assertDatabaseMissing('permissions', ['code' => 'dossiers.export']);
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->assertEqualsCanonicalizing(PermissionMatrixPhaseOneSeeder::ADMIN, DB::table('permissions')
            ->whereIn('code', PermissionMatrixPhaseOneSeeder::ADMIN)->pluck('code')->all());
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId(['code' => 'FRESH-MATRIX', 'name_ar' => 'اختبار جديد', 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->where('code', 'hospital_admin')->value('id');
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $facility, 'role_id' => $role]);
        $this->assertEqualsCanonicalizing(PermissionMatrixPhaseOneSeeder::ADMIN, app(UserAccessContext::class)->forUser($user)[0]['permissions']);
        $token = $user->createToken('fresh-export', ['api'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/api/dossiers/export/xlsx', ['facility_id' => $facility]);
        $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $response->getContent());
    }
}
