<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FacilitySettingsPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $role = DB::table('roles')->where('code', 'hospital_admin')->first();
            if (! $role) {
                throw new \RuntimeException('Run PermissionMatrixPhaseOneSeeder before the facility settings seeder.');
            }
            foreach (['settings.view' => 'عرض إعدادات المنشأة', 'settings.update' => 'تعديل إعدادات المنشأة',
                'reception.patients.search' => 'البحث المحدود عن هوية المريض — يتطلب تفويضًا عالميًا',
                'reception.patients.create' => 'إنشاء هوية المريض — يتطلب تفويضًا عالميًا'] as $code => $name) {
                DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
                $permission = DB::table('permissions')->where('code', $code)->where('is_active', true)->value('id');
                if ($permission && $role->is_active) {
                    // Local assignability only. Never inserts global_user_roles.
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
