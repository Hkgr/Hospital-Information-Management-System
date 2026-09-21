<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReportingPeriodPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'periods.view' => 'عرض الفترات التقريرية',
        'periods.close' => 'إنشاء الفترات ورفعها وإقفالها',
        'periods.reopen' => 'إعادة فتح فترة مقفلة',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
