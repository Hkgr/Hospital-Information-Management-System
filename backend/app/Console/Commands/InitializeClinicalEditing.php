<?php

namespace App\Console\Commands;

use App\Services\Auth\TaskPermissions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitializeClinicalEditing extends Command
{
    public const KEY = 'clinical_edit_initialization_v1';

    public const UPDATES = ['dossiers.medical.update', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update', 'dossiers.services.update', 'dossiers.procedures.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update', 'dossiers.pathology.update'];

    public const TREATMENT = ['dossiers.treatment.update', 'dossiers.treatment.schedule.update', 'dossiers.treatment.administration.correct', 'dossiers.treatment.dispensing.correct'];

    public const LEGACY = ['dossiers.view', 'dossiers.visits.update', 'dossiers.clinical.update', 'dossiers.treatment.schedule', 'dossiers.treatment.correct', 'dossiers.treatment.void'];

    protected $signature = 'patient-cards:initialize-editing {--apply : Apply once to existing relevant roles} {--execution-reference= : Operator reference required for apply}';

    protected $description = 'One-time audited clinical editing initialization; repeat runs never restore revoked permissions';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $reference = trim((string) $this->option('execution-reference'));
        if ($apply && (strlen($reference) < 3 || strlen($reference) > 255)) {
            $this->error('A 3-255 character --execution-reference is required.');

            return self::FAILURE;
        }
        $result = DB::transaction(function () use ($apply, $reference) {
            // Same authorization fence as role/account editors, before reads.
            DB::table('users')->orderBy('id')->lockForUpdate()->get(['id']);
            $roles = DB::table('roles')->orderBy('id')->lockForUpdate()->get();
            $definitions = DB::table('permissions')->orderBy('id')->lockForUpdate()->get()->keyBy('code');
            DB::table('role_permissions')->orderBy('id')->lockForUpdate()->get(['id']);
            $assignments = DB::table('facility_user_roles as a')->join('facilities as f', 'f.id', '=', 'a.facility_id')->join('users as u', 'u.id', '=', 'a.user_id')
                ->where('f.is_active', true)->where('u.is_active', true)->orderBy('a.id')->lockForUpdate()->get(['a.*']);
            $global = DB::table('global_user_roles as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('u.is_active', true)->orderBy('g.id')->lockForUpdate()->get(['g.*']);
            foreach ($roles->where('is_active', true)->where('is_system_super_admin', true) as $protected) {
                if ($global->contains('role_id', $protected->id)) {
                    foreach (DB::table('facilities')->where('is_active', true)->pluck('id') as $facility) {
                        $assignments->push((object) ['role_id' => $protected->id, 'facility_id' => $facility]);
                    }
                }
            }
            if (DB::table('audit_logs')->where('entity_type', self::KEY)->lockForUpdate()->exists()) {
                return ['done' => true, 'plan' => [], 'errors' => []];
            }
            $plan = [];
            $errors = [];
            $tasks = app(TaskPermissions::class);
            foreach ($roles as $role) {
                $scopes = $assignments->where('role_id', $role->id);
                if (! $role->is_active || $scopes->isEmpty()) {
                    continue;
                }
                $old = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role->id)->where('p.is_active', true)->pluck('p.code')->all();
                // Protected policies are exact; ordinary historical aliases are expanded once.
                $effective = $role->is_system_super_admin ? $old : $tasks->effective($old);
                if (! in_array('dossiers.medical.view', $effective, true)) {
                    continue;
                }
                $wanted = array_unique([...$effective, ...self::UPDATES, 'dossiers.visits.view']);
                if (in_array('dossiers.treatment.view', $effective, true)) {
                    $wanted = array_unique([...$wanted, ...self::TREATMENT]);
                }
                // Remove coarse aliases only while materializing every existing
                // effective task, so later granular revocation actually works.
                $remove = $role->is_system_super_admin ? [] : array_intersect($old, self::LEGACY);
                $wanted = array_values(array_diff($wanted, $remove));
                $queue = $wanted;
                $seen = [];
                while ($queue) {
                    $code = array_pop($queue);
                    if (isset($seen[$code])) {
                        continue;
                    }
                    $seen[$code] = true;
                    foreach ($tasks->describe($code, $code)['prerequisites'] as $required) {
                        if (! in_array($required, $wanted, true)) {
                            $wanted[] = $required;
                            $queue[] = $required;
                        }
                    }
                }
                foreach ($wanted as $code) {
                    if (! ($definitions[$code]->is_active ?? false)) {
                        $errors[] = ['role' => $role->id, 'missing_or_disabled' => $code];
                    }
                }
                $plan[] = ['role_id' => $role->id, 'name' => $role->name_ar, 'before' => $old, 'add' => array_values(array_diff($wanted, $old)), 'remove' => array_values($remove), 'facilities' => $scopes->pluck('facility_id')->unique()->values()->all()];
            }
            if ($apply && ! $errors && $plan) {
                foreach ($plan as $entry) {
                    foreach ($entry['add'] as $code) {
                        DB::table('role_permissions')->insertOrIgnore(['role_id' => $entry['role_id'], 'permission_id' => $definitions[$code]->id, 'created_at' => now(), 'updated_at' => now()]);
                    }
                    DB::table('role_permissions')->where('role_id', $entry['role_id'])->whereIn('permission_id', array_map(fn ($code) => $definitions[$code]->id, $entry['remove']))->delete();
                    DB::table('roles')->where('id', $entry['role_id'])->increment('lock_version');
                    foreach ($entry['facilities'] as $facility) {
                        $this->audit($facility, 'role', $entry['role_id'], ['permissions' => $entry['before']], $entry, $reference);
                    }
                }
                // Audit is append-only and preserved on rollback. The role fence
                // serializes this marker check, including concurrent CLI runs.
                $this->audit($plan[0]['facilities'][0], self::KEY, 0, null, ['roles' => array_column($plan, 'role_id')], $reference);
            }

            return ['done' => false, 'plan' => $plan, 'errors' => $errors];
        }, 3);
        if ($result['done']) {
            $this->info('Already initialized. Revoked permissions remain revoked; no changes.');

            return self::SUCCESS;
        }
        $this->line($apply ? 'APPLY (atomic; no changes if prerequisites unavailable)' : 'DRY RUN: no grants or audit changed');
        $this->table(['Role', 'Name', 'Add', 'Replace legacy aliases', 'Facility scopes'], array_map(fn ($p) => [$p['role_id'], $p['name'], implode(', ', $p['add']), implode(', ', $p['remove']), implode(', ', $p['facilities'])], $result['plan']));
        $this->table(['Role', 'Unavailable prerequisite'], $result['errors']);

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }

    private function audit(int $facility, string $type, int $id, ?array $old, array $new, string $reference): void
    {
        DB::table('audit_logs')->insert(['facility_id' => $facility, 'actor_id' => null, 'entity_type' => $type, 'entity_id' => $id, 'event' => 'updated', 'old_values' => $old ? json_encode($old, JSON_THROW_ON_ERROR) : null,
            'new_values' => json_encode($new + ['source' => self::KEY, 'execution_reference' => $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'reason' => $reference, 'request_id' => (string) Str::uuid(), 'occurred_at' => now()]);
    }
}
