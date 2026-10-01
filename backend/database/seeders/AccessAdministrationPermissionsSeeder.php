<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessAdministrationPermissionsSeeder extends Seeder
{
    public const DEFINITIONS = [
        'roles.delegate' => 'إدارة التفويض دون اشتراط امتلاك الصلاحية التشغيلية — تفويض عالمي',
        'users.global.view' => 'عرض التفويضات العالمية للحساب — تفويض عالمي',
        'users.global.manage' => 'تعديل التفويضات العالمية للحساب — تفويض عالمي',
        'users.roles.assign' => 'إسناد الأدوار المحلية للحساب داخل المشفى',
    ];

    public function run(): void
    {
        foreach (self::DEFINITIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
