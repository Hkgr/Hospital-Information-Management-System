<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierPathologyPermissionsSeeder extends Seeder
{
    public const CODES = [
        'dossiers.assessment.update' => 'تسجيل وتصحيح التقييم التشخيصي للزيارة',
        'dossiers.pathology.create' => 'إضافة تقرير أو حالة تشريح مرضي',
        'dossiers.pathology.update' => 'استكمال وتصحيح تقارير التشريح المرضي',
        'dossiers.pathology.void' => 'إلغاء تقرير تشريح مرضي مع حفظ تاريخه',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $label) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $label, 'is_active' => true]);
        }
    }
}
