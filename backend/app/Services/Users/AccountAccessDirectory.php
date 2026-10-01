<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Auth\GlobalAccess;
use App\Services\Auth\TaskPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountAccessDirectory
{
    private function target(int $id, int $facility, bool $lock = false): User
    {
        $query = User::whereKey($id);
        $user = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $user || ! DB::table('facility_user_roles')->where('user_id', $id)->where('facility_id', $facility)->exists()) {
            ProtectedRolePolicy::fail('USER_NOT_FOUND', 'المستخدم غير موجود في المشفى المحدد.', 404);
        }

        return $user;
    }

    public function show(Request $request, int $facility, int $id): array
    {
        return DB::transaction(function () use ($request, $facility, $id) {
            ProtectedRolePolicy::lockActor($request, [$id]);

            return $this->present($request, $facility, $id);
        }, 3);
    }

    private function present(Request $request, int $facility, int $id): array
    {
        $f = app(UserAccess::class)->facility($request->user(), $facility);
        $user = $this->target($id, $facility);
        $global = app(GlobalAccess::class);
        $canView = $global->allows($request->user(), 'users.global.view');
        $protected = $id === 1 || DB::table('roles as r')->where(fn ($q) => $q->where('r.is_system_super_admin', true)->orWhere('r.code', 'super_admin'))
            ->where(fn ($q) => $q->whereIn('r.id', DB::table('global_user_roles')->where('user_id', $id)->select('role_id'))
                ->orWhereIn('r.id', DB::table('facility_user_roles')->where('user_id', $id)->select('role_id')))->exists();
        $globalCodes = $canView ? $global->codes($user) : [];
        $globalPermissions = collect(app(PermissionCatalog::class)->grouped())->pluck('permissions')->flatten(1)->where('scope', 'global')->values();
        $currentRoles = DB::table('facility_user_roles as a')->join('roles as r', 'r.id', '=', 'a.role_id')->where('a.user_id', $id)->where('a.facility_id', $facility)->where('r.is_active', true)->pluck('r.id')->all();
        $currentCodes = collect(app(PermissionCatalog::class)->codesFor($currentRoles))->flatten(1)->pluck('code')->unique()->all();
        $canAssign = ($f['can_manage_global_roles'] ?? false) || app(PermissionCatalog::class)->within($f['permissions'], $currentCodes);
        $assignable = app(RoleDirectory::class)->assignable($f);
        $rolePermissions = app(PermissionCatalog::class)->codesFor(array_column($assignable, 'id'));
        $assignable = array_map(fn ($role) => $role + ['permissions' => $rolePermissions[$role['id']] ?? []], $assignable);

        return ['id' => $id, 'username' => $user->username, 'name' => $user->name, 'is_active' => $user->is_active, 'lock_version' => (int) $user->lock_version,
            'access_fingerprint' => $this->fingerprint($request, $user, $facility, $assignable),
            'local_role_ids' => $currentRoles,
            'assignable_roles' => $assignable,
            'global_permissions' => $canView ? $globalPermissions->all() : [],
            'global_permission_ids' => $canView ? $globalPermissions->whereIn('code', $globalCodes)->pluck('id')->all() : [],
            'global_effective_codes' => $globalCodes,
            'capabilities' => ['assign' => ! $protected && $user->is_active && $canAssign && in_array('users.roles.assign', $f['permissions'], true), 'global_view' => $canView,
                'global_manage' => ! $protected && $user->is_active && ProtectedRolePolicy::delegates($request->user()) && $global->allows($request->user(), 'users.global.manage')],
            'protected' => $protected];
    }

    private function fingerprint(Request $request, User $user, int $facility, array $assignable): string
    {
        // Include inactive historical assignments and every candidate shown in
        // the picker, so selecting an as-yet unassigned role is also reviewed.
        $local = DB::table('facility_user_roles')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
        $global = DB::table('global_user_roles')->where('user_id', $user->id)->orderBy('id')->lockForUpdate()->get();
        $ids = $local->pluck('role_id')->merge($global->pluck('role_id'))->merge(array_column($assignable, 'id'))->unique()->all();
        $state = [
            'actor' => $request->user()->id, 'user' => [$user->id, (int) $user->lock_version, $user->is_active], 'facility' => $facility,
            'local' => $local, 'global' => $global,
            'roles' => DB::table('roles')->whereIn('id', $ids)->orderBy('id')->get(),
            'grants' => DB::table('role_permissions')->whereIn('role_id', $ids)->orderBy('id')->get(),
            'definitions' => DB::table('permissions')->orderBy('id')->get(['id', 'code', 'name_ar', 'is_active']),
        ];

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function update(Request $request, int $facility, int $id, array $input): array
    {
        return DB::transaction(function () use ($request, $facility, $id, $input) {
            ProtectedRolePolicy::lockActor($request, [$id]);
            $user = $this->target($id, $facility, true);
            $actor = $request->user()->fresh();
            $request->setUserResolver(fn () => $actor);
            $f = app(UserAccess::class)->facility($actor, $facility);
            $old = $this->present($request, $facility, $id);
            if ($old['protected'] || $actor->id === $id || ! $user->is_active) {
                ProtectedRolePolicy::fail('PROTECTED_ACCOUNT_ACCESS', 'لا يمكن تغيير حساب محمي أو حسابك الحالي أو حساب معطل من هذا المسار.');
            }
            if ((int) $user->lock_version !== (int) $input['lock_version'] || ! hash_equals($old['access_fingerprint'], $input['access_fingerprint'])) {
                ProtectedRolePolicy::fail('USER_ACCESS_CONFLICT', 'تغير وصول الحساب؛ اجلب أحدث نسخة وراجع الفرق.', 409);
            }
            $before = ['local' => DB::table('facility_user_roles')->where('user_id', $id)->get()->all(), 'global' => DB::table('global_user_roles')->where('user_id', $id)->get()->all(),
                'local_roles' => DB::table('roles')->whereIn('id', $old['local_role_ids'])->orderBy('code')->pluck('code')->all(), 'permissions' => $old['global_effective_codes']];
            if (array_key_exists('local_role_ids', $input)) {
                if (! $old['capabilities']['assign']) {
                    ProtectedRolePolicy::fail('ROLE_ASSIGN_DENIED', 'إسناد الدور المحلي يحتاج صلاحية مستقلة.');
                }
                foreach ($input['local_role_ids'] as $role) {
                    app(RoleDirectory::class)->assertAssignable($f, $role);
                }
                // Disabled role memberships are historical, not editable active
                // choices. Retain them as well as unchanged active assignments.
                DB::table('facility_user_roles')->where('user_id', $id)->where('facility_id', $facility)
                    ->whereIn('role_id', $old['local_role_ids'])->whereNotIn('role_id', $input['local_role_ids'])->delete();
                foreach ($input['local_role_ids'] as $role) {
                    DB::table('facility_user_roles')->insertOrIgnore(['user_id' => $id, 'facility_id' => $facility, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            if (array_key_exists('global_permission_ids', $input)) {
                if (! $old['capabilities']['global_manage']) {
                    ProtectedRolePolicy::fail('GLOBAL_DELEGATION_DENIED', 'تعديل التفويض العالمي يحتاج مسؤول التفويض وصلاحياته المستقلة.');
                }
                $permissions = DB::table('permissions')->whereIn('id', $input['global_permission_ids'])->where('is_active', true)->lockForUpdate()->get();
                if ($permissions->count() !== count($input['global_permission_ids'])) {
                    ProtectedRolePolicy::fail('GLOBAL_PERMISSION_INVALID', 'إحدى الصلاحيات معطلة أو غير موجودة.', 422);
                }
                foreach ($permissions as $permission) {
                    if (app(TaskPermissions::class)->describe($permission->code, $permission->name_ar)['scope'] !== 'global' || in_array($permission->code, ['roles.delegate', 'users.global.manage'], true)) {
                        ProtectedRolePolicy::fail('GLOBAL_PERMISSION_INVALID', 'اختر تفويضًا عالميًا تشغيليًا؛ سلطة إدارة التفويض محمية.', 422);
                    }
                }
                $code = 'delegation-'.$id;
                DB::table('roles')->insertOrIgnore(['code' => $code, 'name_ar' => 'تفويض عالمي محدد للحساب '.$id, 'created_at' => now(), 'updated_at' => now()]);
                $role = DB::table('roles')->where('code', $code)->lockForUpdate()->first();
                if (! $role->is_active || $role->is_system_super_admin || DB::table('global_user_roles')->where('role_id', $role->id)->where('user_id', '!=', $id)->exists() || DB::table('facility_user_roles')->where('role_id', $role->id)->exists()) {
                    ProtectedRolePolicy::fail('GLOBAL_ROLE_CONFLICT', 'تفويض الحساب معطل أو مرتبط بحساب آخر؛ يلزم مراجعة المسؤول.', 409);
                }
                $before['global_role_permissions'] = DB::table('role_permissions')->whereIn('role_id', array_column($before['global'], 'role_id'))->get()->all();
                DB::table('role_permissions')->where('role_id', $role->id)->delete();
                foreach ($input['global_permission_ids'] as $permission) {
                    DB::table('role_permissions')->insert(['role_id' => $role->id, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
                }
                // An explicit replacement, shown as a diff; never copy the local role.
                DB::table('global_user_roles')->where('user_id', $id)->delete();
                DB::table('global_user_roles')->insert(['user_id' => $id, 'role_id' => $role->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('users')->where('id', $id)->update(['lock_version' => $user->lock_version + 1]);
            $after = $this->present($request, $facility, $id);
            ProtectedRolePolicy::audit($request, $facility, 'auth_session', $id, $before + ['lock_version' => $user->lock_version], $after + [
                'local_roles' => DB::table('roles')->whereIn('id', $after['local_role_ids'])->orderBy('code')->pluck('code')->all(), 'permissions' => $after['global_effective_codes'],
            ], $input['reason']);

            return $after;
        }, 3);
    }
}
