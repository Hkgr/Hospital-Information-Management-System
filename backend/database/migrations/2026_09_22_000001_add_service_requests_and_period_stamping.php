<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visit_services', function (Blueprint $table) {
            $table->string('status', 20)->default('completed');
            $table->date('requested_on')->nullable();
            $table->unsignedBigInteger('patient_id')->nullable();
            $table->string('cancelled_reason', 255)->nullable();
        });
        DB::statement('ALTER TABLE `visit_services` MODIFY `performed_on` DATE NULL');
        DB::statement('UPDATE visit_services SET requested_on = performed_on WHERE requested_on IS NULL');
        DB::statement('UPDATE visit_services s JOIN visits v ON v.id = s.visit_id SET s.patient_id = v.patient_id WHERE s.patient_id IS NULL');
        DB::statement('ALTER TABLE `visit_services` MODIFY `requested_on` DATE NOT NULL');
        DB::statement('ALTER TABLE `visit_services` MODIFY `patient_id` BIGINT UNSIGNED NOT NULL');
        Schema::table('visit_services', function (Blueprint $table) {
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement("ALTER TABLE `visit_services` ADD `open_request_key` VARCHAR(80) AS (
     CASE WHEN status = 'pending' AND voided_at IS NULL
          THEN CONCAT(patient_id, '-', service_id) END
   ) STORED");
        Schema::table('visit_services', function (Blueprint $table) {
            $table->unique(['facility_id', 'open_request_key'], 'visit_services_one_open_request');
        });
        DB::statement("ALTER TABLE `visit_services` ADD CONSTRAINT `visit_services_status` CHECK (status IN ('pending','completed','cancelled'))");
        DB::statement("ALTER TABLE `visit_services` ADD CONSTRAINT `visit_services_completed_performed` CHECK (status <> 'completed' OR performed_on IS NOT NULL)");
        DB::statement("ALTER TABLE `visit_services` ADD CONSTRAINT `visit_services_pending_performed` CHECK (status <> 'pending' OR performed_on IS NULL)");
        DB::statement("ALTER TABLE `visit_services` ADD CONSTRAINT `visit_services_cancelled_reason` CHECK (status <> 'cancelled' OR cancelled_reason IS NOT NULL)");
        DB::statement('ALTER TABLE `visit_services` ADD CONSTRAINT `visit_services_performed_after_requested` CHECK (performed_on IS NULL OR performed_on >= requested_on)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `visit_services` DROP CONSTRAINT `visit_services_performed_after_requested`');
        DB::statement('ALTER TABLE `visit_services` DROP CONSTRAINT `visit_services_cancelled_reason`');
        DB::statement('ALTER TABLE `visit_services` DROP CONSTRAINT `visit_services_pending_performed`');
        DB::statement('ALTER TABLE `visit_services` DROP CONSTRAINT `visit_services_completed_performed`');
        DB::statement('ALTER TABLE `visit_services` DROP CONSTRAINT `visit_services_status`');
        Schema::table('visit_services', function (Blueprint $table) {
            $table->dropUnique('visit_services_one_open_request');
        });
        Schema::table('visit_services', function (Blueprint $table) {
            $table->dropColumn('open_request_key');
        });
        Schema::table('visit_services', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
        });
        DB::statement('ALTER TABLE `visit_services` MODIFY `requested_on` DATE NULL');
        DB::statement('ALTER TABLE `visit_services` MODIFY `patient_id` BIGINT UNSIGNED NULL');
        Schema::table('visit_services', function (Blueprint $table) {
            $table->dropColumn(['status', 'requested_on', 'patient_id', 'cancelled_reason']);
        });
        DB::statement('ALTER TABLE `visit_services` MODIFY `performed_on` DATE NOT NULL');
    }
};
