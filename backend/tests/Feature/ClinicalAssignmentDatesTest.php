<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Clinics\ClinicCounts;
use App\Services\Directory\ClinicStaffLinks;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClinicalAssignmentDatesTest extends TestCase
{
    use DatabaseTransactions;

    private User $user;

    private int $facility;

    private int $clinic;

    private int $staff;

    private int $role;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = Str::random(12);
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('dates', ['api'])->plainTextToken;
        $this->facility = DB::table('facilities')->insertGetId(['code' => 'DATES-'.$tag, 'name_ar' => 'اختبار التواريخ', 'timezone' => 'Asia/Damascus']);
        $this->role = DB::table('roles')->insertGetId(['code' => 'DATES-'.$tag, 'name_ar' => 'دور ارتباطات']);
        DB::table('facility_user_roles')->insert(['user_id' => $this->user->id, 'role_id' => $this->role, 'facility_id' => $this->facility]);
        foreach (DB::table('permissions')->whereIn('code', ['clinics.view', 'clinics.edit', 'doctors.view', 'doctors.link'])->pluck('id') as $id) {
            DB::table('role_permissions')->insert(['role_id' => $this->role, 'permission_id' => $id]);
        }
        config(['clinics.doctor_staff_types' => [$tag]]);
        $type = DB::table('staff_types')->insertGetId(['code' => $tag, 'name_ar' => 'طبيب']);
        $this->staff = DB::table('staff')->insertGetId(['staff_type_id' => $type, 'staff_code' => $tag, 'full_name' => 'طبيب اختبار', 'search_name' => 'طبيب اختبار']);
        $this->clinic = DB::table('clinics')->insertGetId(['facility_id' => $this->facility, 'code' => $tag, 'name_ar' => 'عيادة اختبار']);
    }

    private function period(string $start, ?string $end = null): int
    {
        return DB::table('clinic_staff')->insertGetId(['clinic_id' => $this->clinic, 'staff_id' => $this->staff, 'starts_on' => $start, 'ends_on' => $end]);
    }

    private function api(int $id, array $data = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->putJson('/api/clinics/'.$this->clinic.'/assignments/'.$id, $data + ['facility_id' => $this->facility, 'starts_on' => '2022-01-01', 'ends_on' => null,
            'clinic_lock_version' => DB::table('clinics')->where('id', $this->clinic)->value('lock_version'), 'staff_lock_version' => DB::table('staff')->where('id', $this->staff)->value('lock_version'), 'reason' => 'تصحيح فترة العمل'], ['Authorization' => 'Bearer '.$this->token]);
    }

    public function test_historical_availability_is_fixed_by_explicit_date_edit_with_versions_and_audit(): void
    {
        $id = $this->period('2024-01-01');
        $facility = ['id' => $this->facility, 'today' => '2022-06-01'];
        $this->assertFalse(app(ClinicCounts::class)->currentDoctors($facility)->where('s.id', $this->staff)->exists());
        $this->api($id)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.starts_on', '2022-01-01');
        $this->assertTrue(app(ClinicCounts::class)->currentDoctors($facility)->where('s.id', $this->staff)->exists());
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'clinic', 'entity_id' => $this->clinic, 'actor_id' => $this->user->id, 'event' => 'doctors_changed']);
        $this->api($id, ['clinic_lock_version' => 1, 'staff_lock_version' => 1])->assertConflict();
    }

    public function test_start_is_inclusive_end_is_exclusive_and_inactive_doctors_never_become_new_choices(): void
    {
        $this->period('2022-01-01', '2023-01-01');
        foreach (['2021-12-31' => false, '2022-01-01' => true, '2022-12-31' => true, '2023-01-01' => false] as $date => $expected) {
            $this->assertSame($expected, app(ClinicCounts::class)->currentDoctors(['id' => $this->facility, 'today' => $date])->where('s.id', $this->staff)->exists());
        }
        DB::table('staff')->where('id', $this->staff)->update(['is_active' => false]);
        $this->assertFalse(app(ClinicCounts::class)->currentDoctors(['id' => $this->facility, 'today' => '2022-06-01'])->where('s.id', $this->staff)->exists());
    }

    public function test_backdating_is_dry_run_by_default_idempotent_preserves_ends_and_reports_ambiguous_groups(): void
    {
        $id = $this->period('2024-01-01', '2025-01-01');
        $before = DB::table('audit_logs')->count();
        $this->artisan('clinical-assignments:backdate', ['--facility' => $this->facility])->assertSuccessful();
        $this->assertDatabaseHas('clinic_staff', ['id' => $id, 'starts_on' => '2024-01-01']);
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->artisan('clinical-assignments:backdate', ['--facility' => $this->facility, '--apply' => true, '--execution-reference' => 'TEST-CHANGE'])->assertSuccessful();
        $this->assertDatabaseHas('clinic_staff', ['id' => $id, 'starts_on' => '2022-01-01', 'ends_on' => '2025-01-01']);
        $after = DB::table('audit_logs')->count();
        $this->artisan('clinical-assignments:backdate', ['--facility' => $this->facility, '--apply' => true, '--execution-reference' => 'TEST-REPLAY'])->assertSuccessful();
        $this->assertSame($after, DB::table('audit_logs')->count());
        $second = $this->period('2025-01-01');
        $this->artisan('clinical-assignments:backdate', ['--facility' => $this->facility, '--apply' => true, '--execution-reference' => 'TEST-CONFLICT'])->assertFailed();
        $this->assertDatabaseHas('clinic_staff', ['id' => $second, 'starts_on' => '2025-01-01']);
        $this->assertSame($after, DB::table('audit_logs')->count());
    }

    public function test_earlier_and_cancelled_periods_are_not_reopened_or_overwritten(): void
    {
        $early = $this->period('2020-01-01', '2021-01-01');
        $cancelled = $this->period('2026-01-01', '2026-01-01');
        $this->artisan('clinical-assignments:backdate', ['--facility' => $this->facility, '--apply' => true, '--execution-reference' => 'TEST-CANCELLED'])->assertSuccessful();
        $this->assertDatabaseHas('clinic_staff', ['id' => $early, 'starts_on' => '2020-01-01', 'ends_on' => '2021-01-01']);
        $this->assertDatabaseHas('clinic_staff', ['id' => $cancelled, 'starts_on' => '2026-01-01', 'ends_on' => '2026-01-01']);
    }

    public function test_overlap_foreign_facility_and_revoked_authority_are_rejected_without_writes(): void
    {
        $this->period('2022-01-01', '2024-01-01');
        $id = $this->period('2025-01-01');
        $before = DB::table('audit_logs')->count();
        $this->api($id, ['starts_on' => '2023-01-01'])->assertUnprocessable();
        $this->assertSame($before, DB::table('audit_logs')->count());
        $this->api($id, ['facility_id' => PHP_INT_MAX])->assertForbidden();
        DB::table('role_permissions')->where('role_id', $this->role)->where('permission_id', DB::table('permissions')->where('code', 'clinics.edit')->value('id'))->delete();
        $this->api($id)->assertForbidden();
        $this->assertDatabaseHas('clinic_staff', ['id' => $id, 'starts_on' => '2025-01-01']);
    }

    public function test_new_link_default_and_explicit_future_date_are_respected(): void
    {
        $links = app(ClinicStaffLinks::class);
        $links->lockStaff([$this->staff]);
        $links->lockClinics([$this->clinic]);
        $links->change($this->clinic, $this->staff, true, ['id' => $this->facility, 'today' => '2026-01-01']);
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $this->clinic, 'staff_id' => $this->staff, 'starts_on' => '2022-01-01']);
        DB::table('clinic_staff')->where('clinic_id', $this->clinic)->delete();
        $links->change($this->clinic, $this->staff, true, ['id' => $this->facility, 'today' => '2026-01-01'], '2027-01-01');
        $this->assertDatabaseHas('clinic_staff', ['clinic_id' => $this->clinic, 'staff_id' => $this->staff, 'starts_on' => '2027-01-01']);
    }
}
