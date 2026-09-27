<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignSystemSuperAdmin extends Command
{
    protected $signature = 'access:super-admin {--apply : Explicitly assign the existing user 1} {--reason= : Assignment reason, required with apply} {--execution-reference= : Reviewed operator/job reference, required with apply; not an authenticated application user}';

    protected $description = 'Verify or explicitly assign the protected super administrator role to existing user 1; never creates or modifies the user.';

    public function handle(): int
    {
        if (! User::find(1)) {
            $this->error('User 1 does not exist. No user or assignment was created.');

            return self::FAILURE;
        }
        if ($this->option('apply')) {
            $reference = trim((string) $this->option('execution-reference'));
            if ($reference === '' || mb_strlen($reference) > 255) {
                $this->error('--execution-reference must contain 1 to 255 characters.');

                return self::FAILURE;
            }
            if (! trim((string) $this->option('reason'))) {
                $this->error('--reason is required for an audited assignment.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($reference) {
                $user = User::whereKey(1)->lockForUpdate()->firstOrFail();
                DB::table('roles')->insertOrIgnore(['code' => 'super_admin', 'name_ar' => 'مدير النظام الشامل', 'name_en' => 'Super Administrator', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $role = DB::table('roles')->where('code', 'super_admin')->lockForUpdate()->first();
                if (! $role->is_active || DB::table('global_user_roles')->where('role_id', $role->id)->where('user_id', '!=', $user->id)->exists()
                    || DB::table('facility_user_roles')->where('role_id', $role->id)->where('user_id', '!=', $user->id)->exists()) {
                    throw new \RuntimeException('Protected role is inactive or assigned to another user. Review existing assignments explicitly; nothing was changed.');
                }
                $changed = ! $role->is_system_super_admin;
                DB::table('roles')->where('id', $role->id)->update(['is_system_super_admin' => true]);
                $changed = DB::table('global_user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]) > 0 || $changed;
                foreach (DB::table('facilities')->where('is_active', true)->pluck('id') as $id) {
                    $added = DB::table('facility_user_roles')->insertOrIgnore(['user_id' => $user->id, 'role_id' => $role->id, 'facility_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                    if ($changed || $added) {
                        DB::table('audit_logs')->insert(['facility_id' => $id, 'actor_id' => null, 'entity_type' => 'role', 'entity_id' => $role->id, 'event' => 'assigned', 'new_values' => json_encode(['user_id' => $user->id, 'source' => 'operator_command', 'execution_reference' => $reference, 'role' => 'super_admin']), 'reason' => mb_substr($this->option('reason'), 0, 255), 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
                    }
                }
                if ($changed && ! DB::table('facilities')->where('is_active', true)->exists()) {
                    throw new \RuntimeException('An active facility is required to record the audited assignment.');
                }
            });
        }
        $user = User::findOrFail(1);
        $assigned = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->where('g.user_id', $user->id)->where('r.is_system_super_admin', true)->exists();
        $this->table(['User', 'Protected assignment', 'Account active', 'Effective access'], [[1, $assigned ? 'yes' : 'no', $user->is_active ? 'yes' : 'no', app(GlobalAccess::class)->systemRole($user) ? 'all active permissions / active facilities' : 'none']]);

        return $assigned ? self::SUCCESS : self::FAILURE;
    }
}
