<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'dossiers.view', 'name_ar' => 'عرض بطاقات المرضى وزياراتها في المنشأة', 'is_active' => true]);
        DB::table('permissions')->where('code', 'dossiers.view')->where('name_ar', '<>', 'عرض بطاقات المرضى وزياراتها في المنشأة')->update(['name_ar' => 'عرض بطاقات المرضى وزياراتها في المنشأة']);
    }
}
