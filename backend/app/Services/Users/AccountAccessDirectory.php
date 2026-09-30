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

        return ['id' => $id, 'username' => $user->username, 'name' => $user->name, 'is_active' => $user->is_active, 'lock_version' => (int) $user->lock_version,
            'local_role_ids' => $currentRoles,
            'assignable_roles' => app(RoleDirectory::class)->assignable($f),
            'global_permissions' => $canView ? $globalPermissions->all() : [],
            'global_permission_ids' => $canView ? $globalPermissions->whereIn('code', $globalCodes)->pluck('id')->all() : [],
            'global_effective_codes' => $globalCodes,
            'capabilities' => ['assign' => ! $protected && $user->is_active && $canAssign && in_array('users.roles.assign', $f['permissions'], true), 'global_view' => $canView,
                'global_manage' => ! $protected && $user->is_active && ProtectedRolePolicy::delegates($request->user()) && $global->allows($request->user(), 'users.global.manage')],
            'protected' => $protected];
    }

    public function update(Request $request, int $facility, int $id, array $input): array
    {
        return DB::transaction(function () use ($request, $facility, $id, $input) {
            ProtectedRolePolicy::lockActor($request);
            $user = $this->target($id, $facility, true);
            $actor = $request->user()->fresh();
            $request->setUserResolver(fn () => $actor);
            $f = app(UserAccess::class)->facility($actor, $facility);
            $old = $this->show($request, $facility, $id);
            if ($old['protected'] || $actor->id === $id || ! $user->is_active) {
                ProtectedRolePolicy::fail('PROTECTED_ACCOUNT_ACCESS', 'لا يمكن تغيير حساب محمي أو حسابك الحالي أو حساب معطل من هذا المسار.');
            }
            if ((int) $user->lock_version !== (int) $input['lock_version']) {
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
                DB::table('facility_user_roles')->where('user_id', $id)->where('facility_id', $facility)->delete();
                foreach ($input['local_role_ids'] as $role) {
                    DB::table('facility_user_roles')->insert(['user_id' => $id, 'facility_id' => $facility, 'role_id' => $role, 'created_at' => now(), 'updated_at' => now()]);
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
            $after = $this->show($request, $facility, $id);
            ProtectedRolePolicy::audit($request, $facility, 'auth_session', $id, $before + ['lock_version' => $user->lock_version], $after + [
                'local_roles' => DB::table('roles')->whereIn('id', $after['local_role_ids'])->orderBy('code')->pluck('code')->all(), 'permissions' => $after['global_effective_codes'],
            ], $input['reason']);

            return $after;
        }, 3);
    }
}
