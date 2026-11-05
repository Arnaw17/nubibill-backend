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
        // 1. migration_batches
        if (! Schema::hasTable('migration_batches')) {
            Schema::create('migration_batches', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('source_software', 100)->default('Manual');
                $table->date('cutoff_date')->nullable();
                $table->date('date_from')->nullable();
                $table->date('date_to')->nullable();
                $table->string('status', 50)->default('DRAFT')->index(); // DRAFT, APPROVED, RECONCILED, ROLLED_BACK
                $table->integer('total_records')->default(0);
                $table->string('created_by', 100)->nullable();
                $table->string('approved_by', 100)->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        // 2. migration_files
        if (! Schema::hasTable('migration_files')) {
            Schema::create('migration_files', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('batch_id', 100)->index();
                $table->string('file_name', 255);
                $table->string('file_type', 50)->default('csv');
                $table->string('storage_path', 500);
                $table->string('checksum', 100)->nullable();
                $table->timestamps();
            });
        }

        // 3. migration_logs
        if (! Schema::hasTable('migration_logs')) {
            Schema::create('migration_logs', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('batch_id', 100)->nullable()->index();
                $table->string('user_id', 100)->nullable();
                $table->string('action', 100);
                $table->text('description')->nullable();
                $table->timestamps();
            });
        }

        // 4. migration_errors
        if (! Schema::hasTable('migration_errors')) {
            Schema::create('migration_errors', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('batch_id', 100)->index();
                $table->string('record_type', 50);
                $table->integer('source_row')->nullable();
                $table->string('error_code', 50);
                $table->text('error_message');
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        // 5. historical_stock_adjustments
        if (! Schema::hasTable('historical_stock_adjustments')) {
            Schema::create('historical_stock_adjustments', function (Blueprint $table) {
                $table->string('id', 100)->primary();
                $table->string('business_id', 100)->index();
                $table->string('outlet_id', 100)->nullable()->index();
                $table->string('batch_id', 100)->nullable()->index();
                $table->string('purchase_bill_id', 100)->nullable()->index();
                $table->string('product_id', 100)->index();
                $table->string('variant_id', 100)->nullable()->index();
                $table->decimal('original_purchased_qty', 12, 3)->default(0);
                $table->decimal('consumed_qty', 12, 3)->default(0);
                $table->decimal('remaining_physical_stock', 12, 3)->default(0);
                $table->decimal('included_in_opening_stock_qty', 12, 3)->default(0);
                $table->decimal('actual_stock_adjustment_qty', 12, 3)->default(0);
                $table->string('stock_movement_id', 100)->nullable()->index();
                $table->string('status', 50)->default('POSTED')->index();
                $table->json('payload')->nullable();
                $table->timestamps();
            });
        }

        // 6. Extend core transaction tables with historical metadata columns
        $this->addHistoricalColumns('invoices');
        $this->addHistoricalColumns('purchase_bills');
        $this->addHistoricalColumns('stock_movements');
        $this->addHistoricalColumns('customer_ledger_entries');
        $this->addHistoricalColumns('supplier_ledger_entries');
        $this->addHistoricalColumns('payments');
    }

    private function addHistoricalColumns(string $tableName): void
    {
        if (Schema::hasTable($tableName)) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'entry_source')) {
                    $table->string('entry_source', 50)->default('new')->index();
                }
                if (! Schema::hasColumn($tableName, 'is_historical')) {
                    $table->tinyInteger('is_historical')->default(0)->index();
                }
                if (! Schema::hasColumn($tableName, 'migration_batch_id')) {
                    $table->string('migration_batch_id', 100)->nullable()->index();
                }
                if (in_array($tableName, ['invoices', 'purchase_bills'], true)) {
                    if (! Schema::hasColumn($tableName, 'original_invoice_number')) {
                        $table->string('original_invoice_number', 100)->nullable()->index();
                    }
                    if (! Schema::hasColumn($tableName, 'original_invoice_date')) {
                        $table->date('original_invoice_date')->nullable()->index();
                    }
                    if (! Schema::hasColumn($tableName, 'entered_at')) {
                        $table->timestamp('entered_at')->nullable();
                    }
                }
                if (in_array($tableName, ['stock_movements', 'customer_ledger_entries', 'supplier_ledger_entries', 'payments'], true)) {
                    if (! Schema::hasColumn($tableName, 'original_transaction_date')) {
                        $table->date('original_transaction_date')->nullable()->index();
                    }
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('historical_stock_adjustments');
        Schema::dropIfExists('migration_errors');
        Schema::dropIfExists('migration_logs');
        Schema::dropIfExists('migration_files');
        Schema::dropIfExists('migration_batches');

        $tables = ['invoices', 'purchase_bills', 'stock_movements', 'customer_ledger_entries', 'supplier_ledger_entries', 'payments'];
        foreach ($tables as $t) {
            if (Schema::hasTable($t)) {
                Schema::table($t, function (Blueprint $table) use ($t) {
                    $cols = ['entry_source', 'is_historical', 'migration_batch_id'];
                    if (in_array($t, ['invoices', 'purchase_bills'], true)) {
                        $cols = array_merge($cols, ['original_invoice_number', 'original_invoice_date', 'entered_at']);
                    }
                    if (in_array($t, ['stock_movements', 'customer_ledger_entries', 'supplier_ledger_entries', 'payments'], true)) {
                        $cols = array_merge($cols, ['original_transaction_date']);
                    }
                    foreach ($cols as $c) {
                        if (Schema::hasColumn($t, $c)) {
                            $table->dropColumn($c);
                        }
                    }
                });
            }
        }
    }
};
