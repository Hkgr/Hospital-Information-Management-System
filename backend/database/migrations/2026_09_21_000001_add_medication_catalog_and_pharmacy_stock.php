<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function directoryActors(Blueprint $t): void
    {
        $t->foreignId('entered_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $t->unsignedBigInteger('lock_version')->default(1);
        $t->timestamps();
    }

    private function voidColumns(Blueprint $t): void
    {
        $t->dateTime('voided_at')->nullable();
        $t->foreignId('voided_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
        $t->string('void_reason', 255)->nullable();
    }

    private function versionCheck(string $table): void
    {
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_version` CHECK (lock_version >= 1)");
    }

    private function voidCheck(string $table): void
    {
        DB::statement("ALTER TABLE `$table` ADD CONSTRAINT `{$table}_void` CHECK ((voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL) OR (voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND CHAR_LENGTH(TRIM(void_reason)) > 0))");
    }

    public function up(): void
    {
        Schema::table('medications', function (Blueprint $table) {
            $table->text('description')->nullable();
            $table->unsignedBigInteger('lock_version')->default(1);
            $table->timestamp('archived_at')->nullable()->index();
            $table->string('strength', 60)->nullable();
            $table->string('dosage_form', 40)->nullable();
            $table->decimal('reorder_level', 18, 4)->nullable();
        });

        Schema::create('medication_suppliers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->string('code', 50);
            $t->string('name_ar', 200);
            $t->string('contact_person', 120)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('address_line', 255)->nullable();
            $t->text('note')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable()->index();
            $this->directoryActors($t);
            $t->unique(['facility_id', 'code'], 'med_suppliers_facility_code');
            $t->unique(['id', 'facility_id'], 'med_suppliers_scope');
        });
        $this->versionCheck('medication_suppliers');

        Schema::create('medication_stores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->string('code', 50);
            $t->string('name_ar', 200);
            $t->string('location', 200)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamp('archived_at')->nullable()->index();
            $this->directoryActors($t);
            $t->unique(['facility_id', 'code'], 'med_stores_facility_code');
            $t->unique(['id', 'facility_id'], 'med_stores_scope');
        });
        $this->versionCheck('medication_stores');

        Schema::create('medication_batches', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('store_id');
            $t->foreignId('medication_id')->constrained('medications')->restrictOnDelete()->restrictOnUpdate();
            $t->string('batch_number', 60);
            $t->date('expiry_date');
            $t->date('manufactured_on')->nullable();
            $t->date('received_on');
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('medication_source', 30);
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->decimal('received_quantity', 18, 4);
            $t->string('status', 20)->default('active');
            $t->text('note')->nullable();
            $this->directoryActors($t);
            $t->unique(['facility_id', 'store_id', 'medication_id', 'batch_number', 'expiry_date'], 'med_batches_identity');
            $t->unique(['id', 'facility_id'], 'med_batches_scope');
            $t->index(['facility_id', 'expiry_date'], 'med_batches_expiry');
            $t->index(['medication_id', 'status'], 'med_batches_status');
            $t->foreign(['facility_id'], 'med_batches_facility_fk')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['store_id', 'facility_id'], 'med_batches_store_scope')->references(['id', 'facility_id'])->on('medication_stores')->restrictOnDelete()->restrictOnUpdate();
            // A null supplier_id skips this composite check by InnoDB's default behaviour.
            $t->foreign(['supplier_id', 'facility_id'], 'med_batches_supplier_scope')->references(['id', 'facility_id'])->on('medication_suppliers')->restrictOnDelete()->restrictOnUpdate();
        });
        $this->versionCheck('medication_batches');
        DB::statement("ALTER TABLE medication_batches ADD CONSTRAINT med_batches_status_values CHECK (status IN ('active','depleted','expired','quarantined')), ADD CONSTRAINT med_batches_received_qty CHECK (received_quantity > 0), ADD CONSTRAINT med_batches_unit_cost CHECK (unit_cost IS NULL OR unit_cost >= 0), ADD CONSTRAINT med_batches_medication_source CHECK (medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none'))");

        Schema::create('medication_receipts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('store_id');
            $t->string('receipt_no', 50);
            $t->unsignedBigInteger('supplier_id')->nullable();
            $t->string('medication_source', 30);
            $t->date('received_on');
            $t->string('invoice_number', 80)->nullable();
            $t->string('status', 20)->default('draft');
            $t->text('note')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->char('client_request_id', 36);
            $this->directoryActors($t);
            $t->unique(['facility_id', 'receipt_no'], 'med_receipts_no');
            $t->unique(['id', 'facility_id'], 'med_receipts_scope');
            $t->foreign(['facility_id'], 'med_receipts_facility_fk')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['store_id', 'facility_id'], 'med_receipts_store_scope')->references(['id', 'facility_id'])->on('medication_stores')->restrictOnDelete()->restrictOnUpdate();
            // A null supplier_id skips this composite check by InnoDB's default behaviour.
            $t->foreign(['supplier_id', 'facility_id'], 'med_receipts_supplier_scope')->references(['id', 'facility_id'])->on('medication_suppliers')->restrictOnDelete()->restrictOnUpdate();
        });
        $this->versionCheck('medication_receipts');
        DB::statement("ALTER TABLE medication_receipts ADD CONSTRAINT med_receipts_status CHECK (status IN ('draft','confirmed','cancelled')), ADD CONSTRAINT med_receipts_confirmed CHECK ((status = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL) OR (status <> 'confirmed' AND confirmed_at IS NULL AND confirmed_by IS NULL)), ADD CONSTRAINT med_receipts_medication_source CHECK (medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none'))");

        Schema::create('medication_receipt_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('receipt_id');
            $t->unsignedBigInteger('facility_id');
            $t->foreignId('medication_id')->constrained('medications')->restrictOnDelete()->restrictOnUpdate();
            $t->string('batch_number', 60);
            $t->date('expiry_date');
            $t->date('manufactured_on')->nullable();
            $t->decimal('quantity', 18, 4);
            $t->decimal('free_quantity', 18, 4)->default(0);
            $t->decimal('unit_cost', 18, 4)->nullable();
            $t->text('note')->nullable();
            $t->unsignedBigInteger('batch_id')->nullable();
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('updated_by')->nullable()->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $t->timestamps();
            $t->foreign(['receipt_id', 'facility_id'], 'med_receipt_items_receipt_scope')->references(['id', 'facility_id'])->on('medication_receipts')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['batch_id', 'facility_id'], 'med_receipt_items_batch_scope')->references(['id', 'facility_id'])->on('medication_batches')->restrictOnDelete()->restrictOnUpdate();
        });
        DB::statement('ALTER TABLE medication_receipt_items ADD CONSTRAINT med_receipt_items_qty CHECK (quantity > 0 AND free_quantity >= 0), ADD CONSTRAINT med_receipt_items_unit_cost CHECK (unit_cost IS NULL OR unit_cost >= 0)');

        Schema::create('stock_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('facility_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->foreignId('user_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $t->uuid('request_id');
            $t->string('fingerprint', 64);
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['facility_id', 'user_id', 'request_id'], 'stock_requests_once');
        });

        Schema::create('stock_issues', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('store_id');
            $t->foreignId('medication_id')->constrained('medications')->restrictOnDelete()->restrictOnUpdate();
            $t->decimal('quantity', 18, 4);
            $t->decimal('issued_quantity', 18, 4);
            $t->decimal('shortfall_quantity', 18, 4)->default(0);
            $t->date('issued_on');
            $t->string('medication_source', 30);
            $t->unsignedBigInteger('visit_id')->nullable();
            $t->foreignId('patient_id')->nullable()->constrained('patients')->restrictOnDelete()->restrictOnUpdate();
            $t->text('note')->nullable();
            $t->char('client_request_id', 36);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $this->voidColumns($t);
            $t->unsignedBigInteger('lock_version')->default(1);
            $t->timestamps();
            $t->unique(['facility_id', 'client_request_id'], 'stock_issues_request');
            $t->unique(['id', 'facility_id'], 'stock_issues_scope');
            $t->foreign(['facility_id'], 'stock_issues_facility_fk')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['store_id', 'facility_id'], 'stock_issues_store_scope')->references(['id', 'facility_id'])->on('medication_stores')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['visit_id', 'facility_id'], 'stock_issues_visit_scope')->references(['id', 'facility_id'])->on('visits')->restrictOnDelete()->restrictOnUpdate();
        });
        $this->versionCheck('stock_issues');
        $this->voidCheck('stock_issues');
        DB::statement("ALTER TABLE stock_issues ADD CONSTRAINT stock_issues_qty CHECK (quantity > 0 AND issued_quantity >= 0 AND shortfall_quantity >= 0 AND issued_quantity + shortfall_quantity = quantity), ADD CONSTRAINT stock_issues_medication_source CHECK (medication_source IN ('ministry_of_health','al_rowad','other_organization','personal_expense','none'))");

        Schema::create('inventory_transactions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('store_id');
            $t->foreignId('medication_id')->constrained('medications')->restrictOnDelete()->restrictOnUpdate();
            $t->unsignedBigInteger('batch_id');
            $t->string('transaction_type', 20);
            $t->decimal('quantity', 18, 4);
            $t->string('direction', 3);
            $t->date('occurred_on');
            $t->string('reference_type', 40)->nullable();
            $t->unsignedBigInteger('reference_id')->nullable();
            $t->string('reason', 40)->nullable();
            $t->text('note')->nullable();
            $t->char('client_request_id', 36);
            $t->foreignId('entered_by')->constrained('users')->restrictOnDelete()->restrictOnUpdate();
            $this->voidColumns($t);
            $t->timestamps();
            $t->unique(['facility_id', 'client_request_id'], 'inv_txn_request');
            $t->index(['batch_id', 'occurred_on'], 'inv_txn_batch');
            $t->index(['store_id', 'medication_id', 'occurred_on'], 'inv_txn_store_med');
            $t->index(['reference_type', 'reference_id'], 'inv_txn_reference');
            $t->foreign(['facility_id'], 'inv_txn_facility_fk')->references(['id'])->on('facilities')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['store_id', 'facility_id'], 'inv_txn_store_scope')->references(['id', 'facility_id'])->on('medication_stores')->restrictOnDelete()->restrictOnUpdate();
            $t->foreign(['batch_id', 'facility_id'], 'inv_txn_batch_scope')->references(['id', 'facility_id'])->on('medication_batches')->restrictOnDelete()->restrictOnUpdate();
        });
        $this->voidCheck('inventory_transactions');
        DB::statement("ALTER TABLE inventory_transactions ADD CONSTRAINT inv_txn_type CHECK (transaction_type IN ('receipt','dispense','return','adjustment','transfer','expiry_writeoff','damage_writeoff')), ADD CONSTRAINT inv_txn_direction CHECK (direction IN ('in','out')), ADD CONSTRAINT inv_txn_quantity CHECK (quantity > 0), ADD CONSTRAINT inv_txn_reason CHECK ((reason IS NULL OR reason IN ('physical_count','damaged','expired','lost','data_correction','patient_return','discontinued','excess','wrong_medication','other')) AND (transaction_type NOT IN ('adjustment','return','expiry_writeoff','damage_writeoff') OR reason IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('stock_issues');
        Schema::dropIfExists('stock_requests');
        Schema::dropIfExists('medication_receipt_items');
        Schema::dropIfExists('medication_receipts');
        Schema::dropIfExists('medication_batches');
        Schema::dropIfExists('medication_stores');
        Schema::dropIfExists('medication_suppliers');
        Schema::table('medications', fn (Blueprint $table) => $table->dropColumn(['description', 'lock_version', 'archived_at', 'strength', 'dosage_form', 'reorder_level']));
    }
};
