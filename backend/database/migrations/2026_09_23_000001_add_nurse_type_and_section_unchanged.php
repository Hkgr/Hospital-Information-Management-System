<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('staff_types')->where('code', 'NURSE')->exists()) {
            DB::table('staff_types')->insert([
                'code' => 'NURSE',
                'name_ar' => 'ممرض',
                'name_en' => 'Nurse',
                'is_active' => true,
                'display_order' => 20,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Schema::table('dossier_section_progress', function (Blueprint $t) {
            $t->boolean('unchanged')->default(false)->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('dossier_section_progress', function (Blueprint $t) {
            $t->dropColumn('unchanged');
        });
        DB::table('staff_types')->where('code', 'NURSE')->where('name_ar', 'ممرض')->delete();
    }
};
