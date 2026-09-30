<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class UserAccessContext
{
    /**
     * Build current access in bounded queries, including roles without permissions.
     *
     * @return list<array{facility: array{id: int, code: string, name_ar: string, timezone: string}, roles: list<array{code: string, name_ar: string, name_en: ?string}>, permissions: list<string>}>
     */
    public function forUser(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }
        if ($role = app(GlobalAccess::class)->systemRole($user)) {
            $permissions = app(GlobalAccess::class)->codes($user);

            return DB::table('facilities')->where('is_active', true)->orderBy('code')->orderBy('id')->get(['id', 'code', 'name_ar', 'timezone'])->map(fn ($f) => [
                'facility' => (array) $f, 'roles' => [['code' => $role->code, 'name_ar' => $role->name_ar, 'name_en' => $role->name_en]], 'permissions' => $permissions,
            ])->all();
        }
        $rows = DB::table('facility_user_roles as assignments')
            ->join('facilities as f', 'f.id', '=', 'assignments.facility_id')
            ->join('roles as r', 'r.id', '=', 'assignments.role_id')
            ->leftJoin('role_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->leftJoin('permissions as p', function (JoinClause $join) {
                $join->on('p.id', '=', 'rp.permission_id')->where('p.is_active', true);
            })
            ->where('assignments.user_id', $user->id)
            ->where('f.is_active', true)
            ->where('r.is_active', true)
            ->orderBy('f.code')->orderBy('f.id')->orderBy('r.code')->orderBy('p.code')
            ->get(['f.id as facility_id', 'f.code as facility_code', 'f.name_ar as facility_name',
                'f.timezone', 'r.code as role_code', 'r.name_ar as role_name_ar',
                'r.name_en as role_name_en', 'p.code as permission_code']);

        $access = [];
        foreach ($rows as $row) {
            $id = $row->facility_id;
            $access[$id] ??= [
                'facility' => ['id' => (int) $id, 'code' => $row->facility_code,
                    'name_ar' => $row->facility_name, 'timezone' => $row->timezone],
                'roles' => [], 'permissions' => [],
            ];
            $access[$id]['roles'][$row->role_code] = [
                'code' => $row->role_code, 'name_ar' => $row->role_name_ar, 'name_en' => $row->role_name_en,
            ];
            if ($row->permission_code !== null) {
                $access[$id]['permissions'][$row->permission_code] = $row->permission_code;
            }
        }

        $tasks = app(TaskPermissions::class);
        $activeTasks = $tasks->activeTasks();

        return array_values(array_map(function (array $entry) use ($tasks, $activeTasks): array {
            ksort($entry['roles'], SORT_STRING);
            ksort($entry['permissions'], SORT_STRING);
            $entry['roles'] = array_values($entry['roles']);
            $entry['permissions'] = $tasks->effective(array_values($entry['permissions']), false, $activeTasks);

            return $entry;
        }, $access));
    }
}
