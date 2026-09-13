<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CatalogPermissionsSeeder extends Seeder
{
    public const PERMISSIONS = [
        'catalog.view' => 'استعراض الخدمات والإجراءات في المنشأة',
        'catalog.export' => 'تصدير دليل الخدمات والإجراءات في المنشأة',
        'catalog.beneficiaries' => 'استعراض أسماء المستفيدين من الخدمات والإجراءات في المنشأة',
        'catalog.audit' => 'استعراض سجل تغييرات الخدمات والإجراءات في المنشأة',
        'catalog.directory.create' => 'إضافة تعريف إلى دليل الخدمات والإجراءات المشترك',
        'catalog.directory.update' => 'تعديل وتعطيل واستعادة وتفعيل تعريف مشترك',
        'catalog.directory.delete' => 'حذف وأرشفة تعريف مشترك',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $code => $name) {
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true]);
        }
    }
}
