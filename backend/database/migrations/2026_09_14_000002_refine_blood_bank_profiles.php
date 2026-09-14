<?php

use App\Support\BloodBankProfileSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        BloodBankProfileSchema::column('governorates', 'country_code', 'char', 2);
        BloodBankProfileSchema::column('blood_components', 'registration_kind', 'varchar', 20);
        if (! Schema::hasIndex('governorates', ['country_code'])) {
            Schema::table('governorates', fn (Blueprint $t) => $t->index('country_code'));
        }
        if (! Schema::hasIndex('blood_components', ['registration_kind'], 'unique')) {
            Schema::table('blood_components', fn (Blueprint $t) => $t->unique('registration_kind'));
        }
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            BloodBankProfileSchema::column($table, 'governorate_text', 'varchar', 120);
            BloodBankProfileSchema::column($table, 'city_text', 'varchar', 120);
            BloodBankProfileSchema::check($table, "{$table}_manual_address", '(governorate_text IS NULL OR (governorate_id IS NULL AND city_id IS NULL)) AND (city_text IS NULL OR city_id IS NULL) AND (patient_id IS NULL OR (governorate_text IS NULL AND city_text IS NULL))');
        }
        BloodBankProfileSchema::check('blood_bank_screenings', 'bb_screen_result', "result IS NULL OR result IN ('negative','positive','indeterminate')", "(status = 'complete' AND result IS NOT NULL AND result IN ('negative','positive','indeterminate')) OR (status <> 'complete' AND result IS NULL)");
    }

    public function down(): void
    {
        // Refuse a lossy rollback: the old contract cannot represent these records.
        if (DB::table('blood_bank_screenings')->where(fn ($q) => $q->where('status', 'complete')->whereNull('result'))->orWhere(fn ($q) => $q->where('status', '<>', 'complete')->whereNotNull('result'))->exists()
            || DB::table('blood_donors')->whereNotNull('governorate_text')->orWhereNotNull('city_text')->exists()
            || DB::table('blood_recipients')->whereNotNull('governorate_text')->orWhereNotNull('city_text')->exists()) {
            throw new RuntimeException('Cannot roll back blood-bank profile fields without losing saved addresses or screening states.');
        }
        DB::statement(BloodBankProfileSchema::dropCheck('blood_bank_screenings', 'bb_screen_result').", ADD CONSTRAINT bb_screen_result CHECK ((status = 'complete' AND result IS NOT NULL AND result IN ('negative','positive','indeterminate')) OR (status <> 'complete' AND result IS NULL))");
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            DB::statement(BloodBankProfileSchema::dropCheck($table, "{$table}_manual_address"));
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['governorate_text', 'city_text']));
        }
        Schema::table('blood_components', fn (Blueprint $t) => $t->dropColumn('registration_kind'));
        Schema::table('governorates', fn (Blueprint $t) => $t->dropColumn('country_code'));
    }
};
