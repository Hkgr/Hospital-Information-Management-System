<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Users\ProtectedRolePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AssignSystemSuperAdmin extends Command
{
    protected $signature = 'access:super-admin {--apply : Deprecated; use access:consolidate-admin with a reviewed fingerprint} {--reason= : Legacy option; use access:consolidate-admin} {--execution-reference= : Legacy option; use access:consolidate-admin}';

    protected $description = 'Verify the existing user 1 protected assignment and explicit access; does not change users or grants.';

    public function handle(): int
    {
        if (! User::find(1)) {
            $this->error('User 1 does not exist. No user or assignment was created.');

            return self::FAILURE;
        }
        if ($this->option('apply')) {
            $this->error('Use access:consolidate-admin preview then --apply --fingerprint with an operator reference. This command no longer grants or replenishes permissions.');

            return self::FAILURE;
        }
        $user = User::findOrFail(1);
        $assigned = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->where('g.user_id', $user->id)->where('r.is_system_super_admin', true)->exists();
        $this->table(['User', 'Protected assignment', 'Account active', 'Effective access'], [[1, $assigned ? 'yes' : 'no', $user->is_active ? 'yes' : 'no', app(GlobalAccess::class)->systemRole($user) ? count(app(GlobalAccess::class)->codes($user)).' explicitly assigned permissions / active facilities' : 'none']]);

        return app(GlobalAccess::class)->systemRole($user) && ! array_diff(ProtectedRolePolicy::MINIMUM, app(GlobalAccess::class)->codes($user)) ? self::SUCCESS : self::FAILURE;
    }
}
