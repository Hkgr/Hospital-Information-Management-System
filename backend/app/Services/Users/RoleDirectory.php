<?php

namespace App\Services\Users;

use App\Services\Clinics\ClinicAudit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleDirectory
{
    public function __construct(private PermissionCatalog $catalog) {}

    public function listing(array $f): array
    {
        $roles = DB::table('roles')->where('is_active', true)->orderBy('name_ar')->orderBy('id')->get(['id', 'code', 'name_ar']);
        $permissions = $this->catalog->codesFor($roles->pluck('id')->all());
        $data = [];
        foreach ($roles as $role) {
            $codes = array_column($permissions[$role->id] ?? [], 'code');
            $data[] = [
                'id' => (int) $role->id, 'code' => $role->code, 'name_ar' => $role->name_ar,
                'permissions' => $permissions[$role->id] ?? [],
                'manageable' => $this->catalog->within($f['permissions'], $codes),
            ];
        }

        return ['data' => $data];
    }

    public function create(Request $request, array $f, array $input): array
    {
        return DB::transaction(function () use ($request, $f, $input) {
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
            $role = DB::table('roles')->where('id', $id)->where('is_active', true)->lockForUpdate()->first();
            if (! $role) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_NOT_FOUND', 'message' => 'الدور غير موجود.']], 404));
            }
            $current = array_column($this->catalog->codesFor([$id])[$id] ?? [], 'code');
            if (! $this->catalog->within($f['permissions'], $current)) {
                throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_NOT_MANAGEABLE', 'message' => 'لا يمكن تعديل دور يملك صلاحيات خارج صلاحياتك.']], 403));
            }
            $granted = $this->grantable($f, $input['permission_ids']);
            $old = ['name_ar' => $role->name_ar, 'permissions' => $current];
            DB::table('roles')->where('id', $id)->update(['name_ar' => $input['name_ar'], 'updated_at' => now()]);
            $this->sync($id, array_keys($granted));
            app(ClinicAudit::class)->record($request, $f['id'], $id, 'updated', $old, ['name_ar' => $input['name_ar'], 'permissions' => array_values($granted)], 'role');

            return $this->present($f, $id);
        });
    }

    public function assignable(array $f): array
    {
        $roles = DB::table('roles')->where('is_active', true)->orderBy('code')->orderBy('id')->get(['id', 'code', 'name_ar']);
        $permissions = $this->catalog->codesFor($roles->pluck('id')->all());
        $assignable = [];
        foreach ($roles as $role) {
            $codes = array_column($permissions[$role->id] ?? [], 'code');
            if ($this->catalog->within($f['permissions'], $codes)) {
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
        $codes = array_column($this->catalog->codesFor([$roleId])[$roleId] ?? [], 'code');
        if (! $this->catalog->within($f['permissions'], $codes)) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'USER_ROLE_INVALID', 'message' => 'الدور المحدد غير متاح.']], 422));
        }

        return $role;
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
        if (! $this->catalog->within($f['permissions'], array_values($granted))) {
            throw new HttpResponseException(response()->json(['error' => ['code' => 'ROLE_PERMISSIONS_INVALID', 'message' => 'لا يمكن منح صلاحية لا تملكها.']], 422));
        }

        return $granted;
    }

    private function sync(int $roleId, array $permissionIds): void
    {
        DB::table('role_permissions')->where('role_id', $roleId)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'role_'.strtolower(Str::random(10));
        } while (DB::table('roles')->where('code', $code)->lockForUpdate()->exists());

        return $code;
    }

    private function present(array $f, int $id): array
    {
        $role = DB::table('roles')->where('id', $id)->first();
        $permissions = $this->catalog->codesFor([$id])[$id] ?? [];

        return [
            'id' => (int) $role->id, 'code' => $role->code, 'name_ar' => $role->name_ar,
            'permissions' => $permissions,
            'manageable' => $this->catalog->within($f['permissions'], array_column($permissions, 'code')),
        ];
    }
}
