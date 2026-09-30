<?php

namespace App\Services\Users;

use App\Services\Auth\TaskPermissions;
use Illuminate\Support\Facades\DB;

class PermissionCatalog
{
    public const TEMPLATES = [
        ['name_ar' => 'التسجيل والبيانات الأساسية', 'codes' => ['patients.basic.view', 'patients.basic.search', 'patients.basic.create', 'patient_cards.register', 'patients.own.correct', 'patients.corrections.request']],
        ['name_ar' => 'إدخال البيانات الطبية', 'codes' => ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.medical.update', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update', 'dossiers.services.update', 'dossiers.procedures.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update']],
        ['name_ar' => 'الطبيب', 'codes' => ['dossiers.medical.view', 'dossiers.visits.view', 'dossiers.visits.create', 'dossiers.visits.draft.update', 'dossiers.diagnoses.update', 'dossiers.prescriptions.update', 'dossiers.outcomes.update', 'dossiers.visits.complete']],
        ['name_ar' => 'مراجعة بيانات المرضى', 'codes' => ['patients.basic.view', 'identity_corrections.review', 'patients.identity.review', 'patient_duplicates.review', 'patients.duplicates.merge']],
        ['name_ar' => 'إدارة المشفى', 'codes' => ['users.view', 'users.create', 'roles.view', 'roles.create', 'roles.update', 'audit.view', 'settings.view', 'settings.update']],
    ];

    public const GROUPS = [
        ['key' => 'home', 'name_ar' => 'الرئيسية', 'prefixes' => ['dashboards.']],
        ['key' => 'registration', 'name_ar' => 'الهوية والتسجيل الأساسي', 'prefixes' => ['patients.basic.', 'patients.own.', 'patients.corrections.', 'patient_cards.', 'reception.']],
        ['key' => 'review', 'name_ar' => 'مراجعة بيانات المرضى', 'prefixes' => ['identity_corrections.', 'patients.identity.', 'patients.duplicates.', 'patient_duplicates.', 'reception_accounts.']],
        ['key' => 'dossiers', 'name_ar' => 'بطاقة المريض والعمل الطبي', 'prefixes' => ['dossiers.', 'patients.', 'diagnoses.', 'medications.']],
        ['key' => 'clinics', 'name_ar' => 'العيادات', 'prefixes' => ['clinics.']],
        ['key' => 'doctors', 'name_ar' => 'الأطباء', 'prefixes' => ['doctors.']],
        ['key' => 'catalog', 'name_ar' => 'الخدمات والإجراءات', 'prefixes' => ['catalog.']],
        ['key' => 'stock', 'name_ar' => 'المخزون', 'prefixes' => ['stock.']],
        ['key' => 'blood_bank', 'name_ar' => 'بنك الدم', 'prefixes' => ['blood_bank.']],
        ['key' => 'reports', 'name_ar' => 'التقارير', 'prefixes' => ['reports.']],
        ['key' => 'audit', 'name_ar' => 'سجل الحركة', 'prefixes' => ['audit.']],
        ['key' => 'users', 'name_ar' => 'المستخدمون والأدوار', 'prefixes' => ['users.', 'roles.']],
        ['key' => 'settings', 'name_ar' => 'إعدادات المشفى', 'prefixes' => ['settings.']],
        ['key' => 'statistics', 'name_ar' => 'الإحصاء المجهل', 'prefixes' => ['statistics.']],
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
                if (isset($used[$row->code])) {
                    continue;
                }
                foreach ($group['prefixes'] as $prefix) {
                    if (str_starts_with($row->code, $prefix)) {
                        $permissions[] = $this->present($row);
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
                $other[] = $this->present($row);
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
            $map[(int) $row->role_id][] = $this->present($row);
        }

        return $map;
    }

    public function within(array $owned, array $codes): bool
    {
        return array_diff($codes, $owned) === [];
    }

    public function present(object $row): array
    {
        return ['id' => (int) $row->id, 'code' => $row->code] + app(TaskPermissions::class)->describe($row->code, $row->name_ar);
    }

    public function missingPrerequisites(array $codes): array
    {
        $effective = array_unique([...app(TaskPermissions::class)->effective($codes), ...app(TaskPermissions::class)->effective($codes, true)]);
        $missing = [];
        foreach ($codes as $code) {
            // Newly granular tasks require explicit prerequisites; legacy role definitions remain compatible.
            foreach (TaskPermissions::TASKS[$code][2] ?? [] as $required) {
                if (! in_array($required, $effective, true)) {
                    $missing[] = $required;
                }
            }
        }

        return array_values(array_unique($missing));
    }
}
