<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OncologyPermissionsSeeder extends Seeder
{
    public const CODES = [
        'dossiers.treatment.view' => 'عرض الخطط العلاجية والجرعات',
        'dossiers.treatment.create' => 'إنشاء مسودة خطة علاجية',
        'dossiers.treatment.update' => 'تعديل مسودة وإضافة نسخة علاجية',
        'dossiers.treatment.activate' => 'تفعيل وإعادة اعتماد خطة علاجية',
        'dossiers.treatment.override' => 'اعتماد علاج باستثناء التشريح غير المطلوب',
        'dossiers.treatment.status' => 'إيقاف وإكمال وإلغاء خطة علاجية',
        'dossiers.treatment.schedule' => 'جدولة الجرعات وتصحيح مواعيدها',
        'dossiers.treatment.administer' => 'توثيق إعطاء جرعة فعلية',
        'dossiers.treatment.dispense' => 'توثيق صرف دواء مع الجرعة',
        'dossiers.treatment.correct' => 'تصحيح واقعة إعطاء أو صرف محفوظة',
        'dossiers.treatment.void' => 'إبطال واقعة إعطاء أو صرف بسبب صريح',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $label) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $label, 'is_active' => true]);
        }
    }
}
