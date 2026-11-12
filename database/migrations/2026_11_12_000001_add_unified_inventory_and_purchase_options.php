<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'inventory_behaviour')) {
                    $table->string('inventory_behaviour', 32)->default('SEPARATE_VARIANT');
                }
                if (! Schema::hasColumn('products', 'default_purchase_option_id')) {
                    $table->string('default_purchase_option_id', 128)->nullable();
                }
                if (! Schema::hasColumn('products', 'allow_multiple_purchase_options')) {
                    $table->boolean('allow_multiple_purchase_options')->default(true);
                }
                if (! Schema::hasColumn('products', 'allow_multiple_sales_options')) {
                    $table->boolean('allow_multiple_sales_options')->default(false);
                }
                if (! Schema::hasColumn('products', 'track_stock')) {
                    $table->boolean('track_stock')->default(true);
                }
            });
        }

        if (! Schema::hasTable('product_purchase_options')) {
            Schema::create('product_purchase_options', function (Blueprint $table) {
                $table->string('id', 191)->primary();
                $table->string('document_path', 255)->nullable();
                $table->string('business_id', 128)->nullable()->index();
                $table->string('outlet_id', 128)->nullable();
                $table->string('tenant_id', 128)->nullable();
                $table->string('product_id', 128)->nullable()->index();
                $table->string('variant_id', 128)->nullable()->index();
                $table->string('option_name', 191)->nullable();
                $table->string('option_type', 32)->default('PURCHASE');
                $table->decimal('conversion_factor', 14, 4)->default(1);
                $table->string('conversion_unit_id', 128)->nullable();
                $table->bigInteger('purchase_price')->default(0);
                $table->bigInteger('default_selling_price')->nullable();
                $table->decimal('diameter_mm', 8, 2)->nullable();
                $table->decimal('length_metre', 8, 2)->nullable();
                $table->decimal('weight_per_piece_kg', 10, 4)->nullable();
                $table->string('barcode', 128)->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->string('status', 32)->default('ACTIVE');
                $table->tinyInteger('is_deleted')->default(0);
                $table->longText('payload')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('purchase_bill_items')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                if (! Schema::hasColumn('purchase_bill_items', 'purchase_option_id')) {
                    $table->string('purchase_option_id', 128)->nullable();
                }
                if (! Schema::hasColumn('purchase_bill_items', 'purchase_option_name')) {
                    $table->string('purchase_option_name', 191)->nullable();
                }
                if (! Schema::hasColumn('purchase_bill_items', 'effective_base_unit_cost')) {
                    $table->bigInteger('effective_base_unit_cost')->nullable();
                }
            });
        }

        if (Schema::hasTable('stock_balances')) {
            Schema::table('stock_balances', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                    $table->string('inventory_behaviour', 32)->nullable();
                }
            });
        }

        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                if (! Schema::hasColumn('stock_movements', 'purchase_option_id')) {
                    $table->string('purchase_option_id', 128)->nullable();
                }
                if (! Schema::hasColumn('stock_movements', 'purchase_option_name')) {
                    $table->string('purchase_option_name', 191)->nullable();
                }
                if (! Schema::hasColumn('stock_movements', 'effective_base_unit_cost')) {
                    $table->bigInteger('effective_base_unit_cost')->nullable();
                }
                if (! Schema::hasColumn('stock_movements', 'inventory_behaviour')) {
                    $table->string('inventory_behaviour', 32)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_movements')) {
            Schema::table('stock_movements', function (Blueprint $table) {
                foreach (['purchase_option_id', 'purchase_option_name', 'effective_base_unit_cost', 'inventory_behaviour'] as $col) {
                    if (Schema::hasColumn('stock_movements', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('stock_balances')) {
            Schema::table('stock_balances', function (Blueprint $table) {
                if (Schema::hasColumn('stock_balances', 'inventory_behaviour')) {
                    $table->dropColumn('inventory_behaviour');
                }
            });
        }

        if (Schema::hasTable('purchase_bill_items')) {
            Schema::table('purchase_bill_items', function (Blueprint $table) {
                foreach (['purchase_option_id', 'purchase_option_name', 'effective_base_unit_cost'] as $col) {
                    if (Schema::hasColumn('purchase_bill_items', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('product_purchase_options');

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                foreach ([
                    'inventory_behaviour',
                    'default_purchase_option_id',
                    'allow_multiple_purchase_options',
                    'allow_multiple_sales_options',
                    'track_stock',
                ] as $col) {
                    if (Schema::hasColumn('products', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
