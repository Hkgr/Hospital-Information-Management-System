<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DirectoryLifecycleConcurrencyTest extends TestCase
{
    // Committed fixtures are needed by the second process; do not wrap these
    // tests in RefreshDatabase's outer transaction. TestCase guards migrations.
    use DatabaseMigrations;

    public static function directories(): array
    {
        return [['doctors'], ['clinics']];
    }

    #[DataProvider('directories')]
    public function test_concurrent_reference_commit_prevents_hard_delete(string $kind): void
    {
        config(['clinics.doctor_staff_types' => ['DOCTOR']]);
        $user = User::factory()->create();
        $facility = DB::table('facilities')->insertGetId(['code' => 'TEST-CONCURRENT', 'name_ar' => 'اختبار', 'timezone' => 'Asia/Damascus']);
        $role = DB::table('roles')->insertGetId(['code' => 'TEST-CONCURRENT', 'name_ar' => 'اختبار']);
        foreach (['doctors.view', 'doctors.directory.delete', 'clinics.view', 'clinics.delete'] as $code) {
            $permission = DB::table('permissions')->insertGetId(['code' => $code, 'name_ar' => $code]);
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permission]);
        }
        DB::table('facility_user_roles')->insert(['user_id' => $user->id, 'facility_id' => $facility, 'role_id' => $role]);
        DB::table('global_user_roles')->insert(['user_id' => $user->id, 'role_id' => $role]);
        $type = DB::table('staff_types')->insertGetId(['code' => 'DOCTOR', 'name_ar' => 'طبيب']);
        $doctor = DB::table('staff')->insertGetId(['staff_code' => 'D1', 'full_name' => 'طبيب', 'search_name' => 'طبيب', 'staff_type_id' => $type]);
        $clinic = DB::table('clinics')->insertGetId(['facility_id' => $facility, 'code' => 'C1', 'name_ar' => 'عيادة']);
        $token = $user->createToken('concurrent', ['api'])->plainTextToken;
        $id = $kind === 'doctors' ? $doctor : $clinic;
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->getJson('/api/'.$kind.'/'.$id.'/deletion-preview?facility_id='.$facility, $headers)->assertJsonPath('data.action', 'delete');
        $process = new Process([PHP_BINARY, 'tests/Support/directory-reference-worker.php', (string) $doctor, (string) $clinic], base_path(), ['APP_ENV' => 'testing']);
        $process->setTimeout(15);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'REFERENCE_LOCKED')), 'Reference transaction must be active before DELETE. '.$process->getErrorOutput());
            $started = microtime(true);
            $this->app['auth']->forgetGuards();
            $this->deleteJson('/api/'.$kind.'/'.$id, ['facility_id' => $facility, 'lock_version' => 1], $headers)->assertConflict()->assertJsonPath('error.code', $kind === 'doctors' ? 'DOCTOR_REFERENCED' : 'CLINIC_REFERENCED');
            $this->assertGreaterThan(0.3, microtime(true) - $started, 'DELETE must overlap and wait for the reference transaction.');
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $this->assertDatabaseHas('clinic_staff', ['staff_id' => $doctor, 'clinic_id' => $clinic, 'ends_on' => null]);
            $this->assertDatabaseHas($kind === 'doctors' ? 'staff' : 'clinics', ['id' => $id, 'lock_version' => 1]);
            $this->assertDatabaseMissing('audit_logs', ['event' => 'deleted']);
        } finally {
            $process->stop();
        }
    }
}
