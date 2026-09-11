<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['staff', 'clinics'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->timestamp('archived_at')->nullable()->index());
        }
    }

    public function down(): void
    {
        foreach (['staff', 'clinics'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('archived_at'));
        }
    }
};
