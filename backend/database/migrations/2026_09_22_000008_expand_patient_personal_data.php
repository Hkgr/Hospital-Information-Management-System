<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $t) {
            $t->string('marital_status', 20)->default('unknown')->after('displacement_status');
            $t->string('permanent_address', 255)->nullable()->after('marital_status');
            $t->string('occupation', 120)->nullable()->after('permanent_address');
            $t->string('smoking_status', 20)->default('unknown')->after('occupation');
            $t->string('alcohol_status', 20)->default('unknown')->after('smoking_status');
        });
        DB::statement("ALTER TABLE patients ADD CONSTRAINT ck_patients_marital_status CHECK (marital_status IN ('single','married','divorced','widowed','unknown')), ADD CONSTRAINT ck_patients_smoking_status CHECK (smoking_status IN ('yes','no','former','unknown')), ADD CONSTRAINT ck_patients_alcohol_status CHECK (alcohol_status IN ('yes','no','former','unknown'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE patients DROP CONSTRAINT ck_patients_marital_status, DROP CONSTRAINT ck_patients_smoking_status, DROP CONSTRAINT ck_patients_alcohol_status');
        Schema::table('patients', function (Blueprint $t) {
            $t->dropColumn(['marital_status', 'permanent_address', 'occupation', 'smoking_status', 'alcohol_status']);
        });
    }
};
