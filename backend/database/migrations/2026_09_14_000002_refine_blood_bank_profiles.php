<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('governorates', fn (Blueprint $t) => $t->char('country_code', 2)->nullable()->index());
        Schema::table('blood_components', fn (Blueprint $t) => $t->string('registration_kind', 20)->nullable()->unique());
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('governorate_text', 120)->nullable();
                $t->string('city_text', 120)->nullable();
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_manual_address CHECK ((governorate_text IS NULL OR (governorate_id IS NULL AND city_id IS NULL)) AND (city_text IS NULL OR city_id IS NULL) AND (patient_id IS NULL OR (governorate_text IS NULL AND city_text IS NULL)))");
        }
        DB::statement("ALTER TABLE blood_bank_screenings DROP CHECK bb_screen_result, ADD CONSTRAINT bb_screen_result CHECK (result IS NULL OR result IN ('negative','positive','indeterminate'))");
    }

    public function down(): void
    {
        // Refuse a lossy rollback: the old contract cannot represent these records.
        if (DB::table('blood_bank_screenings')->where(fn ($q) => $q->where('status', 'complete')->whereNull('result'))->orWhere(fn ($q) => $q->where('status', '<>', 'complete')->whereNotNull('result'))->exists()
            || DB::table('blood_donors')->whereNotNull('governorate_text')->orWhereNotNull('city_text')->exists()
            || DB::table('blood_recipients')->whereNotNull('governorate_text')->orWhereNotNull('city_text')->exists()) {
            throw new RuntimeException('Cannot roll back blood-bank profile fields without losing saved addresses or screening states.');
        }
        DB::statement("ALTER TABLE blood_bank_screenings DROP CHECK bb_screen_result, ADD CONSTRAINT bb_screen_result CHECK ((status = 'complete' AND result IS NOT NULL AND result IN ('negative','positive','indeterminate')) OR (status <> 'complete' AND result IS NULL))");
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            DB::statement("ALTER TABLE $table DROP CHECK {$table}_manual_address");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['governorate_text', 'city_text']));
        }
        Schema::table('blood_components', fn (Blueprint $t) => $t->dropColumn('registration_kind'));
        Schema::table('governorates', fn (Blueprint $t) => $t->dropColumn('country_code'));
    }
};
