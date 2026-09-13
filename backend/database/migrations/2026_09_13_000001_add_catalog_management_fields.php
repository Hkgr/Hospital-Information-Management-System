<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['services', 'procedures'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->text('description')->nullable();
                $table->unsignedBigInteger('lock_version')->default(1);
                $table->timestamp('archived_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['services', 'procedures'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['description', 'lock_version', 'archived_at']));
        }
    }
};
