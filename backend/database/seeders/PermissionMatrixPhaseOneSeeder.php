<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionMatrixPhaseOneSeeder extends Seeder
{
    public const RECEPTION = ['reception.view', 'reception.register', 'reception.patients.search', 'reception.patients.create'];

    public const ADMIN = ['dashboards.view', 'users.view', 'users.create', 'users.delete', 'roles.view', 'roles.create', 'roles.update', 'audit.view', 'reports.view', 'reports.export', 'dossiers.view', 'dossiers.export', 'dossiers.audit', 'clinics.view', 'clinics.create', 'clinics.update', 'clinics.export', 'doctors.view', 'doctors.link', 'doctors.export', 'catalog.view', 'catalog.export', 'catalog.audit'];

    public function run(): void
    {
        DB::transaction(function () {
            foreach ([SurfacePermissionsSeeder::class, UserPermissionsSeeder::class, DossierPermissionsSeeder::class, DossierAuditPermissionsSeeder::class, ClinicPermissionsSeeder::class, DoctorPermissionsSeeder::class, CatalogPermissionsSeeder::class] as $definition) {
                app($definition)->run();
            }
            foreach (array_combine(self::RECEPTION, ['عرض الاستقبال المحدود', 'تسجيل بطاقة وزيارة أولى من الاستقبال', 'بحث تعريفي محدود عن المرضى — تفويض عالمي', 'إنشاء هوية مريض من الاستقبال — تفويض عالمي']) as $code => $name) {
                DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
            }
            foreach (['data_entry' => ['مدخل البيانات', self::RECEPTION], 'hospital_admin' => ['إداري المشفى', self::ADMIN], 'statistics' => ['فريق الإحصاء', []], 'super_admin' => ['مدير النظام الشامل', []]] as $code => [$name, $permissions]) {
                DB::table('roles')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
                $id = DB::table('roles')->where('code', $code)->lockForUpdate()->value('id');
                if (in_array($code, ['data_entry', 'statistics'], true) && DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('rp.role_id', $id)->whereNotIn('p.code', $permissions)->exists()) {
                    throw new \RuntimeException("Existing $code role has permissions outside the phase-one contract; operator review required. No grants changed.");
                }
                foreach (DB::table('permissions')->whereIn('code', $permissions)->where('is_active', true)->pluck('id') as $permission) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $id, 'permission_id' => $permission, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
