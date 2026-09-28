<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facility_settings', function (Blueprint $t) {
            $t->unsignedSmallInteger('idle_minutes')->default(2);
            $t->unsignedInteger('lock_version')->default(1);
        });
        Schema::create('system_session_policy', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedSmallInteger('idle_minutes')->default(2);
            $t->unsignedInteger('lock_version')->default(1);
            $t->timestamps();
        });
        DB::table('system_session_policy')->insert(['id' => 1, 'idle_minutes' => 2, 'lock_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        Schema::create('session_policy_changes', function (Blueprint $t) {
            $t->id();
            // NULL denotes the independent, protected system-admin policy.
            $t->foreignId('facility_id')->nullable()->constrained('facilities')->restrictOnDelete();
            $t->unsignedSmallInteger('idle_seconds');
            $t->dateTime('changed_at', 6);
            $t->index(['facility_id', 'changed_at']);
        });
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dateTime('web_last_activity_at', 6)->nullable());
        // Preserve each existing deadline; never convert an operator PAT.
        DB::table('personal_access_tokens')->whereNotNull('web_idle_deadline')->update([
            'web_last_activity_at' => DB::raw('DATE_SUB(web_idle_deadline, INTERVAL 120 SECOND)'),
        ]);
    }

    public function down(): void
    {
        if (DB::table('personal_access_tokens')->whereNotNull('web_idle_deadline')->exists()) {
            throw new RuntimeException('Revoke web tokens before rollback; removing policy history could revive expired sessions.');
        }
        if (DB::table('session_policy_changes')->exists()) {
            throw new RuntimeException('Policy changes exist. Retain policy history and roll forward instead of discarding settings.');
        }
        Schema::table('personal_access_tokens', fn (Blueprint $t) => $t->dropColumn('web_last_activity_at'));
        Schema::dropIfExists('session_policy_changes');
        Schema::dropIfExists('system_session_policy');
        Schema::table('facility_settings', fn (Blueprint $t) => $t->dropColumn(['idle_minutes', 'lock_version']));
    }
};
