<?php

namespace Database\Seeders;

use App\Services\Auth\TaskPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TaskPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TaskPermissions::TASKS as $code => [$name]) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
