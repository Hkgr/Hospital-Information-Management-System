<?php

namespace Tests\Feature;

use App\Services\Users\AdminCardEditingPermissions;
use Database\Seeders\PermissionMatrixPhaseOneSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Support\DossierCompletionFixture;
use Tests\TestCase;

class AdminCardEditingPermissionsTest extends TestCase
{
    use DatabaseTransactions;

    private array $f;

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = DossierCompletionFixture::make();
        $this->seed(PermissionMatrixPhaseOneSeeder::class);
        $this->role = DB::table('roles')->where('code', 'hospital_admin')->value('id');
    }

    private function granted(): array
    {
        return DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $this->role)->orderBy('p.code')->pluck('p.code')->all();
    }

    public function test_dry_run_changes_nothing_and_apply_adds_only_the_contract_with_audit_and_is_repeatable(): void
    {
        $before = $this->granted();
        $others = DB::table('role_permissions')->where('role_id', '!=', $this->role)->orderBy('id')->get()->toJson();
        $assignments = DB::table('facility_user_roles')->orderBy('id')->get()->toJson();
        $global = DB::table('global_user_roles')->orderBy('id')->get()->toJson();
        $version = DB::table('roles')->where('id', $this->role)->value('lock_version');
        $audits = DB::table('audit_logs')->count();

        $this->artisan('access:admin-card-editing')->assertSuccessful();
        $this->assertSame($before, $this->granted());
        $this->assertSame($version, DB::table('roles')->where('id', $this->role)->value('lock_version'));
        $this->assertSame($audits, DB::table('audit_logs')->count());

        $this->artisan('access:admin-card-editing', ['--apply' => true])->assertFailed();
        $this->assertSame($before, $this->granted());

        $args = ['--apply' => true, '--facility' => (string) $this->f['facility'], '--execution-reference' => 'TEST/admin-editing'];
        $this->artisan('access:admin-card-editing', $args)->assertSuccessful();

        $after = $this->granted();
        $this->assertSame([], array_diff($before, $after), 'Nothing may be removed from the role.');
        $this->assertSame([], array_diff(AdminCardEditingPermissions::GRANTS, $after));
        $this->assertSame([], array_diff($after, $before, AdminCardEditingPermissions::GRANTS), 'Nothing outside the contract may be added.');
        foreach (['dossiers.personal.update', 'dossiers.create', 'dossiers.delete', 'dossiers.visits.complete', 'dossiers.finalize'] as $code) {
            $this->assertNotContains($code, $after);
        }
        $this->assertSame($others, DB::table('role_permissions')->where('role_id', '!=', $this->role)->orderBy('id')->get()->toJson());
        $this->assertSame($assignments, DB::table('facility_user_roles')->orderBy('id')->get()->toJson());
        $this->assertSame($global, DB::table('global_user_roles')->orderBy('id')->get()->toJson());
        $this->assertSame($version + 1, DB::table('roles')->where('id', $this->role)->value('lock_version'));
        $this->assertSame($audits + 1, DB::table('audit_logs')->count());
        $audit = DB::table('audit_logs')->where('entity_type', 'role')->where('entity_id', $this->role)->where('reason', 'TEST/admin-editing')->first();
        $this->assertNull($audit->actor_id);
        $this->assertSame('TEST/admin-editing', json_decode($audit->new_values, true)['execution_reference']);

        $this->artisan('access:admin-card-editing', $args)->assertSuccessful();
        $this->assertSame($audits + 1, DB::table('audit_logs')->count());
        $this->assertSame($version + 1, DB::table('roles')->where('id', $this->role)->value('lock_version'));
    }

    public function test_disabled_definition_or_protected_role_blocks_the_entire_apply(): void
    {
        $before = DB::table('role_permissions')->orderBy('id')->get()->toJson();
        $args = ['--apply' => true, '--facility' => (string) $this->f['facility'], '--execution-reference' => 'TEST/blocked'];

        DB::table('permissions')->where('code', 'dossiers.medical.update')->update(['is_active' => false]);
        $this->artisan('access:admin-card-editing', $args)->assertFailed();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
        DB::table('permissions')->where('code', 'dossiers.medical.update')->update(['is_active' => true]);

        DB::table('roles')->where('id', $this->role)->update(['is_system_super_admin' => true]);
        $this->artisan('access:admin-card-editing', $args)->assertFailed();
        $this->assertSame($before, DB::table('role_permissions')->orderBy('id')->get()->toJson());
    }
}
