<?php

namespace App\Services\Users;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Role maintenance only. Runtime authorization continues to use permissions. */
class AdminCardEditingPermissions
{
    // Reading plus the medical sections and treatment. Identity (`dossiers.personal.update`),
    // creation, completion, deletion and voiding are deliberately not part of this contract.
    public const GRANTS = [
        'dossiers.medical.view', 'dossiers.visits.view',
        'dossiers.medical.update', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update', 'dossiers.services.update',
        'dossiers.procedures.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update', 'dossiers.pathology.update',
        'dossiers.treatment.view', 'dossiers.treatment.update', 'dossiers.treatment.schedule.update',
        'dossiers.treatment.administration.correct', 'dossiers.treatment.dispensing.correct',
    ];

    public const ROLE = 'hospital_admin';

    /** Additive only: grants missing from the role are added; nothing is removed. */
    public function reconcile(bool $apply, ?int $auditFacility, string $reference): array
    {
        return DB::transaction(function () use ($apply, $auditFacility, $reference) {
            // Same fence as role editors; increment their role version on change.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $roles = DB::table('roles')->orderBy('id')->lockForUpdate()->get();
            $permissions = DB::table('permissions')->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            DB::table('role_permissions')->orderBy('id')->lockForUpdate()->get(['id']);
            $role = $roles->first(fn ($r) => $r->code === self::ROLE);
            if (! $role || ! $role->is_active || $role->is_system_super_admin) {
                throw new \RuntimeException('An existing active ordinary '.self::ROLE.' role is required. No role or assignment was created or activated.');
            }
            if ($apply && (! $auditFacility || ! DB::table('facilities')->where('id', $auditFacility)->where('is_active', true)->exists())) {
                throw new \RuntimeException('Apply requires an existing active --facility for the operator audit. The shared role policy affects all its assignments.');
            }
            $missing = array_values(array_diff(self::GRANTS, $permissions->keys()->all()));
            if ($missing) {
                throw new \RuntimeException('Required permission definitions are missing; run the permission seeders first. No changes: '.implode(', ', $missing));
            }
            $blocked = array_filter(self::GRANTS, fn ($code) => ! $permissions[$code]->is_active);
            if ($blocked) {
                throw new \RuntimeException('Required definitions are disabled; no changes: '.implode(', ', $blocked));
            }
            $before = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role->id)->orderBy('p.code')->pluck('p.code')->all();
            $add = array_values(array_diff(self::GRANTS, $before));
            $after = array_values(array_unique(array_merge($before, $add)));
            sort($after);
            if ($apply && $add) {
                foreach ($add as $code) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissions[$code]->id, 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('roles')->where('id', $role->id)->update(['lock_version' => $role->lock_version + 1, 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['facility_id' => $auditFacility, 'actor_id' => null, 'entity_type' => 'role', 'entity_id' => $role->id, 'event' => 'updated',
                    'old_values' => json_encode(['permissions' => $before, 'lock_version' => $role->lock_version], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'new_values' => json_encode(['permissions' => $after, 'lock_version' => $role->lock_version + 1, 'added' => $add, 'removed' => [], 'source' => 'admin_card_editing_permissions', 'execution_reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'reason' => $reference, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
            }

            return ['role_id' => $role->id, 'added' => $add, 'applied' => $apply && (bool) $add];
        }, 3);
    }
}
