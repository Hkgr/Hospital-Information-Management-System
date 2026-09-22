<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'dossiers.view' => 'عرض بطاقات المرضى وزياراتها في المنشأة',
            'dossiers.delete' => 'حذف بطاقة المريض وكل ارتباطاتها في المنشأة',
        ] as $code => $name) {
            // Definitions only: never grant roles or reactivate an existing permission.
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
        DB::table('permissions')->where('code', 'dossiers.view')->where('name_ar', '<>', 'عرض بطاقات المرضى وزياراتها في المنشأة')->update(['name_ar' => 'عرض بطاقات المرضى وزياراتها في المنشأة']);
    }
}
