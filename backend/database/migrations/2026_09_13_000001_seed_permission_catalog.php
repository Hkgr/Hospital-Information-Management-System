<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SHIPPED_PREFIXES = ['clinics.', 'doctors.'];

    public function up(): void
    {
        $now = now();
        $this->upsertPermissions($this->catalog(), $now);
        $this->upsertRoles($this->roles(), $now);
        $this->syncRolePermissions($now);
    }

    public function down(): void
    {
        $newCodes = array_keys($this->catalog());
        $newRoleCodes = array_values(array_filter(array_keys($this->roles()), fn (string $code) => $code !== 'super_admin'));

        DB::table('role_permissions')->whereIn('permission_id', DB::table('permissions')->whereIn('code', $newCodes)->select('id'))->delete();
        DB::table('role_permissions')->whereIn('role_id', DB::table('roles')->whereIn('code', $newRoleCodes)->select('id'))->delete();
        DB::table('roles')->whereIn('code', $newRoleCodes)->delete();
        DB::table('permissions')->whereIn('code', $newCodes)->delete();
    }

    /**
     * @return array<string, array{name_ar: string, name_en: string, display_order: int}>
     */
    private function catalog(): array
    {
        $modules = [
            'patients' => [10, [
                'view' => ['استعراض المرضى', 'View patients'],
                'create' => ['إضافة مريض', 'Create patient'],
                'update' => ['تعديل بيانات المريض', 'Update patient'],
                'merge' => ['دمج سجلات المرضى', 'Merge patients'],
                'export' => ['تصدير بيانات المرضى', 'Export patients'],
            ]],
            'visits' => [20, [
                'view' => ['استعراض الزيارات', 'View visits'],
                'create' => ['تسجيل زيارة', 'Create visit'],
                'update' => ['تعديل زيارة', 'Update visit'],
                'void' => ['إلغاء زيارة', 'Void visit'],
                'export' => ['تصدير الزيارات', 'Export visits'],
            ]],
            'clinical_events' => [30, [
                'view' => ['استعراض الأحداث السريرية', 'View clinical events'],
                'create' => ['تسجيل حدث سريري', 'Create clinical event'],
                'update' => ['تعديل حدث سريري', 'Update clinical event'],
                'void' => ['إلغاء حدث سريري', 'Void clinical event'],
            ]],
            'dose_sessions' => [40, [
                'view' => ['استعراض جلسات الجرعات', 'View dose sessions'],
                'create' => ['تسجيل جلسة جرعات', 'Create dose session'],
                'update' => ['تعديل جلسة جرعات', 'Update dose session'],
                'void' => ['إلغاء جلسة جرعات', 'Void dose session'],
            ]],
            'cancer_cases' => [50, [
                'view' => ['استعراض حالات السرطان', 'View cancer cases'],
                'create' => ['تسجيل حالة سرطان', 'Create cancer case'],
                'update' => ['تعديل حالة سرطان', 'Update cancer case'],
                'export' => ['تصدير حالات السرطان', 'Export cancer cases'],
            ]],
            'blood_bank' => [60, [
                'view' => ['استعراض بنك الدم', 'View blood bank'],
                'create' => ['تسجيل عملية بنك دم', 'Create blood bank record'],
                'update' => ['تعديل سجل بنك الدم', 'Update blood bank record'],
                'void' => ['إلغاء سجل بنك الدم', 'Void blood bank record'],
                'export' => ['تصدير سجلات بنك الدم', 'Export blood bank'],
            ]],
            'deaths' => [70, [
                'view' => ['استعراض الوفيات', 'View deaths'],
                'create' => ['تسجيل وفاة', 'Create death record'],
                'update' => ['تعديل سجل وفاة', 'Update death record'],
                'void' => ['إلغاء سجل وفاة', 'Void death record'],
            ]],
            'staff_workdays' => [80, [
                'view' => ['استعراض أيام عمل الكادر', 'View staff workdays'],
                'create' => ['تسجيل يوم عمل', 'Create staff workday'],
                'update' => ['تعديل يوم عمل', 'Update staff workday'],
            ]],
            'periods' => [90, [
                'view' => ['استعراض الفترات', 'View reporting periods'],
                'close' => ['إغلاق فترة', 'Close period'],
                'reopen' => ['إعادة فتح فترة', 'Reopen period'],
            ]],
            'corrections' => [100, [
                'view' => ['استعراض طلبات التصحيح', 'View correction requests'],
                'create' => ['إنشاء طلب تصحيح', 'Create correction request'],
                'review' => ['مراجعة طلب تصحيح', 'Review correction request'],
            ]],
            'reports' => [110, [
                'view' => ['استعراض التقارير', 'View reports'],
                'generate' => ['توليد تقرير', 'Generate report'],
                'submit' => ['تسليم تقرير', 'Submit report'],
                'export' => ['تصدير تقرير', 'Export report'],
            ]],
            'imports' => [120, [
                'view' => ['استعراض الاستيراد', 'View imports'],
                'create' => ['إنشاء عملية استيراد', 'Create import'],
                'apply' => ['تطبيق الاستيراد', 'Apply import'],
            ]],
            'audit' => [130, [
                'view' => ['استعراض سجل التدقيق', 'View audit log'],
            ]],
            'catalogs' => [200, [
                'view' => ['استعراض البيانات المرجعية', 'View reference catalogs'],
                'create' => ['إضافة بيان مرجعي', 'Create catalog item'],
                'update' => ['تعديل بيان مرجعي', 'Update catalog item'],
                'delete' => ['حذف بيان مرجعي', 'Delete catalog item'],
            ]],
            'report_definitions' => [210, [
                'view' => ['استعراض تعريفات التقارير', 'View report definitions'],
                'create' => ['إضافة تعريف تقرير', 'Create report definition'],
                'update' => ['تعديل تعريف تقرير', 'Update report definition'],
                'publish' => ['نشر تعريف تقرير', 'Publish report definition'],
            ]],
            'facilities' => [220, [
                'view' => ['استعراض المنشآت', 'View facilities'],
                'create' => ['إضافة منشأة', 'Create facility'],
                'update' => ['تعديل منشأة', 'Update facility'],
            ]],
            'users' => [230, [
                'view' => ['استعراض المستخدمين', 'View users'],
                'create' => ['إضافة مستخدم', 'Create user'],
                'update' => ['تعديل مستخدم', 'Update user'],
                'deactivate' => ['تعطيل مستخدم', 'Deactivate user'],
            ]],
            'roles' => [240, [
                'view' => ['استعراض الأدوار', 'View roles'],
                'update' => ['تعديل صلاحيات الدور', 'Update role permissions'],
            ]],
        ];

        $catalog = [];
        foreach ($modules as $resource => [$base, $actions]) {
            $offset = 0;
            foreach ($actions as $action => [$nameAr, $nameEn]) {
                $catalog[$resource.'.'.$action] = [
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'display_order' => $base + $offset,
                ];
                $offset++;
            }
        }

        return $catalog;
    }

    /**
     * @return array<string, array{name_ar: string, name_en: string, display_order: int}>
     */
    private function roles(): array
    {
        return [
            'super_admin' => ['name_ar' => 'مدير النظام', 'name_en' => 'System administrator', 'display_order' => 10],
            'directory_admin' => ['name_ar' => 'مدير البيانات المرجعية', 'name_en' => 'Reference data administrator', 'display_order' => 20],
            'facility_admin' => ['name_ar' => 'مدير المنشأة', 'name_en' => 'Facility administrator', 'display_order' => 30],
            'supervisor' => ['name_ar' => 'مشرف', 'name_en' => 'Supervisor', 'display_order' => 40],
            'data_entry' => ['name_ar' => 'مدخل بيانات', 'name_en' => 'Data entry clerk', 'display_order' => 50],
            'viewer' => ['name_ar' => 'مطّلع', 'name_en' => 'Viewer', 'display_order' => 60],
        ];
    }

    /**
     * @param  array<string, array{name_ar: string, name_en: string, display_order: int}>  $catalog
     */
    private function upsertPermissions(array $catalog, $now): void
    {
        foreach ($catalog as $code => $row) {
            if ($this->isShipped($code)) {
                continue;
            }

            $existing = DB::table('permissions')->where('code', $code)->first();
            $fields = [
                'name_ar' => $row['name_ar'],
                'name_en' => $row['name_en'],
                'display_order' => $row['display_order'],
                'is_active' => true,
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('permissions')->where('id', $existing->id)->update($fields);
            } else {
                DB::table('permissions')->insert($fields + ['code' => $code, 'created_at' => $now]);
            }
        }
    }

    /**
     * @param  array<string, array{name_ar: string, name_en: string, display_order: int}>  $roles
     */
    private function upsertRoles(array $roles, $now): void
    {
        foreach ($roles as $code => $row) {
            $existing = DB::table('roles')->where('code', $code)->first();
            if ($existing) {
                if ($code !== 'super_admin') {
                    DB::table('roles')->where('id', $existing->id)->update([
                        'name_ar' => $row['name_ar'],
                        'name_en' => $row['name_en'],
                        'display_order' => $row['display_order'],
                        'is_active' => true,
                        'updated_at' => $now,
                    ]);
                }

                continue;
            }

            DB::table('roles')->insert([
                'code' => $code,
                'name_ar' => $row['name_ar'],
                'name_en' => $row['name_en'],
                'display_order' => $row['display_order'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function syncRolePermissions($now): void
    {
        $permissions = DB::table('permissions')->get(['id', 'code']);
        $codes = $permissions->pluck('code')->all();
        $ids = $permissions->pluck('id', 'code')->all();
        $facility = array_values(array_filter($codes, fn (string $code) => ! $this->isGlobal($code)));
        $global = array_values(array_filter($codes, fn (string $code) => $this->isGlobal($code)));
        $writable = ['patients', 'visits', 'clinical_events', 'dose_sessions', 'cancer_cases', 'blood_bank', 'deaths', 'staff_workdays'];

        $grants = [
            'super_admin' => $codes,
            'directory_admin' => $global,
            'facility_admin' => $facility,
            'supervisor' => array_values(array_filter($facility, fn (string $code) => str_ends_with($code, '.view')
                || str_ends_with($code, '.export')
                || str_ends_with($code, '.void')
                || str_starts_with($code, 'reports.')
                || in_array($code, ['periods.close', 'corrections.review', 'patients.merge'], true))),
            'data_entry' => array_values(array_filter($facility, function (string $code) use ($writable) {
                [$resource, $action] = explode('.', $code, 2);

                return (in_array($resource, $writable, true) && in_array($action, ['view', 'create', 'update'], true))
                    || $code === 'corrections.create'
                    || $code === 'periods.view';
            })),
            'viewer' => array_values(array_filter($facility, fn (string $code) => str_ends_with($code, '.view') || str_ends_with($code, '.export'))),
        ];

        $roleIds = DB::table('roles')->whereIn('code', array_keys($grants))->pluck('id', 'code');

        foreach ($grants as $role => $granted) {
            $roleId = $roleIds[$role] ?? null;
            if ($roleId === null) {
                continue;
            }

            foreach ($granted as $code) {
                $permissionId = $ids[$code] ?? null;
                if ($permissionId === null) {
                    continue;
                }

                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function isShipped(string $code): bool
    {
        foreach (self::SHIPPED_PREFIXES as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isGlobal(string $code): bool
    {
        foreach (['doctors.directory.', 'catalogs.', 'report_definitions.', 'facilities.', 'users.', 'roles.'] as $prefix) {
            if (str_starts_with($code, $prefix)) {
                return true;
            }
        }

        return false;
    }
};
