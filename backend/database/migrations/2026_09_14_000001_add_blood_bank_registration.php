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
        Schema::table('blood_donors', function (Blueprint $t) {
            $this->names($t);
            $this->responsibility($t);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unique(['id', 'facility_id'], 'bb_donor_scope');
        });
        Schema::create('blood_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('recipient_code', 40)->unique();
            $this->names($t);
            $t->date('birth_date')->nullable();
            $t->string('gender', 15)->nullable();
            $t->string('phone', 30)->nullable();
            $t->foreignId('governorate_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->foreign(['city_id', 'governorate_id'])->references(['id', 'governorate_id'])->on('cities')->restrictOnDelete();
            $t->string('address_line', 255)->nullable();
            $t->string('beneficiary_entity', 200)->nullable();
            $t->string('blood_group', 5)->nullable();
            $t->string('rh', 10)->nullable();
            $this->responsibility($t);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['facility_id', 'patient_id'], 'bb_recipient_patient');
            $t->unique(['id', 'facility_id'], 'bb_recipient_scope');
            $t->index(['facility_id', 'family_name', 'first_name'], 'bb_recipient_names');
        });
        DB::statement('ALTER TABLE blood_recipients ADD CONSTRAINT bb_recipient_identity CHECK ((patient_id IS NULL AND first_name IS NOT NULL AND family_name IS NOT NULL) OR (patient_id IS NOT NULL AND first_name IS NULL AND family_name IS NULL AND father_name IS NULL AND mother_name IS NULL AND birth_date IS NULL AND birth_date_accuracy IS NULL AND gender IS NULL AND phone IS NULL AND alt_phone IS NULL AND governorate_id IS NULL AND city_id IS NULL AND address_line IS NULL AND displacement_status IS NULL))');
        Schema::table('screening_tests', fn (Blueprint $t) => $t->string('blood_bank_analyte', 10)->nullable()->index());
        DB::statement("ALTER TABLE screening_tests ADD CONSTRAINT bb_test_analyte CHECK (blood_bank_analyte IS NULL OR blood_bank_analyte IN ('HBsAg','HCV','HIV'))");
        Schema::create('blood_bank_screenings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('donor_id')->nullable()->constrained('blood_donors')->restrictOnDelete();
            $t->foreignId('recipient_id')->nullable()->constrained('blood_recipients')->restrictOnDelete();
            $t->string('analyte', 10);
            $t->foreignId('screening_test_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('status', 20);
            $t->string('result', 20)->nullable();
            $t->timestamps();
            $t->unique(['donor_id', 'analyte']);
            $t->unique(['recipient_id', 'analyte']);
        });
        DB::statement("ALTER TABLE blood_bank_screenings ADD CONSTRAINT bb_screen_owner CHECK ((donor_id IS NULL) <> (recipient_id IS NULL)), ADD CONSTRAINT bb_screen_analyte CHECK (analyte IN ('HBsAg','HCV','HIV')), ADD CONSTRAINT bb_screen_state CHECK (status IN ('not_requested','requested','pending','complete','cancelled')), ADD CONSTRAINT bb_screen_result CHECK ((status = 'complete' AND result IS NOT NULL AND result IN ('negative','positive','indeterminate')) OR (status <> 'complete' AND result IS NULL))");
        Schema::table('blood_donations', fn (Blueprint $t) => $t->string('donation_code', 40)->nullable()->unique());
        Schema::create('blood_donation_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('blood_donation_id')->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->timestamp('created_at')->nullable();
        });
        DB::table('blood_donations')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                $code = 'DON-'.str_replace('-', '', $row->donated_on).'-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT);
                DB::table('blood_donations')->where('id', $row->id)->update(['donation_code' => $code]);
                DB::table('blood_donation_codes')->insert(['blood_donation_id' => $row->id, 'code' => $code, 'created_at' => now()]);
            }
        });
        Schema::create('blood_bank_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['facility_id', 'user_id', 'request_id'], 'bb_request_once');
        });
    }

    private function names(Blueprint $t): void
    {
        $t->string('first_name', 80)->nullable();
        $t->string('family_name', 80)->nullable();
        $t->string('father_name', 80)->nullable();
        $t->string('mother_name', 120)->nullable();
        $t->string('birth_date_accuracy', 20)->nullable();
        $t->string('alt_phone', 30)->nullable();
        $t->string('displacement_status', 20)->nullable();
    }

    private function responsibility(Blueprint $t): void
    {
        $t->foreignId('clinic_id')->nullable()->constrained()->restrictOnDelete();
        $t->foreignId('responsible_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
        $t->foreignId('blood_component_id')->nullable()->constrained()->restrictOnDelete();
    }

    public function down(): void
    {
        Schema::dropIfExists('blood_bank_requests');
        Schema::dropIfExists('blood_donation_codes');
        Schema::table('blood_donations', fn (Blueprint $t) => $t->dropColumn('donation_code'));
        Schema::dropIfExists('blood_bank_screenings');
        DB::statement(BloodBankProfileSchema::dropCheck('screening_tests', 'bb_test_analyte'));
        Schema::table('screening_tests', fn (Blueprint $t) => $t->dropColumn('blood_bank_analyte'));
        Schema::dropIfExists('blood_recipients');
        Schema::table('blood_donors', function (Blueprint $t) {
            foreach (['clinic_id', 'responsible_staff_id', 'blood_component_id', 'updated_by'] as $column) {
                $t->dropForeign([$column]);
            }
            $t->dropUnique('bb_donor_scope');
            $t->dropColumn(['first_name', 'family_name', 'father_name', 'mother_name', 'birth_date_accuracy', 'alt_phone', 'displacement_status', 'clinic_id', 'responsible_staff_id', 'blood_component_id', 'updated_by', 'lock_version']);
        });
    }
};
