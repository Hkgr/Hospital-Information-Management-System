<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinics', fn (Blueprint $t) => $t->string('clinic_kind', 20)->nullable());
        // Preserve the original columns/values for historical consumers and rollback.
        DB::table('clinics')->whereNotNull('inpatient_kind')->update(['clinic_kind' => DB::raw('inpatient_kind')]);
        DB::statement('ALTER TABLE clinics DROP CONSTRAINT ck_clinics_inpatient_kind');
        DB::statement('ALTER TABLE clinics DROP CONSTRAINT ck_clinics_care_setting');
        DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_care_setting CHECK (care_setting IS NULL OR care_setting IN ('outpatient','inpatient','surgical','radiology'))");
        DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_kind CHECK (clinic_kind IS NULL OR clinic_kind IN ('blood','oncology','thalassemia','surgical'))");
        Schema::table('procedures', function (Blueprint $t) {
            $t->string('execution_location', 30)->nullable();
            $t->string('guidance_method', 20)->nullable();
        });
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT ck_procedure_location CHECK (execution_location IS NULL OR execution_location IN ('surgical_clinic','radiology'))");
        DB::statement("ALTER TABLE procedures ADD CONSTRAINT ck_procedure_guidance CHECK (guidance_method IS NULL OR (execution_location = 'radiology' AND guidance_method IN ('ultrasound','ct')))");
        Schema::table('visit_procedures', function (Blueprint $t) {
            $t->string('execution_location_snapshot', 30)->nullable();
            $t->string('guidance_method_snapshot', 20)->nullable();
        });
    }

    public function down(): void
    {
        // A downgrade must not discard new classifications or weaken recorded restrictions.
        if (DB::table('clinics')->whereNotNull('clinic_kind')->exists()
            || DB::table('clinics')->where('care_setting', 'radiology')->exists()
            || DB::table('procedures')->whereNotNull('execution_location')->exists()
            || DB::table('visit_procedures')->whereNotNull('execution_location_snapshot')->exists()) {
            throw new RuntimeException('Clinical classifications are in use; retain the additive schema during application rollback.');
        }
        DB::statement('ALTER TABLE procedures DROP CONSTRAINT ck_procedure_guidance');
        DB::statement('ALTER TABLE procedures DROP CONSTRAINT ck_procedure_location');
        Schema::table('visit_procedures', fn (Blueprint $t) => $t->dropColumn(['execution_location_snapshot', 'guidance_method_snapshot']));
        Schema::table('procedures', fn (Blueprint $t) => $t->dropColumn(['execution_location', 'guidance_method']));
        DB::statement('ALTER TABLE clinics DROP CONSTRAINT ck_clinics_kind');
        DB::statement('ALTER TABLE clinics DROP CONSTRAINT ck_clinics_care_setting');
        DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_inpatient_kind CHECK ((care_setting = 'inpatient' AND inpatient_kind IN ('blood','oncology','thalassemia','surgical')) OR (IFNULL(care_setting, '') <> 'inpatient' AND inpatient_kind IS NULL))");
        DB::statement("ALTER TABLE clinics ADD CONSTRAINT ck_clinics_care_setting CHECK (care_setting IS NULL OR care_setting IN ('outpatient','inpatient','surgical'))");
        Schema::table('clinics', fn (Blueprint $t) => $t->dropColumn('clinic_kind'));
    }
};
