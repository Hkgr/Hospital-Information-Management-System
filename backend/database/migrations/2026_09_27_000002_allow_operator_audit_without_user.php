<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', fn (Blueprint $table) => $table->unsignedBigInteger('actor_id')->nullable()->change());
    }

    public function down(): void
    {
        if (DB::table('audit_logs')->whereNull('actor_id')->exists()) {
            throw new RuntimeException('Operator audit rows exist; rollback cannot invent an actor or delete audit history.');
        }
        Schema::table('audit_logs', fn (Blueprint $table) => $table->unsignedBigInteger('actor_id')->nullable(false)->change());
    }
};
