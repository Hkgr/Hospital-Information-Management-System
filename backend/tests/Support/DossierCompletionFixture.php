<?php

namespace Tests\Support;

use Database\Seeders\DossierCompletionPermissionsSeeder;
use Database\Seeders\DossierOutcomeSeeder;
use Illuminate\Support\Facades\DB;

class DossierCompletionFixture
{
    public static function make(): array
    {
        $f = DossierWorkflowFixture::make();
        app(DossierCompletionPermissionsSeeder::class)->run();
        app(DossierOutcomeSeeder::class)->run();
        foreach (array_keys(DossierCompletionPermissionsSeeder::CODES) as $code) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $f['dossier_role'], 'permission_id' => DB::table('permissions')->where('code', $code)->value('id')]);
        }
        $f['service'] = DB::table('services')->where('is_active', true)->whereNull('archived_at')->value('id');
        $f['procedure'] = DB::table('procedures')->where('is_active', true)->whereNull('archived_at')->value('id');
        $f['medication'] = DB::table('medications')->insertGetId(['code' => 'RX-'.$f['tag'], 'name_ar' => 'دواء اختبار '.$f['tag'], 'is_active' => true]);

        return $f;
    }
}
