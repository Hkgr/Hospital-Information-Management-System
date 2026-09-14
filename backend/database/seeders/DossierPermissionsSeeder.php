<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'dossiers.view', 'name_ar' => 'عرض إضبارات المرضى وزياراتها في المنشأة', 'is_active' => true]);
    }
}
