<?php

namespace App\Services\Users;

use Illuminate\Support\Facades\DB;

class PermissionCatalog
{
    public const GROUPS = [
        ['key' => 'home', 'name_ar' => 'الرئيسية', 'prefixes' => ['dashboards.']],
        ['key' => 'dossiers', 'name_ar' => 'بطاقة المريض', 'prefixes' => ['dossiers.', 'patients.', 'diagnoses.', 'medications.']],
        ['key' => 'clinics', 'name_ar' => 'العيادات', 'prefixes' => ['clinics.']],
        ['key' => 'doctors', 'name_ar' => 'الأطباء', 'prefixes' => ['doctors.']],
        ['key' => 'catalog', 'name_ar' => 'الخدمات والإجراءات', 'prefixes' => ['catalog.']],
        ['key' => 'stock', 'name_ar' => 'المخزون', 'prefixes' => ['stock.']],
        ['key' => 'blood_bank', 'name_ar' => 'بنك الدم', 'prefixes' => ['blood_bank.']],
        ['key' => 'reports', 'name_ar' => 'التقارير', 'prefixes' => ['reports.']],
        ['key' => 'audit', 'name_ar' => 'سجل الحركة', 'prefixes' => ['audit.']],
        ['key' => 'users', 'name_ar' => 'المستخدمون والأدوار', 'prefixes' => ['users.', 'roles.']],
    ];

    public function grouped(?array $onlyCodes = null): array
    {
        $q = DB::table('permissions')->where('is_active', true)->orderBy('code')->orderBy('id');
        if ($onlyCodes !== null) {
            $q->whereIn('code', $onlyCodes ?: ['']);
        }
        $rows = $q->get(['id', 'code', 'name_ar']);
        $used = [];
        $grouped = [];
        foreach (self::GROUPS as $group) {
            $permissions = [];
            foreach ($rows as $row) {
                foreach ($group['prefixes'] as $prefix) {
                    if (str_starts_with($row->code, $prefix)) {
                        $permissions[] = ['id' => (int) $row->id, 'code' => $row->code, 'name_ar' => $row->name_ar];
                        $used[$row->code] = true;
                        break;
                    }
                }
            }
            if ($permissions) {
                $grouped[] = ['key' => $group['key'], 'name_ar' => $group['name_ar'], 'permissions' => $permissions];
            }
        }
        $other = [];
        foreach ($rows as $row) {
            if (! isset($used[$row->code])) {
                $other[] = ['id' => (int) $row->id, 'code' => $row->code, 'name_ar' => $row->name_ar];
            }
        }
        if ($other) {
            $grouped[] = ['key' => 'other', 'name_ar' => 'أخرى', 'permissions' => $other];
        }

        return $grouped;
    }

    public function codesFor(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $rows = DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.role_id', $roleIds)->where('p.is_active', true)
            ->orderBy('p.code')->get(['rp.role_id', 'p.id', 'p.code', 'p.name_ar']);
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->role_id][] = ['id' => (int) $row->id, 'code' => $row->code, 'name_ar' => $row->name_ar];
        }

        return $map;
    }

    public function within(array $owned, array $codes): bool
    {
        return array_diff($codes, $owned) === [];
    }
}
