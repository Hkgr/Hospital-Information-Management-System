<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Users\ProtectedRolePolicy;
use Illuminate\Support\Facades\DB;

class GlobalAccess
{
    public function systemRole(User $user): ?object
    {
        if (! $user->is_active) {
            return null;
        }

        return DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('r.is_system_super_admin', true)
            ->orderByDesc('r.access_consolidated_at')->orderBy('r.id')->first(['r.*']);
    }

    public function codes(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($role = $this->systemRole($user)) {
            // A protected assignment selects one explicit policy. Other roles and
            // legacy umbrella expansion must not reinstate excluded operations.
            // Delegation authority belongs to the active protected assignment,
            // not to its operational choices or the account's name/identifier.
            // Respect disabled definitions; never add operational permissions.
            return DB::table('permissions')->where('is_active', true)
                ->where(fn ($q) => $q->whereIn('code', ProtectedRolePolicy::MINIMUM)
                    ->orWhereIn('id', DB::table('role_permissions')->where('role_id', $role->id)->select('permission_id')))
                ->orderBy('code')->pluck('code')->all();
        }

        $rows = DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('g.user_id', $user->id)->where('r.is_active', true)->where('p.is_active', true)->orderBy('p.code')->get(['p.code', 'r.code as role_code']);
        $codes = $rows->reject(fn ($r) => str_starts_with($r->role_code, 'delegation-'))->pluck('code')->unique()->all();
        $exact = $rows->filter(fn ($r) => str_starts_with($r->role_code, 'delegation-'))->pluck('code')->all();

        $result = array_values(array_unique([...app(TaskPermissions::class)->effective($codes, true), ...$exact]));
        sort($result);

        return $result;
    }

    public function allows(User $user, string $permission): bool
    {
        return in_array($permission, $this->codes($user), true);
    }
}
