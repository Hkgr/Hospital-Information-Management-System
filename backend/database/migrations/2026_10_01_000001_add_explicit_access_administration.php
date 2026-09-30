<?php

use Database\Seeders\AccessAdministrationPermissionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamp('access_consolidated_at')->nullable();
        });
        (new AccessAdministrationPermissionsSeeder)->run();
    }

    public function down(): void
    {
        throw new RuntimeException('Access decisions and audit history must be retained. Use the documented forward recovery; automatic rollback is unsafe.');
    }
};
