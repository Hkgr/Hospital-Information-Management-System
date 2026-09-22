<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserPermissionsSeeder extends Seeder
{
    public const CODES = [
        'users.view' => 'استعراض مستخدمي المنشأة',
        'users.create' => 'إضافة مستخدم وربطه بالمنشأة',
        'users.delete' => 'حذف مستخدم من المنشأة',
    ];

    public function run(): void
    {
        foreach (self::CODES as $code => $name) {
            // Definitions only: never grant roles or reactivate an existing permission.
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
