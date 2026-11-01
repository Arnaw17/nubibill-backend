<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Supporting billing & ledger tables (ensures SQLite/MySQL compatibility)
        if (! Schema::hasTable('businesses')) {
            Schema::create('businesses', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('name', 255)->nullable();
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('invoice_number', 100)->nullable()->index();
                $table->string('customer_id', 100)->nullable()->index();
                $table->decimal('total_amount', 12, 2)->default(0.00);
                $table->string('status', 50)->default('PAID')->index();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('invoice_items')) {
            Schema::create('invoice_items', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('invoice_id', 100)->nullable()->index();
                $table->string('product_id', 100)->nullable()->index();
                $table->decimal('quantity', 12, 3)->default(0.000);
                $table->decimal('returned_qty', 12, 3)->default(0.000);
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_bills')) {
            Schema::create('purchase_bills', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('supplier_id', 100)->nullable()->index();
                $table->string('status', 50)->default('APPROVED')->index();
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_bill_items')) {
            Schema::create('purchase_bill_items', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('purchase_bill_id', 100)->nullable()->index();
                $table->string('product_id', 100)->nullable()->index();
                $table->decimal('quantity', 12, 3)->default(0.000);
                $table->decimal('returned_qty', 12, 3)->default(0.000);
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('stock_balances')) {
            Schema::create('stock_balances', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('product_id', 100)->nullable()->index();
                $table->string('variant_id', 100)->nullable()->index();
                $table->decimal('quantity_on_hand', 12, 3)->default(0.000);
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('customer_balances')) {
            Schema::create('customer_balances', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('customer_id', 100)->nullable()->index();
                $table->bigInteger('due_paise')->default(0);
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('supplier_balances')) {
            Schema::create('supplier_balances', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('supplier_id', 100)->nullable()->index();
                $table->decimal('balance', 12, 2)->default(0.00);
                $table->string('status', 50)->default('ACTIVE');
                $table->boolean('is_deleted')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        // 1. Return Reasons Table
        if (! Schema::hasTable('return_reasons')) {
            Schema::create('return_reasons', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('user_id', 100)->nullable()->index();
                $table->string('code', 50)->index();
                $table->string('name', 150);
                $table->boolean('is_system')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // 2. Returns Table (Credit Notes for Sales Returns, Debit Notes for Purchase Returns)
        if (! Schema::hasTable('returns')) {
            Schema::create('returns', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('user_id', 100)->index();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('return_no', 60)->index();
                $table->string('type', 30)->index(); // sale_return | purchase_return
                $table->string('original_bill_id', 100)->nullable()->index();
                $table->string('original_bill_no', 100)->nullable();
                $table->date('original_bill_date')->nullable();
                $table->string('party_id', 100)->index();
                $table->string('party_name', 255)->nullable();
                $table->string('party_phone', 50)->nullable();
                $table->string('party_gstin', 25)->nullable();
                $table->string('party_state_code', 10)->nullable();
                $table->string('business_state_code', 10)->nullable();
                $table->date('return_date')->index();
                $table->string('reason_id', 100)->nullable()->index();
                $table->text('reason_note')->nullable();
                $table->string('refund_mode', 40)->default('adjust_to_ledger'); // cash | upi | bank | adjust_to_ledger | credit_note
                $table->string('idempotency_key', 100)->nullable()->index();
                $table->decimal('subtotal', 12, 2)->default(0.00);
                $table->decimal('cgst_total', 12, 2)->default(0.00);
                $table->decimal('sgst_total', 12, 2)->default(0.00);
                $table->decimal('igst_total', 12, 2)->default(0.00);
                $table->decimal('tax_total', 12, 2)->default(0.00);
                $table->decimal('grand_total', 12, 2)->default(0.00);
                $table->string('status', 30)->default('completed')->index(); // draft | completed | cancelled
                $table->text('cancellation_reason')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->string('created_by', 100)->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Return Items Table
        if (! Schema::hasTable('return_items')) {
            Schema::create('return_items', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('return_id', 100)->index();
                $table->string('product_id', 100)->index();
                $table->string('variant_id', 100)->nullable()->index();
                $table->string('original_bill_item_id', 100)->nullable()->index();
                $table->string('product_name', 255);
                $table->string('hsn_code', 50)->nullable();
                $table->string('unit', 30)->nullable();
                $table->decimal('qty_returned', 12, 3)->default(0.000);
                $table->decimal('unit_price', 12, 2)->default(0.00);
                $table->decimal('discount_amount', 12, 2)->default(0.00);
                $table->decimal('taxable_amount', 12, 2)->default(0.00);
                $table->decimal('tax_rate', 5, 2)->default(0.00);
                $table->decimal('cgst_amount', 12, 2)->default(0.00);
                $table->decimal('sgst_amount', 12, 2)->default(0.00);
                $table->decimal('igst_amount', 12, 2)->default(0.00);
                $table->decimal('tax_amount', 12, 2)->default(0.00);
                $table->decimal('line_total', 12, 2)->default(0.00);
                $table->string('condition', 30)->default('resalable'); // resalable | damaged | expired
                $table->boolean('restock')->default(true);
                $table->string('batch_number', 100)->nullable();
                $table->date('expiry_date')->nullable();
                $table->timestamps();
            });
        }

        // 4. Return Audits Table
        if (! Schema::hasTable('return_audits')) {
            Schema::create('return_audits', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('return_id', 100)->index();
                $table->string('user_id', 100)->nullable()->index();
                $table->string('action', 50); // created | updated | cancelled | pdf_generated
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamps();
            });
        }

        // 5. Stock Movements Table
        if (! Schema::hasTable('stock_movements')) {
            Schema::create('stock_movements', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('product_id', 100)->index();
                $table->string('variant_id', 100)->nullable()->index();
                $table->string('type', 50)->index(); // purchase | sale | sale_return | purchase_return | adjustment | wastage
                $table->decimal('qty_change', 12, 3);
                $table->decimal('balance_after', 12, 3)->nullable();
                $table->string('reference_type', 50)->index();
                $table->string('reference_id', 100)->index();
                $table->string('condition', 30)->nullable();
                $table->text('note')->nullable();
                $table->string('created_by', 100)->nullable();
                $table->timestamps();
            });
        }

        // 6. Ledger Entries Table (Universal party ledger)
        if (! Schema::hasTable('ledger_entries')) {
            Schema::create('ledger_entries', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('party_type', 30)->index(); // customer | supplier
                $table->string('party_id', 100)->index();
                $table->string('type', 20)->index(); // credit | debit
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2)->nullable();
                $table->string('reference_type', 50)->index();
                $table->string('reference_id', 100)->index();
                $table->date('date')->index();
                $table->text('note')->nullable();
                $table->boolean('is_reversal')->default(false);
                $table->string('created_by', 100)->nullable();
                $table->timestamps();
            });
        }

        // 7. Track returned_qty on bill items
        if (Schema::hasTable('invoice_items') && ! Schema::hasColumn('invoice_items', 'returned_qty')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->decimal('returned_qty', 12, 3)->default(0.000);
            });
        }

        if (Schema::hasTable('purchase_bill_items') && ! Schema::hasColumn('purchase_bill_items', 'returned_qty')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                $table->decimal('returned_qty', 12, 3)->default(0.000);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('invoice_items') && Schema::hasColumn('invoice_items', 'returned_qty')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropColumn('returned_qty');
            });
        }

        if (Schema::hasTable('purchase_bill_items') && Schema::hasColumn('purchase_bill_items', 'returned_qty')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                $table->dropColumn('returned_qty');
            });
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('return_audits');
        Schema::dropIfExists('return_items');
        Schema::dropIfExists('returns');
        Schema::dropIfExists('return_reasons');
    }
};
