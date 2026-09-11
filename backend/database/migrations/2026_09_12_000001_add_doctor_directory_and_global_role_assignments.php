<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
        });
        // Explicit global assignments. A facility_user_roles row never grants this scope.
        Schema::create('global_user_roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_user_roles');
        Schema::table('staff', fn (Blueprint $table) => $table->dropColumn(['description', 'lock_version']));
    }
};
