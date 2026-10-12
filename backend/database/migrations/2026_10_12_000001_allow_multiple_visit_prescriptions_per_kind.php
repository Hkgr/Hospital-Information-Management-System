<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keep all headers, items, identifiers, scope FKs and kind checks.
        Schema::table('visit_prescriptions', function (Blueprint $table) {
            $table->dropUnique('prescriptions_one_kind');
        });
    }

    public function down(): void
    {
        if (DB::table('visit_prescriptions')->whereNull('voided_at')->select('visit_id', 'kind')->groupBy('visit_id', 'kind')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore one invoice per kind: multiple active invoices exist. No records have been changed.');
        }
        Schema::table('visit_prescriptions', function (Blueprint $table) {
            $table->unique('active_kind_key', 'prescriptions_one_kind');
        });
    }
};
