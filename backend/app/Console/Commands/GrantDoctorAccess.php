<?php

namespace App\Console\Commands;

use App\Support\TestDatabaseSafety;
use Database\Seeders\DoctorPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantDoctorAccess extends Command
{
    protected $signature = 'doctors:grant-access {--user= : Existing username} {--role=super_admin : Existing approved role code} {--facility=* : Explicit facility codes} {--global : Explicitly assign the role globally for directory mutations} {--apply : Apply the displayed grant; otherwise read-only}';

    protected $description = 'Preview/apply an explicit doctor permission grant to an existing user and approved role';

    public function handle(): int
    {
        if (app()->environment('testing')) {
            TestDatabaseSafety::assertAvailable(app());
        }
        $user = DB::table('users')->where('username', $this->option('user'))->where('is_active', true)->first();
        $role = DB::table('roles')->where('code', $this->option('role'))->where('is_active', true)->first();
        $codes = array_values(array_unique($this->option('facility')));
        $facilities = DB::table('facilities')->whereIn('code', $codes)->where('is_active', true)->get();
        $permissions = DB::table('permissions')->whereIn('code', array_keys(DoctorPermissionsSeeder::PERMISSIONS))->where('is_active', true)->get();
        if (! $user || ! $role || ! $codes || count($codes) !== $facilities->count() || $permissions->count() !== count(DoctorPermissionsSeeder::PERMISSIONS)) {
            $this->error('Require an existing active user/role, explicit active facilities, and active DoctorPermissionsSeeder definitions. Nothing changed.');

            return self::FAILURE;
        }
        $this->table(['User', 'Role', 'Facilities', 'Global directory grant'], [[$user->username, $role->code, implode(', ', $codes), $this->option('global') ? 'YES (explicit)' : 'NO']]);
        $this->warn('Role permissions also affect existing assignments of this role. Global mutations still require a global_user_roles assignment.');
        if (! $this->option('apply')) {
            $this->info('Preview only. Add --apply to perform exactly this grant.');

            return self::SUCCESS;
        }
        DB::transaction(function () use ($user, $role, $facilities, $permissions) {
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission->id, 'created_at' => now()]);
            }
            foreach ($facilities as $facility) {
                DB::table('facility_user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id, 'facility_id' => $facility->id, 'created_at' => now()]);
            }
            if ($this->option('global')) {
                DB::table('global_user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }, 3);
        $this->info('Explicit grant applied. No accounts, roles, or medical data were created.');

        return self::SUCCESS;
    }
}
