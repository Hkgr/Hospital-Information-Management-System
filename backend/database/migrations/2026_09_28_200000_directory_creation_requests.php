<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_creation_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('fingerprint', 64);
            // Intentionally no entity FK: deletion must not erase the replay reservation.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->timestamp('created_at');
            $table->unique(['facility_id', 'user_id', 'request_id'], 'directory_creation_request_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('directory_creation_requests') && DB::table('directory_creation_requests')->exists()) {
            throw new RuntimeException('Cannot discard creation replay history. Keep this additive table when rolling back application code.');
        }
        Schema::dropIfExists('directory_creation_requests');
    }
};
