<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SurfacePermissionsSeeder extends Seeder
{
    public const CODES = [
        'dashboards.view' => 'عرض الصفحة الرئيسية',
        'reports.view' => 'عرض تقارير المنشأة',
        'reports.export' => 'تصدير تقارير المنشأة',
        'audit.view' => 'عرض سجل حركة النظام',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $name) {
            // Definitions only: never grant roles or reactivate an existing permission.
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
