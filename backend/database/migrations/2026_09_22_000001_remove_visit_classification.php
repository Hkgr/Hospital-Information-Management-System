<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropForeign('fk_visits_edf5049a91');
            $table->dropColumn('visit_type_id');
        });
        Schema::drop('visit_types');
    }

    public function down(): void
    {
        // Classification values were deliberately removed, not mapped to a fake type.
        throw new RuntimeException('Visit classification removal is irreversible. Restore a reviewed backup to recover removed classifications.');
    }
};
