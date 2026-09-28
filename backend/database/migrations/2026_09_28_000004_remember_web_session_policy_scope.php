<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->json('web_policy_scope')->nullable());
    }

    public function down(): void
    {
        if (DB::table('personal_access_tokens')->whereNotNull('web_idle_deadline')->exists()) {
            throw new RuntimeException('Revoke web tokens before rollback of policy scope.');
        }
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn('web_policy_scope'));
    }
};
