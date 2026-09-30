<?php

namespace App\Services\Users;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Operator-only, reviewed transition. No clinical table is written. */
class AccessConsolidation
{
    public function preview(bool $lock = false): array
    {
        $read = static fn ($q) => ($lock ? $q->lockForUpdate() : $q)->get()->map(fn ($r) => (array) $r)->all();
        $users = $read(DB::table('users')->where('id', 1)->select('id', 'username', 'is_active'));
        $roles = $read(DB::table('roles')->whereIn('code', ['super_admin', 'full_access_user_1'])->orderBy('id'));
        $ids = array_column($roles, 'id');
        $permissions = $read(DB::table('permissions')->orderBy('id'));
        $grants = $read(DB::table('role_permissions')->whereIn('role_id', $ids)->orderBy('id'));
        $local = $read(DB::table('facility_user_roles')->whereIn('role_id', $ids)->orderBy('id'));
        $global = $read(DB::table('global_user_roles')->whereIn('role_id', $ids)->orderBy('id'));
        $otherProtected = $read(DB::table('global_user_roles as g')->join('roles as r', 'r.id', '=', 'g.role_id')
            ->where('g.user_id', 1)->where('r.is_system_super_admin', true)->whereNotIn('r.id', $ids)->orderBy('r.id')->select('r.id', 'r.code', 'r.is_active'));
        $facilities = $read(DB::table('facilities')->where('is_active', true)->orderBy('id')->select('id', 'code'));
        $conflicts = [];
        foreach ($otherProtected as $other) {
            $conflicts[] = 'User 1 has another protected assignment: '.$other['code'].' ('.$other['id'].'). Review it explicitly.';
        }
        $admin = collect($roles)->firstWhere('code', 'super_admin');
        if (! $users || ! $users[0]['is_active']) {
            $conflicts[] = 'Existing user 1 must be active; this command never creates or activates accounts.';
        }
        if (! $admin || ! $admin['is_active']) {
            $conflicts[] = 'An active super_admin definition is required; disabled roles are never reactivated.';
        }
        if (! $facilities) {
            $conflicts[] = 'An active facility is required for an audited transition.';
        }
        foreach ([...$local, ...$global] as $assignment) {
            if ((int) $assignment['user_id'] !== 1) {
                $conflicts[] = 'Role '.$assignment['role_id'].' is assigned to other user '.$assignment['user_id'].'; explicit operator resolution required.';
            }
        }
        $activeCodes = array_column(array_filter($permissions, fn ($p) => $p['is_active']), 'code');
        foreach (ProtectedRolePolicy::MINIMUM as $code) {
            if (! in_array($code, $activeCodes, true)) {
                $conflicts[] = 'Required access-management definition is absent or disabled: '.$code;
            }
        }
        $state = compact('users', 'roles', 'permissions', 'grants', 'local', 'global', 'otherProtected', 'facilities');
        $initialized = (bool) ($admin['access_consolidated_at'] ?? null);
        $current = array_column(array_filter($grants, fn ($g) => $g['role_id'] === ($admin['id'] ?? null)), 'permission_id');
        $desired = $initialized ? $current : array_values(array_unique([...array_column($grants, 'permission_id'), ...array_column(array_filter($permissions, fn ($p) => $p['is_active']), 'id')]));
        sort($desired);

        return ['fingerprint' => hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 'conflicts' => array_values(array_unique($conflicts)),
            'already_consolidated' => $initialized, 'state' => $state, 'desired_permission_ids' => $desired,
            'added_permission_ids' => array_values(array_diff($desired, $current)), 'removed_permission_ids' => [],
            'policy' => 'Current active permissions are materialized once. Future definitions require explicit reviewed selection. Historical legacy assignments remain attached to the archived role.'];
    }

    public function apply(string $fingerprint, string $reference, string $reason): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint) || trim($reference) === '' || trim($reason) === '' || mb_strlen($reference) > 255 || mb_strlen($reason) > 255) {
            throw new RuntimeException('Valid preview fingerprint, execution reference and reason (1–255 characters) are required.');
        }

        return DB::transaction(function () use ($fingerprint, $reference, $reason) {
            $preview = $this->preview(true);
            if (! hash_equals($preview['fingerprint'], $fingerprint) || $preview['conflicts']) {
                throw new RuntimeException('Preview changed or conflicts remain. Nothing changed. '.implode(' ', $preview['conflicts']));
            }
            if ($preview['already_consolidated']) {
                return $preview;
            }
            $role = collect($preview['state']['roles'])->firstWhere('code', 'super_admin');
            foreach ($preview['desired_permission_ids'] as $permission) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role['id'], 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('roles')->where('id', $role['id'])->update(['name_ar' => 'مدير النظام الشامل', 'is_system_super_admin' => true, 'access_consolidated_at' => now(), 'lock_version' => $role['lock_version'] + 1, 'updated_at' => now()]);
            DB::table('roles')->where('code', 'full_access_user_1')->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('global_user_roles')->insertOrIgnore(['user_id' => 1, 'role_id' => $role['id'], 'created_at' => now(), 'updated_at' => now()]);
            foreach ($preview['state']['facilities'] as $facility) {
                DB::table('facility_user_roles')->insertOrIgnore(['user_id' => 1, 'role_id' => $role['id'], 'facility_id' => $facility['id'], 'created_at' => now(), 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['facility_id' => $facility['id'], 'actor_id' => null, 'entity_type' => 'role', 'entity_id' => $role['id'], 'event' => 'updated',
                    'old_values' => json_encode($preview['state'], JSON_THROW_ON_ERROR),
                    'new_values' => json_encode(['source' => 'operator_command', 'execution_reference' => $reference, 'user_id' => 1, 'role' => 'super_admin', 'archived_role' => 'full_access_user_1', 'preview_fingerprint' => $fingerprint, 'permission_ids' => $preview['desired_permission_ids'], 'legacy_assignments_retained' => true], JSON_THROW_ON_ERROR),
                    'reason' => $reason, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
            }

            return $this->preview();
        });
    }
}
