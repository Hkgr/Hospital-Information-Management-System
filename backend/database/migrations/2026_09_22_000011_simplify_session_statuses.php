<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('oncology_sessions')->where('status', 'rescheduled')->update(['status' => 'scheduled']);
        DB::table('oncology_sessions')->whereIn('status', ['missed', 'referred'])->update(['status' => 'cancelled']);
        DB::statement('ALTER TABLE oncology_sessions DROP CONSTRAINT onc_session_state');
        DB::statement("ALTER TABLE oncology_sessions ADD CONSTRAINT onc_session_state CHECK (status IN ('scheduled','completed','cancelled'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE oncology_sessions DROP CONSTRAINT onc_session_state');
        DB::statement("ALTER TABLE oncology_sessions ADD CONSTRAINT onc_session_state CHECK (status IN ('scheduled','rescheduled','completed','missed','cancelled','referred'))");
    }
};
