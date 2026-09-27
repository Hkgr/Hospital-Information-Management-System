<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PermissionMatrixPhaseTwoSeeder extends Seeder
{
    public const CLERK = ['reception.view', 'reception.register', 'reception.correct', 'reception.corrections.request'];

    public const ADMIN = ['identity_corrections.review', 'reception_accounts.manage', 'patient_duplicates.review'];

    public function run(): void
    {
        DB::transaction(function () {
            foreach ([
                'reception.correct' => 'تصحيح هوية أدخلها المستخدم خلال المهلة',
                'reception.corrections.request' => 'طلب مراجعة تصحيح الهوية',
                'identity_corrections.review' => 'مراجعة تصحيحات الهوية داخل المنشأة',
                'patients.identity.review' => 'اعتماد تصحيح الهوية المشتركة — تفويض عالمي',
                'reception_accounts.manage' => 'إدارة حسابات الاستقبال المحدودة في المنشأة',
                'patient_duplicates.review' => 'معاينة ومراجعة تكرار الهوية في المنشأة',
                'patients.duplicates.merge' => 'اعتماد ربط الهوية المكررة الآمن — تفويض عالمي',
            ] as $code => $name) {
                DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
            }
            foreach (['data_entry' => self::CLERK, 'hospital_admin' => [...self::CLERK, ...self::ADMIN]] as $code => $permissions) {
                $role = DB::table('roles')->where('code', $code)->value('id');
                if (! $role) {
                    throw new \RuntimeException('Run the Phase 1 definitions seeder before Phase 2.');
                }
                foreach (DB::table('permissions')->whereIn('code', $permissions)->where('is_active', true)->pluck('id') as $id) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $role, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        });
    }
}
