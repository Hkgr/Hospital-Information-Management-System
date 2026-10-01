<?php

namespace App\Services\Users;

use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleDirectory
{
    public const LEGACY_SEARCH_ROLE = 'hospital_admin_patient_search';

    public function __construct(private PermissionCatalog $catalog) {}

    public function listing(array $f): array
    {
        $roles = DB::table('roles')->where('is_active', true)->where('code', '!=', self::LEGACY_SEARCH_ROLE)->where('code', 'not like', 'delegation-%')->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar', 'is_system_super_admin', 'lock_version']);
        $permissions = $this->catalog->codesFor($roles->pluck('id')->all());
        $data = [];
        $globalRoles = DB::table('global_user_roles')->distinct()->pluck('role_id')->all();
        $minimum = DB::table('permissions')->where('is_active', true)->whereIn('code', ProtectedRolePolicy::MINIMUM)->get(['id', 'code', 'name_ar'])->map(fn ($row) => $this->catalog->present($row))->all();
        foreach ($roles as $role) {
            if ($role->is_system_super_admin) {
                $permissions[$role->id] = collect([...($permissions[$role->id] ?? []), ...$minimum])->unique('id')->sortBy('code')->values()->all();
            }
            $codes = array_column($permissions[$role->id] ?? [], 'code');
            $data[] = [
                'id' => (int) $role->id, 'code' => $role->code, 'name_ar' => $role->name_ar,
                'permissions' => $permissions[$role->id] ?? [], 'lock_version' => (int) $role->lock_version, 'protected' => (bool) $role->is_system_super_admin, 'locked_permissions' => $role->is_system_super_admin ? ProtectedRolePolicy::MINIMUM : [],
                'manageable' => $this->manageable($f, $role, $codes, in_array($role->id, $globalRoles, true)),
            ];
        }

        return ['data' => $data];
    }

    public function create(Request $request, array $f, array $input): array
    {
        return DB::transaction(function () use ($request, $f, $input) {
            ProtectedRolePolicy::lockActor($request);
            $f = app(RoleAccess::class)->facility($request->user(), $f['id'], 'create');
            $granted = $this->grantable($f, $input['permission_ids']);
            $code = $this->uniqueCode();
            $id = DB::table('roles')->insertGetId([
                'code' => $code, 'name_ar' => $input['name_ar'], 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->sync($id, array_keys($granted));
            app(ClinicAudit::class)->record($request, $f['id'], $id, 'created', null, ['code' => $code, 'name_ar' => $input['name_ar'], 'permissions' => array_values($granted)], 'role');

            return $this->present($f, $id);
        });
    }

    public function update(Request $request, array $f, int $id, array $input): array
    {
        return DB::transaction(function () use ($request, $f, $id, $input) {
            ProtectedRolePolicy::lockActor($request);
            $role = DB::table('roles')->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
            if (! $role) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'الدور غير موجود.']], 404));
            }
            $this->assertOrdinary($role);
            if ((int) $role->lock_version !== (int) $input['lock_version']) {
                ProtectedRolePolicy::fail('ROLE_VERSION_CONFLICT', 'تغير الدور؛ اجلب أحدث نسخة وراجع اختياراتك.', 409);
            }
            $f = app(RoleAccess::class)->facility($request->user(), $f['id'], 'update');
            if (! $f['can_manage_global_roles'] && DB::table('global_user_roles')->where('role_id', $id)->exists()) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'GLOBAL_ROLE_PROTECTED', 'message' => 'هذا الدور مستخدم في تفويض عالمي؛ تعديله يتطلب مسؤول النظام.']], 403));
            }
            $current = array_column($this->catalog->codesFor([$id])[$id] ?? [], 'code');
            if (! ($f['can_manage_global_roles'] ?? false) && ! $this->catalog->within($f['permissions'], $current)) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_NOT_MANAGEABLE', 'message' => 'لا يمكن تعديل دور يملك صلاحيات خارج صلاحياتك.']], 403));
            }
            $granted = $this->grantable($f, $input['permission_ids']);
            $old = ['name_ar' => $role->name_ar, 'permissions' => $current];
            DB::table('roles')->where('id', $id)->update(['name_ar' => $input['name_ar'], 'lock_version' => $role->lock_version + 1, 'updated_at' => now()]);
            $this->sync($id, array_keys($granted));
            ProtectedRolePolicy::audit($request, $f['id'], 'role', $id, $old + ['lock_version' => $role->lock_version], ['name_ar' => $input['name_ar'], 'permissions' => array_values($granted), 'lock_version' => $role->lock_version + 1], $input['reason']);

            return $this->present($f, $id);
        });
    }

    public function assignable(array $f): array
    {
        $roles = DB::table('roles')->where('is_active', true)->where('code', '!=', self::LEGACY_SEARCH_ROLE)->orderBy('code')->orderBy('id')->get(['id', 'code', 'name_ar', 'is_system_super_admin', 'lock_version']);
        $permissions = $this->catalog->codesFor($roles->pluck('id')->all());
        $assignable = [];
        foreach ($roles as $role) {
            $codes = array_column($permissions[$role->id] ?? [], 'code');
            if (! $role->is_system_super_admin && $role->code !== 'super_admin' && ! str_starts_with($role->code, 'reception-') && ! str_starts_with($role->code, 'delegation-') && $role->code !== 'full_access_user_1' && (($f['can_manage_global_roles'] ?? false) || $this->catalog->within($f['permissions'], $codes))) {
                $assignable[] = ['id' => (int) $role->id, 'code' => $role->code, 'name_ar' => $role->name_ar];
            }
        }

        return $assignable;
    }

    public function assertAssignable(array $f, int $roleId): object
    {
        $role = DB::table('roles')->where('id', $roleId)->where('is_active', true)->lockForUpdate()->first();
        if (! $role) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_ROLE_INVALID', 'message' => 'الدور المحدد غير متاح.']], 422));
        }
        $this->assertOrdinary($role);
        $codes = array_column($this->catalog->codesFor([$roleId])[$roleId] ?? [], 'code');
        if (! ($f['can_manage_global_roles'] ?? false) && ! $this->catalog->within($f['permissions'], $codes)) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_ROLE_INVALID', 'message' => 'الدور المحدد غير متاح.']], 422));
        }

        return $role;
    }

    private function assertOrdinary(object $role): void
    {
        if ($role->is_system_super_admin || $role->code === 'super_admin' || $role->code === self::LEGACY_SEARCH_ROLE || str_starts_with($role->code, 'reception-') || str_starts_with($role->code, 'delegation-') || $role->code === 'full_access_user_1') {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'PROTECTED_SYSTEM_ROLE', 'message' => 'هذا الدور محمي؛ لا يمكن تعديله أو إسناده من إدارة الأدوار العامة.']], 403));
        }
    }

    private function grantable(array $f, array $ids): array
    {
        $rows = DB::table('permissions')->whereIn('id', $ids)->where('is_active', true)->get(['id', 'code']);
        if ($rows->count() !== count($ids)) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_PERMISSIONS_INVALID', 'message' => 'إحدى الصلاحيات المحددة غير متاحة.']], 422));
        }
        $granted = [];
        foreach ($rows as $row) {
            $granted[(int) $row->id] = $row->code;
        }
        if (! ($f['can_manage_global_roles'] ?? false) && ! $this->catalog->within($f['permissions'], array_values($granted))) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_PERMISSIONS_INVALID', 'message' => 'لا يمكن منح صلاحية لا تملكها.']], 422));
        }
        if ($missing = $this->catalog->missingPrerequisites(array_values($granted))) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_PREREQUISITES_REQUIRED', 'message' => 'اختر المتطلبات الناقصة صراحة قبل حفظ الدور.', 'missing_permissions' => $missing]], 422));
        }

        return $granted;
    }

    private function sync(int $roleId, array $permissionIds): void
    {
        DB::table('role_permissions')->where('role_id', $roleId)->whereIn('permission_id', DB::table('permissions')->where('is_active', true)->select('id'))->whereNotIn('permission_id', $permissionIds)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'role_'.strtolower(Str::random(10));
        } while (DB::table('roles')->where('code', $code)->lockForUpdate()->exists());

        return $code;
    }

    public function detail(array $f, int $id): array
    {
        foreach ($this->listing($f)['data'] as $role) {
            if ($role['id'] === $id) {
                return $role;
            }
        }
        ProtectedRolePolicy::fail('ROLE_NOT_FOUND', 'الدور غير موجود.', 404);
    }

    private function present(array $f, int $id): array
    {
        return $this->detail($f, $id);
    }

    private function manageable(array $f, object $role, array $codes, bool $global): bool
    {
        if (! in_array('roles.update', $f['permissions'], true) || str_starts_with($role->code, 'reception-') || str_starts_with($role->code, 'delegation-') || $role->code === 'full_access_user_1') {
            return false;
        }
        if ($f['can_manage_global_roles'] ?? false) {
            return true;
        }

        return ! $role->is_system_super_admin && $role->code !== 'super_admin'
            && ! $global
            && $this->catalog->within($f['permissions'], $codes);
    }
}
