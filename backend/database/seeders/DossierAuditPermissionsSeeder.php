<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierAuditPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'dossiers.audit', 'name_ar' => 'استعراض سجل تغييرات بطاقة المريض داخل المنشأة', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('permissions')->where('code', 'dossiers.audit')->where('name_ar', '<>', 'استعراض سجل تغييرات بطاقة المريض داخل المنشأة')->update(['name_ar' => 'استعراض سجل تغييرات بطاقة المريض داخل المنشأة', 'updated_at' => now()]);
    }
}
