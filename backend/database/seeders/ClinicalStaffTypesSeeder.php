<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ClinicalStaffTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['RESIDENT' => 'طبيب مقيم', 'SPECIALIST' => 'طبيب اختصاصي'] as $code => $name) {
            // Explicitly requested types only; preserve disabled definitions and all assignments.
            DB::table('staff_types')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now()]);
        }
    }
}
