<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class UserAccessContext
{
    /**
     * Build current access in one query, including roles without permissions.
     *
     * @return list<array{facility: array{id: int, code: string, name_ar: string, timezone: string}, roles: list<array{code: string, name_ar: string, name_en: ?string}>, permissions: list<string>}>
     */
    public function forUser(User $user): array
    {
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

        return array_values(array_map(function (array $entry): array {
            ksort($entry['roles'], SORT_STRING);
            ksort($entry['permissions'], SORT_STRING);
            $entry['roles'] = array_values($entry['roles']);
            $entry['permissions'] = array_values($entry['permissions']);

            return $entry;
        }, $access));
    }
}
