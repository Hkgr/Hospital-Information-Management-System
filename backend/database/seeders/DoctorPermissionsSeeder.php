<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DoctorPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'doctors.view' => 'استعراض دليل الأطباء ومؤشرات المنشأة',
        'doctors.link' => 'ربط الأطباء بعيادات المنشأة',
        'doctors.export' => 'تصدير تقارير الأطباء في المنشأة',
        'doctors.directory.create' => 'إضافة طبيب إلى الدليل المشترك',
        'doctors.directory.update' => 'تعديل وتعطيل طبيب عالميًا',
        'doctors.directory.delete' => 'حذف طبيب غير مرتبط من الدليل المشترك',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
