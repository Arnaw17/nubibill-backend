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
        $tables = [
            'supplier_balances',
            'customer_balances',
            'stock_balances',
            'purchase_bills',
            'purchase_bill_items',
            'invoices',
            'invoice_items',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    if (!Schema::hasColumn($table, 'document_path')) {
                        $t->string('document_path', 500)->nullable()->after('id');
                    }
                    if (!Schema::hasColumn($table, 'outlet_id')) {
                        $t->string('outlet_id')->nullable()->after('business_id');
                    }
                    if (!Schema::hasColumn($table, 'tenant_id')) {
                        $t->string('tenant_id')->nullable()->after('outlet_id');
                    }
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Safe no-op or drop columns
    }
};
