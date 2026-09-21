<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierImportPermissionsSeeder extends Seeder
{
    public const CODES = [
        'dossiers.import.view' => 'عرض دفعات استيراد بطاقات المرضى',
        'dossiers.import.create' => 'رفع ملف استيراد بطاقات المرضى',
        'dossiers.import.validate' => 'فحص ومعاينة استيراد بطاقات المرضى',
        'dossiers.import.commit' => 'اعتماد استيراد بطاقات المرضى',
        'dossiers.import.download' => 'تنزيل قالب الاستيراد وتقرير أخطائه',
        'dossiers.import.cancel' => 'إلغاء دفعة استيراد بطاقات المرضى',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
