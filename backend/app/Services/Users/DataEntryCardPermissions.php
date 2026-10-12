<?php

namespace App\Services\Users;

use App\Services\Auth\TaskPermissions;
use Database\Seeders\CatalogPermissionsSeeder;
use Database\Seeders\DoctorPermissionsSeeder;
use Database\Seeders\DossierCompletionPermissionsSeeder;
use Database\Seeders\DossierPathologyPermissionsSeeder;
use Database\Seeders\DossierWorkflowPermissionsSeeder;
use Database\Seeders\OncologyPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Role maintenance only. Runtime authorization continues to use permissions. */
class DataEntryCardPermissions
{
    public const GRANTS = [
        'patients.basic.view', 'patient_cards.register', 'patients.own.correct', 'patients.corrections.request',
        'dossiers.medical.view', 'dossiers.visits.view', 'dossiers.create', 'dossiers.personal.update', 'dossiers.medical.update',
        'dossiers.visits.create', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update',
        'dossiers.services.update', 'dossiers.procedures.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update',
        'dossiers.attachments.view', 'dossiers.attachments.upload', 'dossiers.attachments.download',
        'dossiers.finalize', 'dossiers.visits.complete', 'dossiers.assessment.update', 'dossiers.pathology.create', 'dossiers.pathology.update',
        'dossiers.treatment.view', 'dossiers.treatment.create', 'dossiers.treatment.update',
        'dossiers.treatment.schedule.create', 'dossiers.treatment.schedule.update', 'dossiers.treatment.administer', 'dossiers.treatment.dispense',
        'dossiers.treatment.administration.correct', 'dossiers.treatment.dispensing.correct',
        'clinics.view', 'doctors.view', 'catalog.view',
    ];

    // Catalog categories share catalog.directory.create. The stock directory
    // writers also accept the historical umbrella; remove it with its tasks.
    public const DIRECTORY_CREATION = [
        'clinics.create', 'doctors.directory.create', 'catalog.directory.create', 'diagnoses.create', 'medications.create',
        'stock.suppliers.write', 'stock.stores.write', 'stock.suppliers.manage',
    ];

    public function definitions(): array
    {
        $labels = array_merge(DossierWorkflowPermissionsSeeder::CODES, DossierCompletionPermissionsSeeder::CODES,
            DossierPathologyPermissionsSeeder::CODES, OncologyPermissionsSeeder::CODES,
            DoctorPermissionsSeeder::PERMISSIONS, CatalogPermissionsSeeder::PERMISSIONS, ['clinics.view' => 'استعراض العيادات']);
        foreach (TaskPermissions::TASKS as $code => [$label]) {
            $labels[$code] = $label;
        }

        return array_intersect_key($labels, array_flip(self::GRANTS));
    }

    /** Define only this contract's missing codes; preserve disabled definitions. */
    public function define(): void
    {
        foreach ($this->definitions() as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function reconcile(bool $apply, ?int $auditFacility, string $reference): array
    {
        return DB::transaction(function () use ($apply, $auditFacility, $reference) {
            // Same fence as role editors; increment their role version on change.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $roles = DB::table('roles')->orderBy('id')->lockForUpdate()->get();
            $permissions = DB::table('permissions')->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            DB::table('role_permissions')->orderBy('id')->lockForUpdate()->get(['id']);
            $role = $roles->first(fn ($r) => $r->code === 'data_entry');
            if (! $role || ! $role->is_active || $role->is_system_super_admin) {
                throw new \RuntimeException('An existing active ordinary data_entry role is required. No role or assignment was created or activated.');
            }
            if ($apply && (! $auditFacility || ! DB::table('facilities')->where('id', $auditFacility)->where('is_active', true)->exists())) {
                throw new \RuntimeException('Apply requires an existing active --facility for the operator audit. The shared role policy affects all its assignments.');
            }
            $blocked = array_filter(self::GRANTS, fn ($code) => isset($permissions[$code]) && ! $permissions[$code]->is_active);
            if ($blocked) {
                throw new \RuntimeException('Required definitions are disabled; no changes: '.implode(', ', $blocked));
            }
            $missing = array_values(array_diff(self::GRANTS, $permissions->keys()->all()));
            if (array_diff($missing, array_keys($this->definitions()))) {
                throw new \RuntimeException('The required permission definitions are not covered by the reviewed contract.');
            }
            $before = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.role_id', $role->id)->orderBy('p.code')->pluck('p.code')->all();
            $add = array_values(array_diff(self::GRANTS, $before));
            $remove = array_values(array_intersect(self::DIRECTORY_CREATION, $before));
            $after = array_values(array_unique(array_merge(array_diff($before, $remove), $add)));
            sort($after);
            if ($apply && ($add || $remove)) {
                $this->define();
                $ids = DB::table('permissions')->pluck('id', 'code');
                foreach ($add as $code) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $ids[$code], 'created_at' => now(), 'updated_at' => now()]);
                }
                DB::table('role_permissions')->where('role_id', $role->id)->whereIn('permission_id', array_map(fn ($code) => $ids[$code], $remove))->delete();
                DB::table('roles')->where('id', $role->id)->update(['lock_version' => $role->lock_version + 1, 'updated_at' => now()]);
                DB::table('audit_logs')->insert(['facility_id' => $auditFacility, 'actor_id' => null, 'entity_type' => 'role', 'entity_id' => $role->id, 'event' => 'updated',
                    'old_values' => json_encode(['permissions' => $before, 'lock_version' => $role->lock_version], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'new_values' => json_encode(['permissions' => $after, 'lock_version' => $role->lock_version + 1, 'added' => $add, 'removed' => $remove, 'source' => 'data_entry_card_permissions', 'execution_reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'reason' => $reference, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
            }

            return ['role_id' => $role->id, 'added' => $add, 'removed' => $remove, 'missing_definitions' => $missing, 'applied' => $apply && ($add || $remove)];
        }, 3);
    }
}
