<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class MedicationStockPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'stock.view' => 'عرض المخزون والدفعات',
        'stock.receive' => 'استلام الأدوية وتأكيد الاستلام',
        'stock.adjust' => 'تعديل المخزون والإتلاف',
        'stock.issue' => 'صرف الأدوية من المستودع',
        'stock.return' => 'تسجيل مرتجعات الأدوية',
        'stock.export' => 'تصدير تقارير المخزون',
        'stock.suppliers.manage' => 'إدارة الموردين والمستودعات',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
