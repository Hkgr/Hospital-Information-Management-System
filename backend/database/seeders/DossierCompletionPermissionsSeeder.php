<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierCompletionPermissionsSeeder extends Seeder
{
    public const CODES = [
        'dossiers.clinical.update' => 'تسجيل وتصحيح الخدمات والإجراءات والوصفة والنتيجة في زيارة مسودة',
        'dossiers.attachments.view' => 'عرض بيانات مرفقات زيارات الإضبارة',
        'dossiers.attachments.upload' => 'رفع مرفقات زيارة مسودة',
        'dossiers.attachments.download' => 'تنزيل مرفقات الإضبارة الخاصة',
        'dossiers.attachments.void' => 'إلغاء مرفق زيارة مسودة مع حفظ التاريخ',
        'dossiers.finalize' => 'تفعيل الإضبارة بعد مراجعتها صراحة',
        'dossiers.visits.complete' => 'إكمال الزيارة المسودة بعد مراجعتها صراحة',
        'dossiers.export' => 'تصدير الإضبارات والزيارات المصرح بها',
        'medications.create' => 'إضافة تعريف دواء إلى الدليل المشترك — تفويض عالمي',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
