<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('staff_code', 40);
            $table->string('full_name', 200);
            $table->string('search_name', 250);
            $table->unsignedBigInteger('staff_type_id');
            $table->unsignedBigInteger('funding_source_id')->nullable();
            $table->string('license_no', 60)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['staff_code'], 'unique_staff_fbad5f3940');
            $table->index(['search_name'], 'index_staff_8858365cc0');
            $table->foreign(['staff_type_id'], 'fk_staff_656f651f69')->references(['id'])->on('staff_types')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['funding_source_id'], 'fk_staff_92519fb16d')->references(['id'])->on('funding_sources')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('staff_aliases', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->string('alias', 200);
            $table->string('normalized_alias', 250);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['staff_id', 'normalized_alias'], 'unique_staff_aliases_a18d171dda');
            $table->index(['normalized_alias'], 'index_staff_aliases_c3ba93577d');
            $table->foreign(['staff_id'], 'fk_staff_aliases_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('staff_specialties', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('specialty_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['staff_id', 'specialty_id'], 'unique_staff_specialties_01cd29e940');
            $table->foreign(['staff_id'], 'fk_staff_specialties_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['specialty_id'], 'fk_staff_specialties_9cf4ae334d')->references(['id'])->on('specialties')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('clinics', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->string('code', 40);
            $table->string('name_ar', 200);
            $table->unsignedBigInteger('specialty_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'code'], 'unique_clinics_5634f6c789');
            $table->unique(['id', 'facility_id'], 'unique_clinics_62fab0bea7');
            $table->foreign(['facility_id'], 'fk_clinics_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['specialty_id'], 'fk_clinics_9cf4ae334d')->references(['id'])->on('specialties')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('clinic_staff', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('clinic_id');
            $table->unsignedBigInteger('staff_id');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['clinic_id', 'staff_id', 'starts_on'], 'unique_clinic_staff_cb38e51262');
            $table->foreign(['clinic_id'], 'fk_clinic_staff_8e97c18deb')->references(['id'])->on('clinics')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['staff_id'], 'fk_clinic_staff_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `clinic_staff` ADD CONSTRAINT `ck_clinic_staff_b6589fc6ab` CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        }

        Schema::create('users', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->string('username', 60);
            $table->string('name', 200);
            $table->string('email', 190)->nullable();
            $table->dateTime('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->string('remember_token', 100)->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['username'], 'unique_users_249ba36000');
            $table->unique(['email'], 'unique_users_a88b7dcd1a');
            $table->foreign(['staff_id'], 'fk_users_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_roles_e6fb06210f');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->string('code', 50);
            $table->string('name_ar', 200);
            $table->string('name_en', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['code'], 'unique_permissions_e6fb06210f');
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('permission_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['role_id', 'permission_id'], 'unique_role_permissions_76cb942055');
            $table->foreign(['role_id'], 'fk_role_permissions_b36bcfe02f')->references(['id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['permission_id'], 'fk_role_permissions_3b8b97af9d')->references(['id'])->on('permissions')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('facility_user_roles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('role_id');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'user_id', 'role_id'], 'unique_facility_user_roles_d73db07120');
            $table->foreign(['facility_id'], 'fk_facility_user_roles_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['user_id'], 'fk_facility_user_roles_cace4a159f')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['role_id'], 'fk_facility_user_roles_b36bcfe02f')->references(['id'])->on('roles')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('facility_settings', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('correction_user_id')->nullable();
            $table->json('enabled_modules');
            $table->json('preferences')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id'], 'unique_facility_settings_07c6c82781');
            $table->foreign(['facility_id'], 'fk_facility_settings_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['correction_user_id'], 'fk_facility_settings_c330d90c52')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
        });

        Schema::create('medical_equipment', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('equipment_category_id');
            $table->string('code', 40);
            $table->string('name_ar', 200);
            $table->integer('quantity')->default(1);
            $table->string('operational_status', 30)->default('operational');
            $table->date('status_as_of')->nullable();
            $table->text('condition_note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'code'], 'unique_medical_equipment_5634f6c789');
            $table->foreign(['facility_id'], 'fk_medical_equipment_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['equipment_category_id'], 'fk_medical_equipment_78bb3e6faa')->references(['id'])->on('equipment_categories')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `medical_equipment` ADD CONSTRAINT `ck_medical_equipment_b6589fc6ab` CHECK (quantity > 0)');
        }
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `medical_equipment` ADD CONSTRAINT `ck_medical_equipment_356a192b79` CHECK (operational_status IN (\'operational\',\'limited\',\'out_of_service\',\'unknown\'))');
        }

        Schema::create('staff_work_days', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_unicode_ci';
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('clinic_id')->nullable();
            $table->date('work_date');
            $table->string('source_type', 20)->default('documented');
            $table->unsignedBigInteger('entered_by');
            $table->text('note')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['facility_id', 'staff_id', 'work_date'], 'unique_staff_work_days_b2f12c0d6f');
            $table->index(['facility_id', 'work_date'], 'index_staff_work_days_8e8a1034cf');
            $table->index(['staff_id', 'work_date'], 'index_staff_work_days_212d46f30c');
            $table->foreign(['facility_id'], 'fk_staff_work_days_07c6c82781')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['staff_id'], 'fk_staff_work_days_e4ee98bb55')->references(['id'])->on('staff')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['entered_by'], 'fk_staff_work_days_020d7f1cfb')->references(['id'])->on('users')->restrictOnDelete()->restrictOnUpdate();
            $table->foreign(['clinic_id', 'facility_id'], 'fk_staff_work_days_7c0a1e0767')->references(['id', 'facility_id'])->on('clinics')->restrictOnDelete()->restrictOnUpdate();
        });
        if (DB::getDriverName() !== 'sqlite') {
            DB::statement('ALTER TABLE `staff_work_days` ADD CONSTRAINT `ck_staff_work_days_b6589fc6ab` CHECK (source_type IN (\'documented\',\'derived\'))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_work_days');
        Schema::dropIfExists('medical_equipment');
        Schema::dropIfExists('facility_settings');
        Schema::dropIfExists('facility_user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('clinic_staff');
        Schema::dropIfExists('clinics');
        Schema::dropIfExists('staff_specialties');
        Schema::dropIfExists('staff_aliases');
        Schema::dropIfExists('staff');
    }
};
