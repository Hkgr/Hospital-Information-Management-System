<?php

namespace Tests\Support;

use Database\Seeders\MedicationStockPermissionsSeeder;
use Illuminate\Support\Facades\DB;

class StockFixture
{
    public static function make(): array
    {
        $f = CatalogFixture::make();
        app(MedicationStockPermissionsSeeder::class)->run();
        $role = DB::table('facility_user_roles')->where('user_id', $f['user']->id)->value('role_id');
        foreach (array_keys(MedicationStockPermissionsSeeder::PERMISSIONS) as $code) {
            DB::table('role_permissions')->insert(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $f['store'] = DB::table('medication_stores')->insertGetId([
            'facility_id' => $f['facility'], 'code' => 'PHARM-'.$f['tag'], 'name_ar' => 'صيدلية الاختبار',
            'location' => 'الطابق الأرضي', 'entered_by' => $f['user']->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $f['supplier'] = DB::table('medication_suppliers')->insertGetId([
            'facility_id' => $f['facility'], 'code' => 'SUP-'.$f['tag'], 'name_ar' => 'مورد اختبار',
            'entered_by' => $f['user']->id, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $f['token'] = CatalogFixture::token($f['user'], 'stock-test');
        $f['viewer_token'] = CatalogFixture::token($f['viewer'], 'stock-viewer');

        return $f;
    }

    public static function viewerStock(array $f, array $codes): void
    {
        $role = DB::table('facility_user_roles')->where('user_id', $f['viewer']->id)->value('role_id');
        foreach ($codes as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
    }
}
