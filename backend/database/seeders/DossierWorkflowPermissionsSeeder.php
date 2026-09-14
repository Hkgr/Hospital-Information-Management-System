<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierWorkflowPermissionsSeeder extends Seeder
{
    public const CODES = [
        'dossiers.create' => 'إنشاء إضبارة مسودة في المنشأة',
        'dossiers.personal.update' => 'تعديل البيانات الشخصية للإضبارة في المنشأة',
        'dossiers.medical.update' => 'تعديل المعلومات الطبية للإضبارة في المنشأة',
        'dossiers.visits.create' => 'تسجيل الزيارة الأولية للإضبارة في المنشأة',
        'dossiers.visits.update' => 'تعديل زيارة الإضبارة المسودة وتشخيصاتها',
        'patients.search' => 'البحث في سجل المرضى المشترك للإضبارات — تفويض عالمي',
        'patients.create' => 'إنشاء مريض في السجل المشترك — تفويض عالمي',
        'patients.update' => 'تعديل هوية المريض المشتركة — تفويض عالمي',
        'diagnoses.create' => 'إضافة تشخيص إلى الدليل المشترك — تفويض عالمي',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
