<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClinicPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['view' => 'استعراض العيادات', 'create' => 'إضافة عيادة', 'update' => 'تعديل وتعطيل العيادات', 'delete' => 'حذف عيادة غير مرتبطة', 'export' => 'تصدير تقارير العيادات'] as $action => $name) {
            // Definitions only: never grant roles or reactivate an existing permission.
            DB::table('permissions')->insertOrIgnore(['code' => 'clinics.'.$action, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
