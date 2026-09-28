<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionMatrixPhaseThreeSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (['statistics.view' => 'عرض الإحصاءات المجهلة', 'statistics.export' => 'تصدير الإحصاءات المجهلة'] as $code => $name) {
                DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
            }
            foreach (['statistics', 'hospital_admin'] as $code) {
                $role = DB::table('roles')->where('code', $code)->value('id');
                if (! $role) {
                    throw new \RuntimeException('Phase 1 role definitions are required.');
                }
                if ($code === 'statistics' && DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $role)->whereNotIn('p.code', ['statistics.view', 'statistics.export'])->exists()) {
                    throw new \RuntimeException('Statistics role has unrelated authority; operator review required. No grants changed.');
                }
                foreach (DB::table('permissions')->whereIn('code', ['statistics.view', 'statistics.export'])->where('is_active', true)->pluck('id') as $id) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
