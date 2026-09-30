<?php

use App\Services\Auth\TaskPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (TaskPermissions::TASKS as $code => [$name]) {
            // Definitions only. Preserve disabled definitions and every role assignment.
            DB::table('permissions')->insertOrIgnore(['code' => $code, 'name_ar' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // Assigned definitions are historical authorization data, not disposable schema.
        // Rolling application code back leaves these additive definitions inert.
    }
};
