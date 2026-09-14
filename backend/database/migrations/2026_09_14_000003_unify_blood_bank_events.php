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
        // Never mark a partially applied, unverified schema as complete.
        foreach (['blood_bank_people', 'blood_bank_events', 'blood_bank_event_codes', 'blood_bank_event_screenings', 'blood_bank_identity_reviews'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException("Partial unified blood-bank schema: $name exists. Inspect and restore the failed migration before retrying; no schema was assumed complete.");
            }
        }
        Schema::create('blood_bank_people', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreignId('patient_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            foreach (['first_name' => 80, 'family_name' => 80, 'father_name' => 80, 'mother_name' => 120, 'legacy_name' => 200, 'national_id' => 60, 'phone' => 30, 'alt_phone' => 30, 'address_line' => 255, 'governorate_text' => 120, 'city_text' => 120, 'birth_date_accuracy' => 20, 'gender' => 15, 'displacement_status' => 20] as $name => $length) {
                $t->string($name, $length)->nullable();
            }
            $t->date('birth_date')->nullable();
            $t->foreignId('governorate_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('city_id')->nullable();
            $t->foreign(['city_id', 'governorate_id'])->references(['id', 'governorate_id'])->on('cities')->restrictOnDelete();
            $t->string('blood_group', 5)->nullable();
            $t->string('rh', 10)->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['facility_id', 'patient_id'], 'bb_people_patient');
            $t->unique(['id', 'facility_id'], 'bb_people_scope');
        });
        DB::statement('ALTER TABLE blood_bank_people ADD CONSTRAINT bb_people_no_patient_copy CHECK (patient_id IS NULL OR (first_name IS NULL AND family_name IS NULL AND father_name IS NULL AND mother_name IS NULL AND legacy_name IS NULL AND national_id IS NULL AND birth_date IS NULL AND gender IS NULL AND phone IS NULL AND alt_phone IS NULL AND governorate_id IS NULL AND city_id IS NULL AND governorate_text IS NULL AND city_text IS NULL AND address_line IS NULL AND birth_date_accuracy IS NULL AND displacement_status IS NULL))');
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('person_id')->nullable()->constrained('blood_bank_people')->restrictOnDelete());
        }
        foreach (['blood_donations', 'blood_transfusions'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('quantity_unit', 8)->default('unit');
                $t->decimal('units', 18, 4)->default(null)->change();
            });
            DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_quantity_unit CHECK (quantity_unit IN ('unit','kg'))");
        }
        Schema::create('blood_bank_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('person_id');
            $t->foreignId('facility_id')->constrained()->restrictOnDelete();
            $t->foreign(['person_id', 'facility_id'])->references(['id', 'facility_id'])->on('blood_bank_people')->restrictOnDelete();
            $t->string('kind', 12);
            $t->string('benefit_kind', 16)->nullable();
            $t->foreignId('issue_event_id')->nullable()->unique()->constrained('blood_bank_events')->restrictOnDelete();
            $t->foreignId('blood_donation_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('blood_transfusion_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->date('occurred_on');
            $t->unsignedBigInteger('reporting_period_id');
            $t->foreign(['reporting_period_id', 'facility_id'])->references(['id', 'facility_id'])->on('reporting_periods')->restrictOnDelete();
            $t->foreignId('blood_component_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('clinic_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('responsible_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $t->string('blood_group', 5)->nullable();
            $t->string('rh', 10)->nullable();
            $t->decimal('quantity', 18, 4);
            $t->string('quantity_unit', 8);
            $t->string('beneficiary_entity', 200)->nullable();
            $t->string('entity_address', 255)->nullable();
            $t->string('legacy_address', 255)->nullable();
            $t->string('status', 20)->default('recorded');
            $t->dateTime('voided_at')->nullable();
            $t->boolean('legacy')->default(false);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['facility_id', 'occurred_on', 'id'], 'bb_events_date');
            $t->index(['person_id', 'occurred_on'], 'bb_events_person');
        });
        DB::statement("ALTER TABLE blood_bank_events ADD CONSTRAINT bb_event_kind CHECK ((kind = 'donation' AND benefit_kind IS NULL AND issue_event_id IS NULL AND blood_transfusion_id IS NULL) OR (kind = 'benefit' AND benefit_kind IN ('issue','transfusion') AND blood_donation_id IS NULL AND (benefit_kind = 'transfusion' OR (issue_event_id IS NULL AND blood_transfusion_id IS NULL)))), ADD CONSTRAINT bb_event_quantity CHECK (quantity > 0 AND quantity_unit IN ('kg','unit'))");
        Schema::create('blood_bank_event_codes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained('blood_bank_events')->restrictOnDelete();
            $t->string('code', 40)->unique();
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('blood_bank_event_screenings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('event_id')->constrained('blood_bank_events')->restrictOnDelete();
            $t->string('analyte', 10);
            $t->string('status', 20);
            $t->foreignId('screening_test_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('result', 20)->nullable();
            $t->date('tested_on')->nullable();
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['event_id', 'analyte'], 'bb_event_screening');
        });
        DB::statement("ALTER TABLE blood_bank_event_screenings ADD CONSTRAINT bb_event_analyte CHECK (analyte IN ('HBsAg','HCV','HIV')), ADD CONSTRAINT bb_event_screen_status CHECK (status IN ('not_requested','requested','pending','complete','cancelled'))");
        Schema::create('blood_bank_identity_reviews', function (Blueprint $t) {
            $t->id();
            $t->string('source', 30);
            $t->unsignedBigInteger('source_id');
            $t->string('reason', 60);
            $t->json('references')->nullable();
            $t->timestamps();
            $t->unique(['source', 'source_id', 'reason'], 'bb_identity_review');
        });
    }

    public function down(): void
    {
        if (DB::table('blood_bank_people')->exists() || DB::table('blood_bank_events')->exists()) {
            throw new RuntimeException('Unified blood-bank data exists. Restore a verified backup; do not discard people or events by rollback.');
        }
        Schema::dropIfExists('blood_bank_identity_reviews');
        Schema::dropIfExists('blood_bank_event_screenings');
        Schema::dropIfExists('blood_bank_event_codes');
        Schema::dropIfExists('blood_bank_events');
        foreach (['blood_donors', 'blood_recipients'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('person_id'));
        }
        foreach (['blood_donations', 'blood_transfusions'] as $table) {
            DB::statement(BloodBankProfileSchema::dropCheck($table, $table.'_quantity_unit'));
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('quantity_unit');
                $t->decimal('units', 18, 4)->default(1)->change();
            });
        }
        Schema::dropIfExists('blood_bank_people');
    }
};
