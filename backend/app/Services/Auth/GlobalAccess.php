<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class GlobalAccess
{
    public function systemRole(User $user): ?object
    {
        if (! $user->is_active) {
            return null;
        }

        return DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('r.is_system_super_admin', true)->first(['r.*']);
    }

    public function codes(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($this->systemRole($user)) {
            return DB::table('permissions')->where('is_active', true)->orderBy('code')->pluck('code')->all();
        }

        $codes = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->distinct()->orderBy('p.code')->pluck('p.code')->all();

        return app(TaskPermissions::class)->effective($codes, true);
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->codes($user), true);
    }
}
