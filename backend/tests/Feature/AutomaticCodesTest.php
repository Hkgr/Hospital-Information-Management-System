<?php

namespace Tests\Feature;

use App\Services\Directory\IssuedCodes;
use Database\Seeders\ClinicPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\StockFixture;
use Tests\TestCase;

class AutomaticCodesTest extends TestCase
{
    use RefreshDatabase;

    private array $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = StockFixture::make();
        $this->seed([ClinicPermissionsSeeder::class, DoctorPermissionsSeeder::class]);
        $role = DB::table('facility_user_roles')->where('user_id', $this->f['user']->id)->value('role_id');
        foreach (DB::table('permissions')->where('is_active', true)->pluck('id') as $permission) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
        config(['clinics.doctor_staff_types' => ['CAT-'.$this->f['tag']]]);
    }

    private function api(string $path, array $data)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/'.$path, $data + ['facility_id' => $this->f['facility']], ['Authorization' => 'Bearer '.$this->f['token']]);
    }

    public function test_directory_creates_replay_once_and_reject_changed_payloads_without_writes(): void
    {
        $cases = [
            ['clinics', ['name_ar' => 'عيادة إعادة المحاولة', 'is_active' => true]],
            ['doctors', ['name' => 'طبيب إعادة المحاولة', 'staff_type_id' => DB::table('staff_types')->where('code', 'CAT-'.$this->f['tag'])->value('id'), 'specialty_ids' => DB::table('specialties')->where('is_active', true)->limit(1)->pluck('id')->all(), 'is_active' => true]],
            ['stock/stores', ['name_ar' => 'مستودع', 'is_active' => true]],
            ['stock/suppliers', ['name_ar' => 'مورد', 'is_active' => true]],
        ];
        foreach (['service', 'procedure', 'medication'] as $kind) {
            $cases[] = ['service-catalog', ['kind' => $kind, 'name_ar' => 'تعريف '.$kind, 'is_active' => true, ...($kind === 'service' ? ['category_id' => $this->f['category']] : [])]];
            $cases[] = ['service-catalog/categories', ['kind' => $kind, 'name_ar' => 'تصنيف '.$kind, 'is_active' => true]];
        }
        foreach ($cases as [$path, $data]) {
            $data['request_id'] = (string) Str::uuid();
            $first = $this->api($path, $data)->assertCreated()->json('data');
            $this->assertStringStartsWith('AUTO-', $first['code']);
            $sequences = DB::table('number_sequences')->orderBy('id')->get()->toJson();
            $audits = DB::table('audit_logs')->count();
            $replay = $this->api($path, array_reverse($data, true))->assertCreated()->json('data');
            $this->assertSame($first['id'], $replay['id']);
            $this->assertSame($first['code'], $replay['code']);
            $data['is_active'] = false;
            $this->api($path, $data)->assertConflict()->assertJsonPath('error.code', 'CREATION_REQUEST_CONFLICT');
            $this->assertSame($audits, DB::table('audit_logs')->count());
            $this->assertSame($sequences, DB::table('number_sequences')->orderBy('id')->get()->toJson());
        }
    }

    public function test_replay_requires_current_global_and_facility_permission(): void
    {
        $data = ['request_id' => (string) Str::uuid(), 'kind' => 'procedure', 'name_ar' => 'تعريف', 'is_active' => true];
        $this->api('service-catalog', $data)->assertCreated();
        DB::table('global_user_roles')->where('user_id', $this->f['user']->id)->delete();
        $this->api('service-catalog', $data)->assertForbidden();
        $this->api('service-catalog', $data + ['facility_id' => $this->f['other']])->assertForbidden();
    }

    public function test_rollback_preserves_issued_request_history_before_any_ddl(): void
    {
        $this->api('clinics', ['request_id' => (string) Str::uuid(), 'name_ar' => 'عيادة', 'is_active' => true])->assertCreated();
        $migration = require database_path('migrations/2026_09_28_200000_directory_creation_requests.php');
        $this->expectExceptionMessage('Cannot discard creation replay history');
        $migration->down();
    }

    public function test_codes_survive_updates_skip_archived_codes_and_are_not_reused_after_deletion(): void
    {
        $generator = app(IssuedCodes::class);
        $first = $generator->catalog('procedure');
        $id = DB::table('procedures')->insertGetId(['code' => $first, 'name_ar' => 'مؤرشف', 'archived_at' => now()]);
        $second = $generator->catalog('procedure');
        $this->assertNotSame($first, $second);
        DB::table('procedures')->where('id', $id)->delete();
        $this->assertNotContains($generator->catalog('procedure'), [$first, $second]);
        $before = DB::table('number_sequences')->orderBy('id')->get()->toJson();
        try {
            DB::transaction(function () use ($generator) {
                $generator->doctor();
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            $this->assertSame($before, DB::table('number_sequences')->orderBy('id')->get()->toJson());
        }
    }

    public function test_receipt_number_is_generated_once_and_external_identifiers_remain_literal(): void
    {
        $data = ['request_id' => (string) Str::uuid(), 'store_id' => $this->f['store'], 'medication_source' => 'ministry_of_health', 'received_on' => $this->f['today'], 'invoice_number' => '00017/EXT', 'items' => []];
        $one = $this->api('stock/receipts', $data)->assertCreated()->json('data');
        $this->assertStringStartsWith('AUTO-RCV-', $one['receipt_no']);
        $this->assertSame('00017/EXT', $one['invoice_number']);
        $this->api('stock/receipts', $data)->assertCreated()->assertJsonPath('data.id', $one['id'])->assertJsonPath('data.receipt_no', $one['receipt_no']);
        $this->api('stock/receipts', $data + ['receipt_no' => 'MANUAL'])->assertUnprocessable()->assertJsonValidationErrors('receipt_no');
        DB::table('medication_receipts')->where('id', $one['id'])->update(['receipt_no' => '000-LEGACY']);
        unset($data['request_id']);
        $this->putJson('/api/stock/receipts/'.$one['id'], $data + ['facility_id' => $this->f['facility'], 'lock_version' => 1], ['Authorization' => 'Bearer '.$this->f['token']])->assertOk()->assertJsonPath('data.receipt_no', '000-LEGACY');
    }
}
