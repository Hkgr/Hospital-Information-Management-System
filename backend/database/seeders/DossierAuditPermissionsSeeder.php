<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DossierAuditPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permissions')->insertOrIgnore(['code' => 'dossiers.audit', 'name_ar' => 'استعراض سجل تغييرات الإضبارة داخل المنشأة', 'created_at' => now(), 'updated_at' => now()]);
    }
}
