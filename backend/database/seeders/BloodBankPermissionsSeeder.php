<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BloodBankPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'blood_bank.view' => 'استعراض ملفات بنك الدم وتبرعات المنشأة',
        'blood_bank.export' => 'تصدير تقارير ملفات بنك الدم ووقائع التبرع في المنشأة',
        'blood_bank.create' => 'إضافة ملف متبرع أو مستفيد في المنشأة',
        'blood_bank.update' => 'تعديل ملفات بنك الدم في المنشأة',
        'blood_bank.donations.create' => 'تسجيل تبرع فعلي في المنشأة',
        'blood_bank.donations.update' => 'تصحيح بيانات تبرع فعلي في المنشأة',
        'blood_bank.benefits.create' => 'تسجيل صرف مكوّن أو نقل دم فعلي وربط المرحلتين في المنشأة',
        'blood_bank.benefits.update' => 'تصحيح واقعة صرف أو نقل دم في المنشأة',
        'blood_bank.patients.search' => 'البحث في سجل مرضى المشفى المشترك للربط ببنك الدم — تفويض عالمي',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
