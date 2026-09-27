<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', fn (Blueprint $t) => $t->boolean('is_system_super_admin')->default(false));
    }

    public function down(): void
    {
        if (DB::table('roles')->where('is_system_super_admin', true)->exists()) {
            throw new RuntimeException('Revoke the protected system assignment through a reviewed maintenance procedure before rollback.');
        }
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn('is_system_super_admin'));
    }
};
