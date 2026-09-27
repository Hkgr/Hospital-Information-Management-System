<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $t) {
            $t->dateTime('web_idle_deadline', 6)->nullable();
            $t->dateTime('web_expired_at', 6)->nullable();
        });
        // Login is the only application token issuer. Existing sessions receive
        // one deployment grace window, never a grace window on each request.
        DB::table('personal_access_tokens')->update(['web_idle_deadline' => now()->addSeconds(120)->format('Y-m-d H:i:s.u')]);
    }

    public function down(): void
    {
        if (DB::table('personal_access_tokens')->whereNotNull('web_idle_deadline')->exists()) {
            throw new RuntimeException('Revoke web tokens before rollback; removing deadlines must not revive expired sessions.');
        }
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn(['web_idle_deadline', 'web_expired_at']));
    }
};
